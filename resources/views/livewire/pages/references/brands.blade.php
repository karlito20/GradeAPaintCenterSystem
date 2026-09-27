<?php

use App\Models\AuditLog;
use App\Models\Brand;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $name = '';
    public ?int $editingId = null;

    public ?int $confirmingToggleId = null;
    public ?string $confirmingAction = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                $this->editingId ? 'unique:brands,name,' . $this->editingId : 'unique:brands,name',
            ],
        ];

        $this->validate($rules);

        if ($this->editingId) {
            $brand = Brand::findOrFail($this->editingId);
            $oldName = $brand->name;
            $brand->name = trim($this->name);
            $brand->save();

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'brand_updated',
                'auditable_type' => Brand::class,
                'auditable_id' => $brand->id,
                'context' => ['old_name' => $oldName, 'name' => $brand->name],
            ]);

            $message = 'Brand updated successfully.';
        } else {
            $brand = Brand::create([
                'name' => trim($this->name),
                'active' => true,
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'brand_created',
                'auditable_type' => Brand::class,
                'auditable_id' => $brand->id,
                'context' => ['name' => $brand->name],
            ]);

            $message = 'Brand created successfully.';
        }

        $this->reset(['name', 'editingId']);
        session()->flash('status', $message);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    public function edit(int $id): void
    {
        $brand = Brand::findOrFail($id);
        $this->editingId = $id;
        $this->name = $brand->name;
    }

    public function cancelEdit(): void
    {
        $this->reset(['name', 'editingId']);
        $this->resetValidation();
    }

    public function confirmToggle(int $id): void
    {
        $brand = Brand::findOrFail($id);
        $this->confirmingToggleId = $brand->id;
        $this->confirmingAction = $brand->active ? 'deactivate' : 'reactivate';
    }

    public function cancelConfirmToggle(): void
    {
        $this->reset(['confirmingToggleId', 'confirmingAction']);
    }

    public function executeToggle(): void
    {
        if (!$this->confirmingToggleId) {
            return;
        }

        $brand = Brand::findOrFail($this->confirmingToggleId);
        $newActive = !$brand->active;
        $brand->update(['active' => $newActive]);

        $event = $newActive ? 'brand_reactivated' : 'brand_deactivated';
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => Brand::class,
            'auditable_id' => $brand->id,
            'context' => ['name' => $brand->name, 'active' => $newActive],
        ]);

        $message = $newActive ? "Brand '{$brand->name}' reactivated." : "Brand '{$brand->name}' deactivated.";
        $this->cancelConfirmToggle();
        session()->flash('status', $message);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    // Retain legacy method for backward compatibility
    public function toggleActive(int $id): void
    {
        $this->confirmingToggleId = $id;
        $this->executeToggle();
    }

    public function render(): mixed
    {
        $query = Brand::withCount('products')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%' . trim($this->search) . '%'))
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('active', false))
            ->latest('id');

        return view('livewire.pages.references.brands', [
            'brands' => $query->paginate(15),
            'brandToToggle' => $this->confirmingToggleId ? Brand::withCount('products')->find($this->confirmingToggleId) : null,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('products.index') }}" class="text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-600">Inventory</a>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Settings</span>
            </div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Brands</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('references.categories') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                Categories
            </a>
            <a href="{{ route('references.package-units') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                Package Units
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-xs font-medium text-emerald-800 shadow-xs">
            {{ session('status') }}
        </div>
    @endif

    <!-- Main Workspace with Left Side Filter Panel & Right Table/Form -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3.5">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filters</span>
                @if ($search !== '' || $statusFilter !== 'all')
                    <button 
                        wire:click="$set('search', ''); $set('statusFilter', 'all');" 
                        type="button" 
                        class="text-[11px] font-semibold text-slate-500 hover:text-slate-900"
                    >
                        Reset
                    </button>
                @endif
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Brand</label>
                <input 
                    wire:model.live.debounce.300ms="search"
                    type="search" 
                    placeholder="Brand name..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>

            <!-- Status Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Status</label>
                <select wire:model.live="statusFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Brands</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Deactivated Only</option>
                </select>
            </div>
        </aside>

        <!-- Right Side: Add/Edit Form + Table -->
        <div class="flex-1 min-w-0 w-full space-y-4">
            <!-- Brand Add / Edit Form Card -->
            <div class="rounded-lg border border-slate-300 bg-white p-4 shadow-xs">
                <div class="mb-2.5 flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="font-heading text-sm font-bold text-slate-900">
                        {{ $editingId ? 'Edit Brand' : 'Add New Brand' }}
                    </h2>
                    @if ($editingId)
                        <button wire:click="cancelEdit" type="button" class="text-xs font-medium text-slate-500 hover:text-slate-700">
                            Cancel Editing
                        </button>
                    @endif
                </div>

                <form wire:submit="save" class="flex flex-col gap-2.5 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <label for="brand_name" class="block text-[11px] font-semibold text-slate-600 mb-1">Brand Name</label>
                        <input 
                            id="brand_name"
                            wire:model="name" 
                            type="text"
                            placeholder="e.g. Boysen, Davies, Nippon Paint, Triton" 
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500"
                            required 
                            autofocus
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div class="flex items-center gap-2 sm:mt-5">
                        <button 
                            type="submit" 
                            class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
                        >
                            <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            {{ $editingId ? 'Save Changes' : 'Create Brand' }}
                        </button>
                        @if ($editingId)
                            <button 
                                type="button" 
                                wire:click="cancelEdit"
                                class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition"
                            >
                                Cancel
                            </button>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Brands Grid Table -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                            <tr>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-16">ID</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Brand Name</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-36">Associated Products</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Status</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-44">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse ($brands as $brand)
                                <tr class="hover:bg-slate-50 transition-colors {{ $editingId === $brand->id ? 'bg-amber-50/40' : '' }}" wire:key="brand-row-{{ $brand->id }}">
                                    <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500">#{{ $brand->id }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900">{{ $brand->name }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                        {{ $brand->products_count }} {{ Str::plural('item', $brand->products_count) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        @if ($brand->active)
                                            <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                                Active
                                            </span>
                                        @else
                                            <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                                Deactivated
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap space-x-1">
                                        <button 
                                            wire:click="edit({{ $brand->id }})" 
                                            type="button"
                                            class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            wire:click="confirmToggle({{ $brand->id }})" 
                                            type="button"
                                            class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium {{ $brand->active ? 'text-rose-700 hover:bg-rose-50' : 'text-emerald-700 hover:bg-emerald-50' }} shadow-xs transition"
                                        >
                                            {{ $brand->active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                        <div class="mx-auto flex flex-col items-center justify-center">
                                            <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                            </svg>
                                            <p class="text-xs font-semibold text-slate-700">No brands found matching your criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($brands->hasPages())
                    <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                        {{ $brands->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Confirmation Modal for Status Toggle -->
    @if ($confirmingToggleId && $brandToToggle)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-[#00a3cc]/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl border border-slate-300 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded border {{ $confirmingAction === 'deactivate' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-emerald-300 bg-emerald-50 text-emerald-700' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            {{ $confirmingAction === 'deactivate' ? 'Deactivate Brand' : 'Reactivate Brand' }} ({{ $brandToToggle->name }})
                        </h3>
                    </div>
                </div>

                @if ($confirmingAction === 'deactivate' && $brandToToggle->products_count > 0)
                    <div class="rounded border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-800">
                        <span class="font-bold">Notice:</span> This brand currently has <span class="font-mono font-bold">{{ $brandToToggle->products_count }}</span> associated product(s). Deactivating the brand will prevent new products from selecting it, but will not delete existing product stock records.
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-1 border-t border-slate-200">
                    <button 
                        wire:click="cancelConfirmToggle"
                        type="button" 
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                    >
                        Cancel
                    </button>
                    <button 
                        wire:click="executeToggle" 
                        type="button"
                        class="rounded px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs transition {{ $confirmingAction === 'deactivate' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-[#00a3cc] hover:bg-[#008fb3]' }}"
                    >
                        Confirm {{ ucfirst($confirmingAction) }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
