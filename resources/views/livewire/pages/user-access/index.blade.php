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
        $allowedRoles = auth()->user()->role === 'dev' 
            ? ['dev', 'admin', 'manager', 'mixer'] 
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
        $allowedRoles = auth()->user()->role === 'dev' 
            ? ['dev', 'admin', 'manager', 'mixer'] 
            : ['admin', 'manager', 'mixer'];

        // If target user is dev and current user is not dev, forbid editing
        if ($user->role === 'dev' && auth()->user()->role !== 'dev') {
            abort(403, 'Unauthorized to modify developer accounts.');
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
        if ($user->role === 'dev' && auth()->user()->role !== 'dev') {
            abort(403, 'Unauthorized to reset developer credentials.');
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
        if ($user->role === 'dev' && auth()->user()->role !== 'dev') {
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

        if ($user->role === 'dev' && auth()->user()->role !== 'dev') {
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
        abort_unless(in_array($role, ['dev', 'admin', 'manager', 'mixer'], true), 422);
        $user = User::findOrFail($id);

        if ($user->role === 'dev' && auth()->user()->role !== 'dev') {
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

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Administration</span>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Security</span>
            </div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">User Access Control</h1>
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

    <!-- Main Workspace with Left Side Filter Panel & Users Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3.5">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">User Filters</span>
                @if ($search !== '' || $roleFilter !== '' || $statusFilter !== 'all')
                    <button 
                        wire:click="$set('search', ''); $set('roleFilter', ''); $set('statusFilter', 'all');" 
                        type="button" 
                        class="text-[11px] font-semibold text-slate-500 hover:text-slate-900"
                    >
                        Reset
                    </button>
                @endif
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Staff</label>
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Name or username..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>

            <!-- Role Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Role</label>
                <select wire:model.live="roleFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Roles</option>
                    @if (auth()->user()->role === 'dev')
                        <option value="dev">Developer (dev)</option>
                    @endif
                    <option value="admin">Admin (Store Owner)</option>
                    <option value="manager">Manager</option>
                    <option value="mixer">Paint Mixer</option>
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Login Status</label>
                <select wire:model.live="statusFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Statuses</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Deactivated Only</option>
                </select>
            </div>
        </aside>

        <!-- Users Grid Table -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                        <tr>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-16">ID</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Staff Name</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Username</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-32">Role</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Status</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-32">Created Date</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-64">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($users as $user)
                            <tr class="hover:bg-slate-50 transition-colors" wire:key="user-row-{{ $user->id }}">
                                <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500">#{{ $user->id }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ $user->name }}</span>
                                        @if ($user->id === auth()->id())
                                            <span class="inline-block rounded border border-blue-600 text-blue-700 bg-transparent px-1 py-0.2 text-[9px] font-bold uppercase tracking-wider">
                                                You
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-700">
                                    {{ $user->username }}
                                </td>
                                <!-- Role Badge -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($user->role === 'dev')
                                        <span class="inline-block rounded border border-purple-600 text-purple-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Developer
                                        </span>
                                    @elseif ($user->role === 'admin')
                                        <span class="inline-block rounded border border-blue-600 text-blue-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Admin
                                        </span>
                                    @elseif ($user->role === 'manager')
                                        <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Manager
                                        </span>
                                    @elseif ($user->role === 'mixer')
                                        <span class="inline-block rounded border border-amber-600 text-amber-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Paint Mixer
                                        </span>
                                    @endif
                                </td>
                                <!-- Status -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($user->active)
                                        <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Deactivated
                                        </span>
                                    @endif
                                </td>
                                <!-- Created Date -->
                                <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                    {{ $user->created_at->format('M d, Y') }}
                                </td>
                                <!-- Real Action Buttons -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap space-x-1">
                                    <button 
                                        wire:click="openEditModal({{ $user->id }})" 
                                        type="button" 
                                        class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                                    >
                                        Edit
                                    </button>
                                    <button 
                                        wire:click="openResetPasswordModal({{ $user->id }})" 
                                        type="button" 
                                        class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-amber-700 hover:bg-amber-50 shadow-xs transition"
                                    >
                                        Reset Password
                                    </button>
                                    @if ($user->id !== auth()->id())
                                        <button 
                                            wire:click="confirmToggleActive({{ $user->id }})" 
                                            type="button" 
                                            class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium {{ $user->active ? 'text-rose-700 hover:bg-rose-50' : 'text-emerald-700 hover:bg-emerald-50' }} shadow-xs transition"
                                        >
                                            {{ $user->active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    @endif
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
                                        @if (auth()->user()->role === 'dev')
                                            <option value="dev">Developer (Full Access)</option>
                                        @endif
                                        <option value="admin">Store Admin (Owner)</option>
                                        <option value="manager">Manager (Sales & Inventory)</option>
                                        <option value="mixer">Paint Mixer (POS & Mixing)</option>
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
                                @if (auth()->user()->role === 'dev')
                                    <option value="dev">Developer (Full Access)</option>
                                @endif
                                <option value="admin">Store Admin (Owner)</option>
                                <option value="manager">Manager (Sales & Inventory)</option>
                                <option value="mixer">Paint Mixer (POS & Mixing)</option>
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
</div>
