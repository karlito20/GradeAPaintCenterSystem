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
                this.openAt(btn.right - 176, btn.bottom + 4, item);
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
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Audit Trail</h1>
        </div>
    </div>

    <!-- Audit Logs Grid Table Card with Integrated Filter Bar -->
    <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <!-- Integrated Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <div class="flex-1 min-w-[200px]">
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search event, id, payload..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>
            <div class="w-44">
                <select wire:model.live="event" class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Action Events</option>
                    @foreach ($events as $eventName)
                        <option value="{{ $eventName }}">{{ strtoupper(str_replace('_', ' ', $eventName)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-44">
                <select wire:model.live="userId" class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Responsible Users</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ ucfirst($user->role) }})</option>
                    @endforeach
                </select>
            </div>
            <div class="w-36">
                <input wire:model.live="from" type="date" title="Date From" class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
            <div class="w-36">
                <input wire:model.live="to" type="date" title="Date To" class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
            @if ($search !== '' || $event !== '' || $userId !== '' || $from !== '' || $to !== '')
                <button 
                    wire:click="resetFilters" 
                    type="button" 
                    class="text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium"
                >
                    Reset
                </button>
            @endif
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24 whitespace-nowrap">Date</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24 whitespace-nowrap">Time</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36 whitespace-nowrap">User</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24 whitespace-nowrap">Role</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-44 whitespace-nowrap">Action Event</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Details</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($logs as $log)
                        @php
                            $event = $log->event;
                            $eventClass = 'text-slate-700';
                            if (str_contains($event, 'create') || str_contains($event, 'sale') || str_contains($event, 'confirmed')) {
                                $eventClass = 'text-emerald-700';
                            } elseif (str_contains($event, 'update') || str_contains($event, 'reset')) {
                                $eventClass = 'text-blue-700';
                            } elseif (str_contains($event, 'deactivate') || str_contains($event, 'delete') || str_contains($event, 'purge')) {
                                $eventClass = 'text-rose-700';
                            } elseif (str_contains($event, 'restore') || str_contains($event, 'backup')) {
                                $eventClass = 'text-amber-700';
                            }
                        @endphp
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="audit-log-{{ $log->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $log->id }}, event: '{{ $log->event }}' })"
                        >
                            <!-- Separate Date Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $log->created_at->format('M d, Y') }}
                            </td>
                            <!-- Separate Time Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $log->created_at->format('h:i:s A') }}
                            </td>
                            <!-- User Name -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 whitespace-nowrap">
                                {{ $log->user?->name ?? 'System' }}
                            </td>
                            <!-- Separate User Role Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 capitalize whitespace-nowrap">
                                {{ $log->user?->role ?? 'Internal' }}
                            </td>
                            <!-- Action Event Minimal Text (No Border, Left-Aligned) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-left whitespace-nowrap">
                                <span class="inline-block {{ $eventClass }} text-[10px] font-bold uppercase tracking-wider">
                                    {{ str_replace('_', ' ', $log->event) }}
                                </span>
                            </td>
                            <!-- Details -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 font-medium">
                                {{ class_basename($log->auditable_type ?? '—') }}{{ $log->auditable_id ? ' (' . $log->auditable_id . ')' : '' }}
                            </td>
                            <!-- Actions Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $log->id }}, event: '{{ $log->event }}' })" 
                                    type="button" 
                                    title="Options"
                                    class="inline-flex justify-center items-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
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

    <!-- Global Floating Context Menu -->
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
        class="w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.viewDetails(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View Record Details</span>
        </button>
        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.event); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Event Name</span>
        </button>
    </div>

    <!-- Audit Log Deep Detail Inspection Modal -->
    @if ($viewingLogId && $viewingLog)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            Audit Record {{ $viewingLog->id }}
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
                                {{ $viewingLog->auditable_id ?: '—' }}
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
