<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $event = '';
    public string $userId = '';
    public string $from = '';
    public string $to = '';

    public ?int $viewingLogId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'event', 'userId', 'from', 'to']);
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->viewingLogId = $id;
    }

    public function closeDetails(): void
    {
        $this->viewingLogId = null;
    }

    public function render(): mixed
    {
        $query = AuditLog::with('user')
            ->when($this->search !== '', function ($query) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('event', 'like', $term)
                        ->orWhere('auditable_type', 'like', $term)
                        ->orWhere('auditable_id', 'like', $term)
                        ->orWhere('context', 'like', $term);
                });
            })
            ->when($this->event !== '', fn ($query) => $query->where('event', $this->event))
            ->when($this->userId !== '', fn ($query) => $query->where('user_id', $this->userId))
            ->when($this->from !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->latest('id');

        return view('livewire.pages.audit.index', [
            'logs' => $query->paginate(25),
            'users' => User::orderBy('name')->get(),
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
            'viewingLog' => $this->viewingLogId ? AuditLog::with('user')->find($this->viewingLogId) : null,
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
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Compliance</span>
            </div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">System Audit Trail</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('user-access.index') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                User Access
            </a>
            <a href="{{ route('backup.index') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                Backup Management
            </a>
        </div>
    </div>

    <!-- Main Workspace with Left Side Filter Panel & Audit Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3.5">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Audit Filters</span>
                @if ($search !== '' || $event !== '' || $userId !== '' || $from !== '' || $to !== '')
                    <button 
                        wire:click="resetFilters" 
                        type="button" 
                        class="text-[11px] font-semibold text-slate-500 hover:text-slate-900"
                    >
                        Reset
                    </button>
                @endif
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Context / ID</label>
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search event, id, payload..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>

            <!-- Event Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Action Event</label>
                <select wire:model.live="event" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Action Events</option>
                    @foreach ($events as $eventName)
                        <option value="{{ $eventName }}">{{ strtoupper(str_replace('_', ' ', $eventName)) }}</option>
                    @endforeach
                </select>
            </div>

            <!-- User Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Responsible User</label>
                <select wire:model.live="userId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Responsible Users</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ ucfirst($user->role) }})</option>
                    @endforeach
                </select>
            </div>

            <!-- From Date -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Date From</label>
                <input wire:model.live="from" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- To Date -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Date To</label>
                <input wire:model.live="to" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
        </aside>

        <!-- Audit Logs Grid Table -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Date</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20">Time</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36">User</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Role</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-44">Action Event</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-32">Entity / Model</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Record ID</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Key Context</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($logs as $log)
                        @php
                            $event = $log->event;
                            $eventClass = 'border-slate-600 text-slate-700';
                            if (str_contains($event, 'create') || str_contains($event, 'sale') || str_contains($event, 'confirmed')) {
                                $eventClass = 'border-emerald-600 text-emerald-700';
                            } elseif (str_contains($event, 'update') || str_contains($event, 'reset')) {
                                $eventClass = 'border-blue-600 text-blue-700';
                            } elseif (str_contains($event, 'deactivate') || str_contains($event, 'delete') || str_contains($event, 'purge')) {
                                $eventClass = 'border-rose-600 text-rose-700';
                            } elseif (str_contains($event, 'restore') || str_contains($event, 'backup')) {
                                $eventClass = 'border-amber-600 text-amber-700';
                            }

                            $contextArray = is_array($log->context) ? $log->context : (json_decode($log->context, true) ?: []);
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors" wire:key="audit-log-{{ $log->id }}">
                            <!-- Separate Date Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $log->created_at->format('M d, Y') }}
                            </td>
                            <!-- Separate Time Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $log->created_at->format('h:i:s A') }}
                            </td>
                            <!-- User Name -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 whitespace-nowrap">
                                {{ $log->user?->name ?? 'System' }}
                            </td>
                            <!-- Separate User Role Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 capitalize whitespace-nowrap">
                                {{ $log->user?->role ?? 'Internal' }}
                            </td>
                            <!-- Action Event Minimal Badge -->
                            <td class="border border-slate-200 px-2.5 py-1.5 whitespace-nowrap">
                                <span class="inline-block rounded border {{ $eventClass }} bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                    {{ str_replace('_', ' ', $log->event) }}
                                </span>
                            </td>
                            <!-- Entity / Model -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 font-medium whitespace-nowrap">
                                {{ class_basename($log->auditable_type ?? '—') }}
                            </td>
                            <!-- Record ID -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $log->auditable_id ? '#' . $log->auditable_id : '—' }}
                            </td>
                            <!-- Key Context -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">
                                <div class="flex flex-wrap gap-1 max-w-md">
                                    @php $shown = 0; @endphp
                                    @foreach ($contextArray as $k => $v)
                                        @if ($shown < 3 && !is_array($v))
                                            <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-1.5 py-0.2 text-[10px]">
                                                <span class="font-semibold text-slate-500 mr-1">{{ $k }}:</span>
                                                <span class="font-mono text-slate-800">{{ Str::limit((string)$v, 20) }}</span>
                                            </span>
                                            @php $shown++; @endphp
                                        @endif
                                    @endforeach
                                    @if (count($contextArray) > $shown)
                                        <span class="text-[10px] text-slate-400 self-center">+{{ count($contextArray) - $shown }} more</span>
                                    @endif
                                </div>
                            </td>
                            <!-- Actions / View -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap">
                                <button 
                                    wire:click="viewDetails({{ $log->id }})" 
                                    type="button" 
                                    class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                                >
                                    Inspect
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                <div class="mx-auto flex flex-col items-center justify-center">
                                    <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p class="text-xs font-semibold text-slate-700">No audit log records match your filter criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
    </div>

    <!-- Audit Log Deep Detail Inspection Modal -->
    @if ($viewingLogId && $viewingLog)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            Audit Record #{{ $viewingLog->id }}
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            Recorded on <span class="tabular-nums font-semibold text-slate-800">{{ $viewingLog->created_at->format('F d, Y · h:i:s A') }}</span>
                        </p>
                    </div>
                    <button 
                        wire:click="closeDetails" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-4 space-y-3.5">
                    <div class="grid grid-cols-2 gap-2.5 text-xs">
                        <div class="rounded border border-slate-200 bg-slate-50 p-2.5">
                            <span class="text-slate-500 block text-[10px] font-bold uppercase tracking-wider">Responsible User</span>
                            <span class="font-bold text-slate-900 mt-0.5 block">{{ $viewingLog->user?->name ?? 'System' }}</span>
                            <span class="text-slate-500 text-[11px] capitalize">{{ $viewingLog->user?->role ?? 'Internal Process' }}</span>
                        </div>
                        <div class="rounded border border-slate-200 bg-slate-50 p-2.5">
                            <span class="text-slate-500 block text-[10px] font-bold uppercase tracking-wider">Action Event</span>
                            <span class="font-bold text-slate-900 mt-0.5 block uppercase">{{ str_replace('_', ' ', $viewingLog->event) }}</span>
                        </div>
                        <div class="rounded border border-slate-200 bg-slate-50 p-2.5">
                            <span class="text-slate-500 block text-[10px] font-bold uppercase tracking-wider">Target Entity</span>
                            <span class="font-bold text-slate-900 mt-0.5 block">{{ class_basename($viewingLog->auditable_type ?? 'None') }}</span>
                            <span class="text-slate-400 font-mono text-[10px]">{{ $viewingLog->auditable_type ?? '—' }}</span>
                        </div>
                        <div class="rounded border border-slate-200 bg-slate-50 p-2.5">
                            <span class="text-slate-500 block text-[10px] font-bold uppercase tracking-wider">Record Identifier</span>
                            <span class="font-bold text-slate-900 mt-0.5 block font-mono">
                                {{ $viewingLog->auditable_id ? '#' . $viewingLog->auditable_id : '—' }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <span class="text-slate-700 text-xs font-bold uppercase tracking-wider block mb-1">
                            Payload Context Data:
                        </span>
                        <div class="rounded border border-slate-300 bg-slate-900 p-3 font-mono text-xs text-emerald-400 overflow-x-auto max-h-56">
                            <pre>{{ json_encode(is_array($viewingLog->context) ? $viewingLog->context : (json_decode($viewingLog->context, true) ?: []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 flex justify-end">
                    <button 
                        wire:click="closeDetails" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
