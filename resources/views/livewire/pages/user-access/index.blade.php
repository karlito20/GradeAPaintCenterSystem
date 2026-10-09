<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $roleFilter = '';
    public string $statusFilter = 'all';

    // Create User Modal State
    public bool $showCreateModal = false;
    public string $createName = '';
    public string $createUsername = '';
    public string $createRole = 'manager';
    public string $createPassword = '';
    public string $createPassword_confirmation = '';

    // Edit User Modal State
    public bool $showEditModal = false;
    public ?int $editingUserId = null;
    public string $editName = '';
    public string $editUsername = '';
    public string $editRole = 'manager';

    // Reset Password Modal State
    public bool $showResetPasswordModal = false;
    public ?int $resetUserId = null;
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    // Confirmation Modal for Deactivation/Reactivation
    public bool $showToggleModal = false;
    public ?int $toggleUserId = null;
    public ?string $toggleAction = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetCreateForm();
        $this->resetValidation();
    }

    public function resetCreateForm(): void
    {
        $this->createName = '';
        $this->createUsername = '';
        $this->createRole = 'manager';
        $this->createPassword = '';
        $this->createPassword_confirmation = '';
    }

    public function createUser(): void
    {
        $allowedRoles = auth()->user()->role === 'superadmin' 
            ? ['superadmin', 'admin', 'manager', 'mixer'] 
            : ['admin', 'manager', 'mixer'];

        $this->validate([
            'createName' => ['required', 'string', 'max:255'],
            'createUsername' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'createRole' => ['required', 'string', Rule::in($allowedRoles)],
            'createPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => trim($this->createName),
            'username' => strtolower(trim($this->createUsername)),
            'role' => $this->createRole,
            'password' => $this->createPassword,
            'active' => true,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'user_created',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'context' => [
                'username' => $user->username,
                'role' => $user->role,
                'name' => $user->name,
            ],
        ]);

        $this->closeCreateModal();
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "User '{$user->username}' created successfully.",
        ]);
    }

    public function openEditModal(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editUsername = $user->username;
        $this->editRole = $user->role;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingUserId = null;
        $this->resetValidation();
    }

    public function updateUser(): void
    {
        if (!$this->editingUserId) {
            return;
        }

        $user = User::findOrFail($this->editingUserId);
        $allowedRoles = auth()->user()->role === 'superadmin' 
            ? ['superadmin', 'admin', 'manager', 'mixer'] 
            : ['admin', 'manager', 'mixer'];

        // If target user is superadmin and current user is not superadmin, forbid editing
        if ($user->role === 'superadmin' && auth()->user()->role !== 'superadmin') {
            abort(403, 'Unauthorized to modify superadmin accounts.');
        }

        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editUsername' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'editRole' => ['required', 'string', Rule::in($allowedRoles)],
        ]);

        $oldData = ['name' => $user->name, 'username' => $user->username, 'role' => $user->role];

        $user->update([
            'name' => trim($this->editName),
            'username' => strtolower(trim($this->editUsername)),
            'role' => $this->editRole,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'user_updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'context' => [
                'old' => $oldData,
                'new' => ['name' => $user->name, 'username' => $user->username, 'role' => $user->role],
            ],
        ]);

        $this->closeEditModal();
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "User '{$user->username}' updated successfully.",
        ]);
    }

    public function openResetPasswordModal(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->role === 'superadmin' && auth()->user()->role !== 'superadmin') {
            abort(403, 'Unauthorized to reset superadmin credentials.');
        }

        $this->resetUserId = $user->id;
        $this->newPassword = '';
        $this->newPassword_confirmation = '';
        $this->showResetPasswordModal = true;
    }

    public function closeResetPasswordModal(): void
    {
        $this->showResetPasswordModal = false;
        $this->resetUserId = null;
        $this->newPassword = '';
        $this->newPassword_confirmation = '';
        $this->resetValidation();
    }

    public function resetPassword(): void
    {
        if (!$this->resetUserId) {
            return;
        }

        $user = User::findOrFail($this->resetUserId);
        if ($user->role === 'superadmin' && auth()->user()->role !== 'superadmin') {
            abort(403);
        }

        $this->validate([
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => $this->newPassword,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'user_password_reset',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'context' => ['username' => $user->username],
        ]);

        $this->closeResetPasswordModal();
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Password for '{$user->username}' was reset successfully.",
        ]);
    }

    public function confirmToggleActive(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'You cannot deactivate your own logged-in account.',
            ]);
            return;
        }

        if ($user->active && $user->role === 'admin') {
            $activeAdminCount = User::where('role', 'admin')->where('active', true)->count();
            if ($activeAdminCount <= 1) {
                $this->dispatch('toast', [
                    'type' => 'error',
                    'message' => 'Cannot deactivate the sole active store administrator.',
                ]);
                return;
            }
        }

        if ($user->role === 'superadmin' && auth()->user()->role !== 'superadmin') {
            abort(403);
        }

        $this->toggleUserId = $user->id;
        $this->toggleAction = $user->active ? 'deactivate' : 'reactivate';
        $this->showToggleModal = true;
    }

    public function cancelToggleActive(): void
    {
        $this->showToggleModal = false;
        $this->toggleUserId = null;
        $this->toggleAction = null;
    }

    public function executeToggleActive(): void
    {
        if (!$this->toggleUserId) {
            return;
        }

        $user = User::findOrFail($this->toggleUserId);

        if ($user->id === auth()->id()) {
            $this->cancelToggleActive();
            return;
        }

        $newStatus = !$user->active;
        $user->update(['active' => $newStatus]);

        $event = $newStatus ? 'user_reactivated' : 'user_deactivated';
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'context' => [
                'username' => $user->username,
                'active' => $newStatus,
            ],
        ]);

        $msg = $newStatus ? "User '{$user->username}' reactivated." : "User '{$user->username}' deactivated.";
        $this->cancelToggleActive();
        $this->dispatch('toast', ['type' => 'success', 'message' => $msg]);
    }

    // Retain legacy method for backward compatibility
    public function toggleActive(int $id): void
    {
        $this->toggleUserId = $id;
        $this->executeToggleActive();
    }

    // Retain legacy method for backward compatibility
    public function changeRole(int $id, string $role): void
    {
        abort_unless(in_array($role, ['superadmin', 'admin', 'manager', 'mixer'], true), 422);
        $user = User::findOrFail($id);

        if ($user->role === 'superadmin' && auth()->user()->role !== 'superadmin') {
            abort(403);
        }

        $oldRole = $user->role;
        $user->update(['role' => $role]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'user_role_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'context' => ['old_role' => $oldRole, 'new_role' => $role],
        ]);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "User '{$user->username}' role changed to " . ucfirst($role) . ".",
        ]);
    }

    public function render(): mixed
    {
        $query = User::query()
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('username', 'like', $term));
            })
            ->when($this->roleFilter !== '', fn ($q) => $q->where('role', $this->roleFilter))
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('active', false))
            ->latest('id');

        return view('livewire.pages.user-access.index', [
            'users' => $query->paginate(20),
            'userToToggle' => $this->toggleUserId ? User::find($this->toggleUserId) : null,
            'userToReset' => $this->resetUserId ? User::find($this->resetUserId) : null,
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
            
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Users</h1>
        </div>
        <div class="flex items-center gap-2">
            <button 
                wire:click="openCreateModal" 
                type="button" 
                class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
            >
                <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                Create New User
            </button>
        </div>
    </div>

    <!-- Table Container with Seamless Top Filters -->
    <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <!-- Horizontal Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <div class="w-56">
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search staff name or username..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>
            <div class="w-40">
                <select wire:model.live="roleFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Roles</option>
                    @if (auth()->user()->role === 'superadmin')
                        <option value="superadmin">Superadmin</option>
                    @endif
                    <option value="admin">Store Admin</option>
                    <option value="manager">Manager</option>
                    <option value="mixer">Paint Mixer</option>
                </select>
            </div>
            <div class="w-36">
                <select wire:model.live="statusFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Statuses</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Inactive Only</option>
                </select>
            </div>
            @if ($search !== '' || $roleFilter !== '' || $statusFilter !== 'all')
                <button 
                    wire:click="$set('search', ''); $set('roleFilter', ''); $set('statusFilter', 'all');" 
                    type="button" 
                    class="text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium ml-auto"
                >
                    Reset
                </button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-12 whitespace-nowrap">ID</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Staff Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36 whitespace-nowrap">Username</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-32 whitespace-nowrap">Role</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24 whitespace-nowrap">Status</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-32 whitespace-nowrap">Created Date</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($users as $user)
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="user-row-{{ $user->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $user->id }}, name: '{{ addslashes($user->name) }}', active: {{ $user->active ? 'true' : 'false' }} })"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-500 whitespace-nowrap">{{ $user->id }}</td>
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900">
                                <div class="flex items-center gap-1.5">
                                    <span>{{ $user->name }}</span>
                                    @if ($user->id === auth()->id())
                                        <span class="inline-block rounded bg-blue-50 text-blue-700 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider">
                                            You
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-700 whitespace-nowrap font-mono">
                                {{ $user->username }}
                            </td>
                            <!-- Role Badge -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap text-slate-700">
                                @if ($user->role === 'superadmin')
                                    Superadmin
                                @elseif ($user->role === 'admin')
                                    Store Admin
                                @elseif ($user->role === 'manager')
                                    Manager
                                @elseif ($user->role === 'mixer')
                                    Paint Mixer
                                @else
                                    {{ ucfirst($user->role) }}
                                @endif
                            </td>
                            <!-- Status -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($user->active)
                                    <span class="inline-flex items-center text-[10px] font-bold uppercase text-emerald-700">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-[10px] font-bold uppercase text-slate-500">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                            <!-- Created Date -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-600 whitespace-nowrap">
                                {{ $user->created_at->format('M d, Y') }}
                            </td>
                            <!-- Context Menu Actions -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $user->id }}, name: '{{ addslashes($user->name) }}', active: {{ $user->active ? 'true' : 'false' }} })" 
                                    type="button" 
                                    title="More actions"
                                    class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none" />
                                        <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                                        <circle cx="12" cy="19" r="1.5" fill="currentColor" stroke="none" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                    <div class="mx-auto flex flex-col items-center justify-center">
                                        <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        <p class="text-xs font-semibold text-slate-700">No users found matching your criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($users->hasPages())
                <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                    {{ $users->links() }}
                </div>
            @endif
        </div>

    <!-- Create User Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">Create New User Account</h3>
                    </div>
                    <button wire:click="closeCreateModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="createUser" class="p-5 space-y-4">
                    <!-- Section 1: Staff Identification -->
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-2">Staff Identification</span>
                        <div class="space-y-3">
                            <div>
                                <label for="create_name" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Full Name</label>
                                <input wire:model="createName" id="create_name" placeholder="e.g. Maria Santos" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required autofocus />
                                <x-input-error :messages="$errors->get('createName')" class="mt-1" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="create_username" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Username (Login ID)</label>
                                    <input wire:model="createUsername" id="create_username" placeholder="e.g. msantos" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required />
                                    <x-input-error :messages="$errors->get('createUsername')" class="mt-1" />
                                </div>

                                <div>
                                    <label for="create_role" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Assigned Role</label>
                                    <select wire:model="createRole" id="create_role" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required>
                                        @if (auth()->user()->role === 'superadmin')
                                            <option value="superadmin">Superadmin</option>
                                        @endif
                                        <option value="admin">Store Admin</option>
                                        <option value="manager">Manager</option>
                                        <option value="mixer">Paint Mixer</option>
                                    </select>
                                    <x-input-error :messages="$errors->get('createRole')" class="mt-1" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section Separator -->
                    <div class="border-t border-slate-200 pt-3">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-2">Initial Credentials</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="create_password" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Initial Password</label>
                                <input wire:model="createPassword" id="create_password" type="password" placeholder="At least 8 characters" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required />
                                <x-input-error :messages="$errors->get('createPassword')" class="mt-1" />
                            </div>

                            <div>
                                <label for="create_password_confirmation" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Confirm Password</label>
                                <input wire:model="createPassword_confirmation" id="create_password_confirmation" type="password" placeholder="Re-type password" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required />
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-200 flex justify-end gap-2">
                        <button wire:click="closeCreateModal" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                            Cancel
                        </button>
                        <button type="submit" class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition">
                            Create Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Edit User Modal -->
    @if ($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">Edit User Account</h3>
                    </div>
                    <button wire:click="closeEditModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="updateUser" class="p-5 space-y-4">
                    <!-- Section 1: Staff Identification -->
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-2">Staff Identification</span>
                        <div class="space-y-3">
                            <div>
                                <label for="edit_name" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Full Name</label>
                                <input wire:model="editName" id="edit_name" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required autofocus />
                                <x-input-error :messages="$errors->get('editName')" class="mt-1" />
                            </div>

                            <div>
                                <label for="edit_username" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Username (Login ID)</label>
                                <input wire:model="editUsername" id="edit_username" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required />
                                <x-input-error :messages="$errors->get('editUsername')" class="mt-1" />
                            </div>
                        </div>
                    </div>

                    <!-- Section Separator -->
                    <div class="border-t border-slate-200 pt-3">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-2">Role & Permissions</span>
                        <div>
                            <label for="edit_role" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Assigned Role</label>
                            <select wire:model="editRole" id="edit_role" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required>
                                @if (auth()->user()->role === 'superadmin')
                                    <option value="superadmin">Superadmin</option>
                                @endif
                                <option value="admin">Store Admin</option>
                                <option value="manager">Manager</option>
                                <option value="mixer">Paint Mixer</option>
                            </select>
                            <x-input-error :messages="$errors->get('editRole')" class="mt-1" />
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-200 flex justify-end gap-2">
                        <button wire:click="closeEditModal" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                            Cancel
                        </button>
                        <button type="submit" class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Reset Password Modal -->
    @if ($showResetPasswordModal && $userToReset)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">Reset Password ({{ $userToReset->username }})</h3>
                    </div>
                    <button wire:click="closeResetPasswordModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="resetPassword" class="p-4 space-y-3.5">
                    <div>
                        <label for="new_password" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">New Password</label>
                        <input wire:model="newPassword" id="new_password" type="password" placeholder="Minimum 8 characters" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required autofocus />
                        <x-input-error :messages="$errors->get('newPassword')" class="mt-1" />
                    </div>

                    <div>
                        <label for="new_password_confirmation" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Confirm New Password</label>
                        <input wire:model="newPassword_confirmation" id="new_password_confirmation" type="password" placeholder="Re-type new password" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" required />
                    </div>

                    <div class="pt-3 border-t border-slate-200 flex justify-end gap-2">
                        <button wire:click="closeResetPasswordModal" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            wire:confirm="Are you sure you want to reset the password for {{ $userToReset->username }}?"
                            class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
                        >
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Toggle Status Confirmation Modal -->
    @if ($showToggleModal && $userToToggle)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden p-5 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded border {{ $toggleAction === 'deactivate' ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-emerald-300 bg-emerald-50 text-emerald-700' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            {{ $toggleAction === 'deactivate' ? 'Deactivate User Account' : 'Reactivate User Account' }}
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            User: <span class="font-semibold text-slate-800">{{ $userToToggle->name }}</span> ({{ $userToToggle->username }})
                        </p>
                    </div>
                </div>

                <div class="rounded border border-slate-200 bg-slate-50 p-2.5 text-xs text-slate-700">
                    @if ($toggleAction === 'deactivate')
                        Deactivating this user will immediately revoke login access. Their historical sales, mixing transactions, and movements will remain intact.
                    @else
                        Reactivating this user will restore their ability to log in with their existing credentials.
                    @endif
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button wire:click="cancelToggleActive" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                        Cancel
                    </button>
                    <button 
                        wire:click="executeToggleActive" 
                        type="button" 
                        class="rounded px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs transition {{ $toggleAction === 'deactivate' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-[#00a3cc] hover:bg-[#008fb3]' }}"
                    >
                        Confirm {{ ucfirst($toggleAction) }}
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
            @click="if (contextMenu.item) { $wire.openEditModal(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span>Edit</span>
        </button>
        <button 
            @click="if (contextMenu.item) { $wire.openResetPasswordModal(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
            <span>Reset Password</span>
        </button>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { $wire.confirmToggleActive(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            :class="contextMenu.item?.active ? 'text-rose-700 hover:bg-rose-50' : 'text-emerald-700 hover:bg-emerald-50'"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium transition-colors"
        >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            <span x-text="contextMenu.item?.active ? 'Deactivate' : 'Reactivate'"></span>
        </button>
    </div>
</div>
