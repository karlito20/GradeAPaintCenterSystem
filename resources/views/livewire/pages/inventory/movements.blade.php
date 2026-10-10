<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $type = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $userId = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'type', 'brandId', 'categoryId', 'userId', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $query = InventoryMovement::with(['product.packageUnit', 'product.brand', 'product.category', 'user'])
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->whereHas('product', fn ($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term))
                        ->orWhere('reference_text', 'like', $term);
                });
            })
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->brandId !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->where('brand_id', $this->brandId)))
            ->when($this->categoryId !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->where('category_id', $this->categoryId)))
            ->when($this->userId !== '', fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id');

        // Summary metrics based on current filters
        $summaryQuery = clone $query;
        $totalIn = (float) (clone $summaryQuery)->where('quantity_change', '>', 0)->sum('quantity_change');
        $totalOut = (float) abs((clone $summaryQuery)->where('quantity_change', '<', 0)->sum('quantity_change'));

        return view('livewire.pages.inventory.movements', [
            'movements' => $query->paginate(25),
            'types' => InventoryMovement::query()->distinct()->orderBy('type')->pluck('type'),
            'brands' => Brand::query()->where('active', true)->orderBy('name')->get(),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(),
            'users' => User::query()->where('active', true)->orderBy('name')->get(),
            'totalIn' => $totalIn,
            'totalOut' => $totalOut,
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
                this.openAt(btn.right - 192, btn.bottom + 4, item);
            },
            openFromEvent(event, item) {
                this.openAt(event.clientX, event.clientY, item);
            },
            close() {
                this.open = false;
                this.item = null;
            }
        },
        copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            $wire.dispatch('toast', { type: 'success', message: 'Copied to clipboard: ' + text });
        }
    }"
    @click.window="contextMenu.close()"
    @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()"
    @resize.window="contextMenu.close()"
    class="space-y-4 w-full min-w-0"
>
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Movements</h1>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Filtered Inflow (Stock-In)</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-emerald-700 mt-2 text-right">+{{ number_format($totalIn, 2) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Units added</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Filtered Outflow (Sales / Deductions)</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-slate-900 mt-2 text-right">{{ number_format($totalOut, 2) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Units deducted</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Total Movement Events</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-slate-900 mt-2 text-right">{{ number_format($movements->total()) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Recorded entries</p>
        </div>
    </div>

    <!-- Table Container with Seamless Top Filters -->
    <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <!-- Horizontal Filter Bar -->
        <div class="p-2.5 bg-slate-50 border-b border-slate-200 flex flex-wrap xl:flex-nowrap items-center gap-2 overflow-x-auto">
            <div class="w-44 min-w-[150px]">
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search SKU, name..." 
                    class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>

            <div class="w-32">
                <select wire:model.live="type" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Types</option>
                    @foreach ($types as $movementType)
                        <option value="{{ $movementType }}">
                            @if ($movementType === 'sale') Point of Sale
                            @elseif ($movementType === 'stock_in') Stock-In
                            @elseif ($movementType === 'physical_adjustment') Count Adj.
                            @else {{ ucwords(str_replace('_', ' ', $movementType)) }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="w-28">
                <select wire:model.live="brandId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-28">
                <select wire:model.live="categoryId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-28">
                <select wire:model.live="userId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Users</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-1 text-xs text-slate-600">
                <span class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">From:</span>
                <input wire:model.live="dateFrom" type="date" class="w-28 rounded border border-slate-300 px-1.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <div class="flex items-center gap-1 text-xs text-slate-600">
                <span class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">To:</span>
                <input wire:model.live="dateTo" type="date" class="w-28 rounded border border-slate-300 px-1.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <button wire:click="resetFilters" type="button" class="text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium ml-auto whitespace-nowrap">
                Reset
            </button>
        </div>

        <!-- Dashboard-styled Grid Table -->
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">SKU</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">Product Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Unit</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">Brand</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-24 whitespace-nowrap">Date</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20 whitespace-nowrap">Time</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28 whitespace-nowrap">Type</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Change</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20 whitespace-nowrap">Before</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20 whitespace-nowrap">After</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Reference</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">User</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-12 whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($movements as $movement)
                        @php
                            $change = (float) $movement->quantity_change;
                            $unit = $movement->product?->packageUnit?->abbreviation ?? 'pcs';
                            $refText = $movement->reference_text ? str_replace('#', '', $movement->reference_text) : ($movement->reference_type ? class_basename($movement->reference_type) . ' ' . $movement->reference_id : '—');
                        @endphp
                        <tr 
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $movement->id }}, sku: '{{ $movement->product?->sku ?? '' }}', name: '{{ addslashes($movement->product?->name ?? '') }}', reference: '{{ addslashes($refText) }}', type: '{{ $movement->type }}' })"
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="movement-{{ $movement->id }}"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap font-mono">
                                {{ $movement->product?->sku ?? '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $movement->product?->name }}">
                                {{ $movement->product?->name ?? 'Unknown Product' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                {{ $unit }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $movement->product?->brand?->name ?? '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $movement->created_at->format('M d, Y') }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $movement->created_at->format('h:i A') }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($movement->type === 'stock_in')
                                    <span class="text-[10px] font-bold uppercase text-emerald-700">Stock-In</span>
                                @elseif ($movement->type === 'sale')
                                    <span class="text-[10px] font-bold uppercase text-blue-700">Sale</span>
                                @elseif ($movement->type === 'physical_adjustment')
                                    <span class="text-[10px] font-bold uppercase text-amber-700">Count Adj.</span>
                                @elseif ($movement->type === 'mixing')
                                    <span class="text-[10px] font-bold uppercase text-purple-700">Mixing</span>
                                @else
                                    <span class="text-[10px] font-bold uppercase text-slate-700">{{ str_replace('_', ' ', $movement->type) }}</span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $change > 0 ? 'text-emerald-700' : ($change < 0 ? 'text-rose-600' : 'text-slate-500') }}">
                                {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                {{ number_format((float) $movement->quantity_before, 2) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                {{ number_format((float) $movement->quantity_after, 2) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700">
                                {{ $refText }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $movement->user?->name ?? 'System' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $movement->id }}, sku: '{{ $movement->product?->sku ?? '' }}', name: '{{ addslashes($movement->product?->name ?? '') }}', reference: '{{ addslashes($refText) }}', type: '{{ $movement->type }}' })"
                                    type="button" 
                                    class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                    title="Options"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                <div class="mx-auto flex flex-col items-center justify-center">
                                    <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                    <p class="text-xs font-semibold text-slate-700">No inventory movements match your filter criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $movements->links() }}
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
            @click="if (contextMenu.item?.sku) { copyToClipboard(contextMenu.item.sku); } contextMenu.close();" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            <span>Copy SKU</span>
        </button>
        <button 
            @click="if (contextMenu.item?.reference && contextMenu.item.reference !== '—') { copyToClipboard(contextMenu.item.reference); } contextMenu.close();" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>Copy Reference</span>
        </button>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item?.sku) { $wire.set('search', contextMenu.item.sku); } contextMenu.close();" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <span>Filter by this SKU</span>
        </button>
        <button 
            @click="if (contextMenu.item?.type) { $wire.set('type', contextMenu.item.type); } contextMenu.close();" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
            <span>Filter by this Type</span>
        </button>
    </div>
</div>
