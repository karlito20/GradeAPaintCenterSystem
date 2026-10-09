<?php

use App\Models\AuditLog;
use App\Models\PackageUnit;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $name = '';
    public string $abbreviation = '';
    public ?int $editingId = null;
    public bool $showModal = false;

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

    public function openCreateModal(): void
    {
        $this->reset(['name', 'abbreviation', 'editingId']);
        $this->resetValidation();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $unit = PackageUnit::findOrFail($id);
        $this->editingId = $id;
        $this->name = $unit->name;
        $this->abbreviation = $unit->abbreviation ?? '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['name', 'abbreviation', 'editingId']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                $this->editingId ? 'unique:package_units,name,' . $this->editingId : 'unique:package_units,name',
            ],
            'abbreviation' => ['nullable', 'string', 'max:20'],
        ];

        $this->validate($rules);

        if ($this->editingId) {
            $unit = PackageUnit::findOrFail($this->editingId);
            $oldName = $unit->name;
            $unit->name = trim($this->name);
            $unit->abbreviation = $this->abbreviation ? trim($this->abbreviation) : null;
            $unit->save();

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'package_unit_updated',
                'auditable_type' => PackageUnit::class,
                'auditable_id' => $unit->id,
                'context' => ['old_name' => $oldName, 'name' => $unit->name, 'abbreviation' => $unit->abbreviation],
            ]);

            $message = 'Package unit updated successfully.';
        } else {
            $unit = PackageUnit::create([
                'name' => trim($this->name),
                'abbreviation' => $this->abbreviation ? trim($this->abbreviation) : null,
                'active' => true,
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'package_unit_created',
                'auditable_type' => PackageUnit::class,
                'auditable_id' => $unit->id,
                'context' => ['name' => $unit->name, 'abbreviation' => $unit->abbreviation],
            ]);

            $message = 'Package unit created successfully.';
        }

        $this->closeModal();
        session()->flash('status', $message);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    public function confirmToggle(int $id): void
    {
        $unit = PackageUnit::findOrFail($id);
        $this->confirmingToggleId = $unit->id;
        $this->confirmingAction = $unit->active ? 'deactivate' : 'reactivate';
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

        $unit = PackageUnit::findOrFail($this->confirmingToggleId);
        $newActive = !$unit->active;
        $unit->update(['active' => $newActive]);

        $event = $newActive ? 'package_unit_reactivated' : 'package_unit_deactivated';
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => PackageUnit::class,
            'auditable_id' => $unit->id,
            'context' => ['name' => $unit->name, 'active' => $newActive],
        ]);

        $message = $newActive ? "Package unit '{$unit->name}' reactivated." : "Package unit '{$unit->name}' deactivated.";
        $this->cancelConfirmToggle();
        session()->flash('status', $message);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    public function toggleActive(int $id): void
    {
        $this->confirmingToggleId = $id;
        $this->executeToggle();
    }

    public function render(): mixed
    {
        $query = PackageUnit::withCount('products')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%' . trim($this->search) . '%')
                ->orWhere('abbreviation', 'like', '%' . trim($this->search) . '%'))
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('active', false))
            ->latest('id');

        return view('livewire.pages.references.package-units', [
            'units' => $query->paginate(15),
            'unitToToggle' => $this->confirmingToggleId ? PackageUnit::withCount('products')->find($this->confirmingToggleId) : null,
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
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Units</h1>
        </div>
        <div>
            <button 
                wire:click="openCreateModal" 
                type="button" 
                class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
            >
                <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Create Unit
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-xs font-medium text-emerald-800 shadow-xs">
            {{ session('status') }}
        </div>
    @endif

    <!-- Package Units Grid Table Card with Integrated Filter Bar -->
    <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <!-- Integrated Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <div class="flex-1 min-w-[200px]">
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search unit name or abbreviation..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>
            <div class="w-40">
                <select wire:model.live="statusFilter" class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Units</option>
                    <option value="active">Active Only</option>
                    <option value="inactive">Inactive Only</option>
                </select>
            </div>
            @if ($search !== '' || $statusFilter !== 'all')
                <button 
                    wire:click="$set('search', ''); $set('statusFilter', 'all');" 
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
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-12 whitespace-nowrap">ID</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Unit Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28 whitespace-nowrap">Abbreviation</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-36 whitespace-nowrap">Associated Products</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24 whitespace-nowrap">Status</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($units as $unit)
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="unit-row-{{ $unit->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $unit->id }}, name: '{{ addslashes($unit->name) }}', active: {{ $unit->active ? 'true' : 'false' }} })"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-500 whitespace-nowrap">{{ $unit->id }}</td>
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900">{{ $unit->name }}</td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center font-mono text-slate-700 whitespace-nowrap">
                                {{ $unit->abbreviation ?? '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700 whitespace-nowrap">
                                {{ $unit->products_count }} {{ Str::plural('item', $unit->products_count) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($unit->active)
                                    <span class="inline-flex items-center text-[10px] font-bold uppercase text-emerald-700">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center text-[10px] font-bold uppercase text-slate-500">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $unit->id }}, name: '{{ addslashes($unit->name) }}', active: {{ $unit->active ? 'true' : 'false' }} })" 
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
                            <td colspan="6" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                <div class="mx-auto flex flex-col items-center justify-center">
                                    <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    <p class="text-xs font-semibold text-slate-700">No package units found matching your criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($units->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $units->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Package Unit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <h3 class="font-heading text-sm font-bold text-slate-900">
                        {{ $editingId ? 'Edit Package Unit' : 'Create New Package Unit' }}
                    </h3>
                    <button wire:click="closeModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="save" class="p-4 space-y-3.5">
                    <div>
                        <label for="modal_unit_name" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Unit Name</label>
                        <input 
                            wire:model="name" 
                            id="modal_unit_name" 
                            type="text" 
                            placeholder="e.g. 1 Gallon, 4 Liters, Pail, Drum" 
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" 
                            required 
                            autofocus 
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <label for="modal_unit_abbreviation" class="block text-[11px] font-semibold text-slate-700 uppercase tracking-wider mb-1">Abbreviation (Optional)</label>
                        <input 
                            wire:model="abbreviation" 
                            id="modal_unit_abbreviation" 
                            type="text" 
                            placeholder="e.g. gal, 4L, pail" 
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" 
                        />
                        <x-input-error :messages="$errors->get('abbreviation')" class="mt-1" />
                    </div>

                    <div class="pt-3 border-t border-slate-200 flex justify-end gap-2">
                        <button wire:click="closeModal" type="button" class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                            Cancel
                        </button>
                        <button type="submit" class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition">
                            {{ $editingId ? 'Save Changes' : 'Create Unit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Confirmation Modal for Status Toggle -->
    @if ($confirmingToggleId && $unitToToggle)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl border border-slate-300 space-y-3.5">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded border {{ $confirmingAction === 'deactivate' ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-emerald-300 bg-emerald-50 text-emerald-700' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            {{ $confirmingAction === 'deactivate' ? 'Deactivate Package Unit' : 'Reactivate Package Unit' }} ({{ $unitToToggle->name }})
                        </h3>
                    </div>
                </div>

                @if ($confirmingAction === 'deactivate' && $unitToToggle->products_count > 0)
                    <div class="rounded border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-800">
                        <span class="font-bold">Notice:</span> This package unit currently has <span class="font-mono font-bold">{{ $unitToToggle->products_count }}</span> associated product(s). Deactivating the unit will prevent new products from selecting it, but will not delete existing product records.
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
        class="w-40 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.edit(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span>Edit</span>
        </button>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { $wire.confirmToggle(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            :class="contextMenu.item?.active ? 'text-rose-700 hover:bg-rose-50' : 'text-emerald-700 hover:bg-emerald-50'"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium transition-colors"
        >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            <span x-text="contextMenu.item?.active ? 'Deactivate' : 'Reactivate'"></span>
        </button>
    </div>
</div>
