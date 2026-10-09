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
    public string $backupFrequency = 'daily';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
        $this->backupFrequency = app(\App\Services\AutoBackupService::class)->getFrequency();
    }

    public function updateFrequency(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
        app(\App\Services\AutoBackupService::class)->setFrequency($this->backupFrequency);
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Auto-backup frequency updated successfully.',
        ]);
    }

    public function runAutoBackupCheck(): void
    {
        abort_unless(auth()->user()?->canAccessAdministration(), 403);
        $service = app(\App\Services\AutoBackupService::class);
        if (! $service->isOverdue()) {
            $next = $service->getNextDueTime();
            $this->dispatch('toast', [
                'type' => 'info',
                'message' => 'Backup is currently up to date. Next due: ' . ($next ? $next->format('M d, Y h:i A') : 'N/A'),
            ]);
            return;
        }

        $this->isProcessing = true;
        try {
            $ran = $service->checkAndRunIfOverdue('manual_check');
            if ($ran) {
                $this->dispatch('toast', [
                    'type' => 'success',
                    'message' => 'Overdue automated database backup created successfully.',
                ]);
            } else {
                $this->dispatch('toast', [
                    'type' => 'info',
                    'message' => 'Auto-backup check completed.',
                ]);
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Auto-backup failed: ' . $e->getMessage(),
            ]);
        } finally {
            $this->isProcessing = false;
        }
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

        $autoBackupService = app(\App\Services\AutoBackupService::class);

        return view('livewire.pages.admin.backup', [
            'backups' => $files,
            'lastBackupTime' => $autoBackupService->getLastBackupTime(),
            'nextDueTime' => $autoBackupService->getNextDueTime(),
            'isOverdue' => $autoBackupService->isOverdue(),
            'frequencies' => \App\Services\AutoBackupService::getFrequencies(),
        ]);
    }
}; ?>

<div 
    x-data="{
        contextMenu: {
            open: false,
            x: 0,
            y: 0,
            item: null,
            openAt(x, y, item) {
                this.item = item;
                this.x = x;
                this.y = y;
                this.open = true;
                this.$nextTick(() => {
                    const el = this.$refs.floatingMenu;
                    if (!el) return;
                    const r = el.getBoundingClientRect();
                    if (this.x + r.width > window.innerWidth - 8) {
                        this.x = Math.max(8, window.innerWidth - r.width - 8);
                    }
                    if (this.y + r.height > window.innerHeight - 8) {
                        this.y = Math.max(8, window.innerHeight - r.height - 8);
                    }
                });
            },
            openFromButton(event, item) {
                const btn = event.currentTarget.getBoundingClientRect();
                this.openAt(btn.right - 160, btn.bottom + 4, item);
            },
            openFromEvent(event, item) {
                this.openAt(event.clientX, event.clientY, item);
            },
            close() {
                this.open = false;
                this.item = null;
            }
        }
    }"
    @click.window="contextMenu.close()"
    @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()"
    @resize.window="contextMenu.close()"
    class="space-y-4 w-full min-w-0"
>
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Backups</h1>
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

            <!-- Auto-Backup Configuration -->
            <div class="border-t border-slate-200 pt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Auto-Backup</span>
                    @if ($backupFrequency !== 'disabled')
                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $isOverdue ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $isOverdue ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500' }}"></span>
                            {{ $isOverdue ? 'Overdue' : 'Active' }}
                        </span>
                    @else
                        <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">Off</span>
                    @endif
                </div>

                <div>
                    <label for="backupFrequencySelect" class="text-[10px] font-semibold uppercase tracking-wider text-slate-500 block mb-1">Frequency</label>
                    <select 
                        id="backupFrequencySelect"
                        wire:model.live="backupFrequency" 
                        wire:change="updateFrequency"
                        class="w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-xs text-slate-800 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                    >
                        @foreach ($frequencies as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($backupFrequency !== 'disabled')
                    <div class="rounded border {{ $isOverdue ? 'border-amber-300 bg-amber-50/70' : 'border-slate-200 bg-slate-50' }} p-2 text-xs space-y-1">
                        <div class="flex justify-between items-center text-slate-600">
                            <span class="text-[10px] uppercase font-bold text-slate-500">Next Due</span>
                            <span class="font-mono text-[11px] {{ $isOverdue ? 'text-amber-900 font-bold' : 'text-slate-800 font-medium' }}">
                                {{ $nextDueTime ? $nextDueTime->format('M d · h:i A') : 'Immediately' }}
                            </span>
                        </div>
                        @if ($isOverdue)
                            <div class="pt-1 border-t border-amber-200/80 flex items-center justify-between">
                                <span class="text-[10px] text-amber-800 font-medium">Backup overdue</span>
                                <button 
                                    wire:click="runAutoBackupCheck"
                                    wire:loading.attr="disabled"
                                    type="button" 
                                    class="text-[10px] font-bold text-[#008fb3] hover:underline"
                                >
                                    Run Check Now
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
                
                <p class="text-[10px] text-slate-400 leading-tight">
                    Automatically creates database backups if overdue during login, scheduled runs, and system startup.
                </p>
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
                        <th class="border border-slate-300 px-3 py-1.5 text-left">Backup Filename</th>
                        <th class="border border-slate-300 px-4 py-1.5 text-center w-px whitespace-nowrap">Date Created</th>
                        <th class="border border-slate-300 px-4 py-1.5 text-center w-px whitespace-nowrap">Time</th>
                        <th class="border border-slate-300 px-4 py-1.5 text-right w-px whitespace-nowrap">Archive Size</th>
                        <th class="border border-slate-300 px-2 py-1.5 text-center w-px whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($backups as $backup)
                        @php
                            $dt = \Carbon\Carbon::parse($backup['modified_at']);
                        @endphp
                        <tr 
                            class="hover:bg-slate-50 transition-colors"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { name: '{{ addslashes($backup['name']) }}' })"
                        >
                            <td class="border border-slate-200 px-3 py-1.5 font-mono text-slate-900 font-medium">
                                <div class="flex items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <span>{{ $backup['name'] }}</span>
                                </div>
                            </td>
                            <!-- Separate Date Column -->
                            <td class="border border-slate-200 px-4 py-1.5 text-center tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $dt->format('M d, Y') }}
                            </td>
                            <!-- Separate Time Column -->
                            <td class="border border-slate-200 px-4 py-1.5 text-center tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $dt->format('h:i A') }}
                            </td>
                            <!-- Archive Size -->
                            <td class="border border-slate-200 px-4 py-1.5 text-right tabular-nums font-semibold text-slate-800 whitespace-nowrap">
                                {{ $backup['size'] }}
                            </td>
                            <!-- Context Menu Actions -->
                            <td class="border border-slate-200 px-2 py-1.5 text-center w-px whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { name: '{{ addslashes($backup['name']) }}' })" 
                                    type="button" 
                                    title="More actions"
                                    class="inline-flex justify-center items-center rounded p-1 hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
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

    <!-- Global Floating Context Menu (Unconstrained by table) -->
    <div 
        x-ref="floatingMenu"
        x-show="contextMenu.open" 
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :style="`position: fixed; left: ${contextMenu.x}px; top: ${contextMenu.y}px; z-index: 9999;`"
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.confirmRestore(contextMenu.item.name); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span>Restore Snapshot</span>
        </button>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { $wire.confirmDelete(contextMenu.item.name); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-rose-700 hover:bg-rose-50 transition-colors"
        >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span>Delete Snapshot</span>
        </button>
    </div>
</div>
