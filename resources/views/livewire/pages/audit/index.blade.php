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
    public function resetFilters(): void
    {
        $this->reset(['search', 'event', 'userId', 'from', 'to']);
    }
    public function render(): mixed
    {
        return view('livewire.pages.audit.index', [
            'logs' => AuditLog::with('user')
                ->when(
                    $this->search,
                    fn($query) => $query->where(
                        fn($query) => $query
                            ->where('event', 'like', '%' . $this->search . '%')
                            ->orWhere('auditable_type', 'like', '%' . $this->search . '%')
                            ->orWhere('auditable_id', $this->search),
                    ),
                )
                ->when($this->event, fn($query) => $query->where('event', $this->event))
                ->when($this->userId, fn($query) => $query->where('user_id', $this->userId))
                ->when($this->from, fn($query) => $query->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn($query) => $query->whereDate('created_at', '<=', $this->to))
                ->latest()
                ->paginate(25),
            'users' => User::orderBy('name')->get(),
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}; ?>
<div class="mx-auto max-w-7xl space-y-4 px-4 py-5 text-sm sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">Audit Log</h1>
        <p class="mt-1 text-gray-600">Important authenticated actions and affected records.</p>
    </div>
    <div class="flex flex-wrap gap-2 rounded-lg bg-white p-3 shadow-sm"><input wire:model.live.debounce.300ms="search"
            type="search" placeholder="Search event/entity/id" class="rounded-md border-gray-300"><select
            wire:model.live="event" class="rounded-md border-gray-300">
            <option value="">All events</option>
            @foreach ($events as $eventName)
                <option value="{{ $eventName }}">{{ $eventName }}</option>
            @endforeach
        </select>
        <select wire:model.live="userId" class="rounded-md border-gray-300">
            <option value="">All users</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>
        <input wire:model.live="from" type="date" class="rounded-md border-gray-300"><input wire:model.live="to"
            type="date" class="rounded-md border-gray-300"><button wire:click="resetFilters" type="button"
            class="rounded-md border px-3 py-2 text-gray-600">Reset</button>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
            <thead class="bg-gray-50 uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2">Timestamp</th>
                    <th class="px-4 py-2">User</th>
                    <th class="px-4 py-2">Action</th>
                    <th class="px-4 py-2">Entity</th>
                    <th class="px-4 py-2">Record ID</th>
                    <th class="px-4 py-2">Context</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-2">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-2">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-4 py-2 uppercase">{{ str_replace('_', ' ', $log->event) }}</td>
                        <td class="px-4 py-2">{{ class_basename($log->auditable_type ?? '') }}</td>
                        <td class="px-4 py-2">{{ $log->auditable_id ?? '-' }}</td>
                        <td class="max-w-sm truncate px-4 py-2">{{ json_encode($log->context) }}</td>
                </tr>@empty<tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">No audit events found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-3">{{ $logs->links() }}</div>
    </div>
</div>
