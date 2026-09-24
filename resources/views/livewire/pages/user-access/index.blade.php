<?php
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;
new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public function toggleActive(int $id): void
    {
        $user = User::findOrFail($id);
        $user->update(['active' => !$user->active]);
    }
    public function changeRole(int $id, string $role): void
    {
        abort_unless(in_array($role, ['dev', 'admin', 'manager', 'mixer'], true), 422);
        User::findOrFail($id)->update(['role' => $role]);
    }
    public function render(): mixed
    {
        return view('livewire.pages.user-access.index', ['users' => User::where(fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('username', 'like', '%' . $this->search . '%'))->latest()->paginate(20)]);
    }
}; ?>
<div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">User Management</h1>
        <p class="mt-1 text-sm text-gray-600">Manage access state and application roles without exposing passwords.</p>
    </div><input wire:model.live.debounce.300ms="search" type="search" placeholder="Search users"
        class="w-full max-w-sm rounded-md border-gray-300">
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Username</th>
                    <th class="px-3 py-2">Role</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($users as $user)
                    <tr>
                        <td class="px-3 py-2">{{ $user->name }}</td>
                        <td class="px-3 py-2">{{ $user->username }}</td>
                        <td class="px-3 py-2"><select wire:change="changeRole({{ $user->id }}, $event.target.value)"
                                class="rounded-md border-gray-300 text-sm">
                                <option value="dev" @selected($user->role === 'dev')>Dev</option>
                                <option value="admin" @selected($user->role === 'admin')>Admin</option>
                                <option value="manager" @selected($user->role === 'manager')>Manager</option>
                                <option value="mixer" @selected($user->role === 'mixer')>Mixer</option>
                            </select></td>
                        <td class="px-3 py-2"><span
                                class="font-semibold {{ $user->active ? 'text-green-700' : 'text-gray-500' }}">{{ $user->active ? 'Active' : 'Deactivated' }}</span>
                        </td>
                        <td class="px-3 py-2"><button wire:click="toggleActive({{ $user->id }})" type="button"
                                class="{{ $user->active ? 'text-red-600' : 'text-green-700' }}">{{ $user->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="p-4">{{ $users->links() }}</div>
    </div>
</div>
