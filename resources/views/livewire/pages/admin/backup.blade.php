<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Layout('layouts.app')] class extends Component {
    public ?string $restoringFile = null;
    public ?string $deletingFile = null;
    public bool $isProcessing = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
    }

    public function createBackup(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);

        $this->isProcessing = true;
        try {
            $exitCode = Artisan::call('backup:run', ['--only-db' => true]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'database_backup_created',
                'auditable_type' => 'Database',
                'auditable_id' => null,
                'context' => [
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ],
            ]);

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => 'Database backup created successfully.',
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Failed to create backup: ' . $e->getMessage(),
            ]);
        } finally {
            $this->isProcessing = false;
        }
    }

    public function confirmRestore(string $filename): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
        $this->restoringFile = $filename;
    }

    public function cancelRestore(): void
    {
        $this->restoringFile = null;
    }

    public function restoreBackup(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);

        if (!$this->restoringFile) {
            return;
        }

        $filePath = $this->getBackupFilePath($this->restoringFile);
        if (!file_exists($filePath)) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Backup file not found.']);
            $this->restoringFile = null;
            return;
        }

        $this->isProcessing = true;
        try {
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                throw new \RuntimeException('Failed to open backup zip file.');
            }

            $extractPath = storage_path('app/backup-restore-temp');
            File::ensureDirectoryExists($extractPath);
            $zip->extractTo($extractPath);
            $zip->close();

            $sqlFiles = File::glob($extractPath . '/**/*.sql');
            if (empty($sqlFiles)) {
                $sqlFiles = File::glob($extractPath . '/*.sql');
            }

            if (empty($sqlFiles)) {
                throw new \RuntimeException('No SQL dump file found inside the backup archive.');
            }

            $sqlFile = $sqlFiles[0];

            $connection = config('database.default');
            $host = config("database.connections.{$connection}.host", '127.0.0.1');
            $port = config("database.connections.{$connection}.port", '3306');
            $database = config("database.connections.{$connection}.database");
            $username = config("database.connections.{$connection}.username", 'root');
            $password = config("database.connections.{$connection}.password", '');

            $mysqlPath = is_executable('/usr/bin/mysql') ? '/usr/bin/mysql' : 'mysql';
            $passwordArg = $password !== '' ? "-p" . escapeshellarg($password) : '';
            $command = sprintf(
                '%s -h %s -P %s -u %s %s %s < %s',
                escapeshellcmd($mysqlPath),
                escapeshellarg($host),
                escapeshellarg((string) $port),
                escapeshellarg($username),
                $passwordArg,
                escapeshellarg($database),
                escapeshellarg($sqlFile)
            );

            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            File::deleteDirectory($extractPath);

            if ($returnVar !== 0) {
                throw new \RuntimeException('MySQL restore failed with code ' . $returnVar . ': ' . implode("\n", $output));
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'database_backup_restored',
                'auditable_type' => 'Database',
                'auditable_id' => null,
                'context' => [
                    'file' => $this->restoringFile,
                ],
            ]);

            $this->restoringFile = null;
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => 'Database restored successfully from backup.',
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Restore failed: ' . $e->getMessage(),
            ]);
        } finally {
            $this->isProcessing = false;
        }
    }

    public function confirmDelete(string $filename): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
        $this->deletingFile = $filename;
    }

    public function cancelDelete(): void
    {
        $this->deletingFile = null;
    }

    public function deleteBackup(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);

        if (!$this->deletingFile) {
            return;
        }

        $filePath = $this->getBackupFilePath($this->deletingFile);
        if (file_exists($filePath)) {
            File::delete($filePath);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'database_backup_deleted',
                'auditable_type' => 'Database',
                'auditable_id' => null,
                'context' => ['file' => $this->deletingFile],
            ]);

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => 'Backup deleted successfully.',
            ]);
        }

        $this->deletingFile = null;
    }

    public function downloadBackup(string $filename): BinaryFileResponse
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);

        $filePath = $this->getBackupFilePath($filename);
        abort_unless(file_exists($filePath), 404);

        return response()->download($filePath);
    }

    protected function getBackupDir(): string
    {
        $appDir = storage_path('app/private/' . config('backup.backup.name', env('APP_NAME', 'GradeAPaintCenterSystem')));
        if (File::isDirectory($appDir)) {
            return $appDir;
        }

        $altDir = storage_path('app/' . config('backup.backup.name', env('APP_NAME', 'GradeAPaintCenterSystem')));
        if (File::isDirectory($altDir)) {
            return $altDir;
        }

        File::ensureDirectoryExists($appDir);
        return $appDir;
    }

    protected function getBackupFilePath(string $filename): string
    {
        $filename = basename($filename);
        $primary = storage_path('app/private/' . config('backup.backup.name', env('APP_NAME', 'GradeAPaintCenterSystem')) . '/' . $filename);
        if (file_exists($primary)) {
            return $primary;
        }

        return storage_path('app/' . config('backup.backup.name', env('APP_NAME', 'GradeAPaintCenterSystem')) . '/' . $filename);
    }

    public function render(): mixed
    {
        $dir = $this->getBackupDir();
        $files = [];

        if (File::isDirectory($dir)) {
            $rawFiles = File::files($dir);
            foreach ($rawFiles as $file) {
                if ($file->getExtension() === 'zip') {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => number_format($file->getSize() / 1024, 2) . ' KB',
                        'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
                        'timestamp' => $file->getMTime(),
                    ];
                }
            }

            usort($files, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        }

        return view('livewire.pages.admin.backup', [
            'backups' => $files,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Administration</span>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Maintenance</span>
            </div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Database Backup &amp; Restore</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('audit.index') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                Audit Trail
            </a>
            <a href="{{ route('user-access.index') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                User Access
            </a>
        </div>
    </div>

    <!-- Main Workspace with Left Side Action Panel & Backups Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Action & Info Panel -->
        <aside class="w-full lg:w-60 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3.5">
            <div class="border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Backup Actions</span>
            </div>

            <!-- Create Backup Button -->
            <div>
                <button 
                    wire:click="createBackup" 
                    wire:confirm="Create a new full database backup snapshot now?"
                    wire:loading.attr="disabled" 
                    type="button"
                    class="w-full inline-flex items-center justify-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-2 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createBackup">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </span>
                    <span wire:loading wire:target="createBackup">
                        <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </span>
                    <span wire:loading.remove wire:target="createBackup">New Backup</span>
                    <span wire:loading wire:target="createBackup">Dumping DB...</span>
                </button>
            </div>

            <!-- Backup Summary Cards -->
            <div class="border-t border-slate-200 pt-3 space-y-3">
                <div class="rounded border border-slate-200 bg-slate-50 p-2.5">
                    <p class="text-[10px] font-normal uppercase tracking-wider text-slate-500">Available Snapshots</p>
                    <div class="mt-1 text-right">
                        <span class="tabular-nums text-2xl font-light text-slate-900">{{ count($backups) }}</span>
                    </div>
                </div>

                @if (!empty($backups))
                    <div class="rounded border border-slate-200 bg-slate-50 p-2.5 text-xs text-slate-600">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Latest Backup</span>
                        <span class="font-mono text-[11px] text-slate-800 font-medium block mt-0.5">
                            {{ \Carbon\Carbon::parse($backups[0]['modified_at'])->format('M d, Y · h:i A') }}
                        </span>
                    </div>
                @endif
            </div>

            <!-- Safety Notice -->
            <div class="border-t border-slate-200 pt-3 text-[11px] text-amber-800 rounded bg-amber-50/70 p-2.5 border border-amber-200">
                <div class="flex items-center gap-1 font-bold text-amber-900 mb-1">
                    <svg class="h-3.5 w-3.5 text-amber-700 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span>Restore Notice</span>
                </div>
                Restoring overwrites current database records with snapshot contents.
            </div>
        </aside>

        <!-- Backups Grid Table -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                    <tr>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left">Backup Filename</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Date Created</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Time</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Archive Size</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-56">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($backups as $backup)
                        @php
                            $dt = \Carbon\Carbon::parse($backup['modified_at']);
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono text-slate-900 font-medium">
                                <div class="flex items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <span>{{ $backup['name'] }}</span>
                                </div>
                            </td>
                            <!-- Separate Date Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $dt->format('M d, Y') }}
                            </td>
                            <!-- Separate Time Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $dt->format('h:i A') }}
                            </td>
                            <!-- Archive Size -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-semibold text-slate-800 whitespace-nowrap">
                                {{ $backup['size'] }}
                            </td>
                            <!-- Real Action Buttons -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap space-x-1">
                                <button wire:click="downloadBackup('{{ $backup['name'] }}')" type="button"
                                    class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                                    <svg class="h-3 w-3 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    <span>Download</span>
                                </button>
                                <button wire:click="confirmRestore('{{ $backup['name'] }}')" type="button"
                                    class="inline-flex items-center gap-1 rounded border border-amber-300 bg-white px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-50 shadow-xs transition">
                                    <svg class="h-3 w-3 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    <span>Restore</span>
                                </button>
                                <button wire:click="confirmDelete('{{ $backup['name'] }}')" type="button"
                                    class="inline-flex items-center gap-1 rounded border border-rose-300 bg-white px-2 py-0.5 text-xs font-medium text-rose-700 hover:bg-rose-50 shadow-xs transition">
                                    <svg class="h-3 w-3 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                <div class="flex flex-col items-center justify-center gap-1">
                                    <svg class="h-8 w-8 text-slate-300 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                    <p class="font-medium text-slate-700">No database backups available yet.</p>
                                    <p class="text-[11px] text-slate-400">Click "New Backup" to generate your first snapshot.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    @if ($restoringFile)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl border border-slate-300 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded border border-amber-300 bg-amber-50 text-amber-700">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">Confirm Database Restore</h3>
                    </div>
                </div>

                <div class="space-y-2 text-xs text-slate-600">
                    <p>Are you sure you want to restore the database from this backup?</p>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2 font-mono text-[11px] text-slate-800 break-all">
                        {{ $restoringFile }}
                    </div>
                    <p class="font-semibold text-rose-700">
                        WARNING: All changes, sales, and inventory records created after this backup will be permanently replaced!
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-1 border-t border-slate-200">
                    <button wire:click="cancelRestore" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                        Cancel
                    </button>
                    <button wire:click="restoreBackup" wire:loading.attr="disabled" type="button" class="rounded bg-amber-600 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-amber-700 disabled:opacity-50 transition">
                        <span wire:loading.remove wire:target="restoreBackup">Yes, Restore Database</span>
                        <span wire:loading wire:target="restoreBackup">Restoring...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($deletingFile)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl border border-slate-300 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded border border-rose-300 bg-rose-50 text-rose-700">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">Confirm Backup Deletion</h3>
                    </div>
                </div>

                <div class="space-y-2 text-xs text-slate-600">
                    <p>Are you sure you want to permanently delete this backup file?</p>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2 font-mono text-[11px] text-slate-800 break-all">
                        {{ $deletingFile }}
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-1 border-t border-slate-200">
                    <button wire:click="cancelDelete" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                        Cancel
                    </button>
                    <button wire:click="deleteBackup" type="button" class="rounded bg-rose-700 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-rose-800 transition">
                        Delete File
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
