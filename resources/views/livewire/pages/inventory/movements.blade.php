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

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('reports.inventory') }}" class="text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-600">Inventory</a>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Movements</span>
            </div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Movements</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('inventory.stock-in') }}" class="inline-flex items-center rounded bg-[#00a3cc] px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Stock In
            </a>
            <a href="{{ route('inventory.physical-count') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                Physical Count
            </a>
        </div>
    </div>

    <!-- Quick Summary KPI Cards (Right-aligned numbers, bigger light font, neutral labels) -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Filtered Inflow (Stock-In)</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-emerald-700 mt-2 text-right">+{{ number_format($totalIn, 3) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Units added</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Filtered Outflow (Sales / Deductions)</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-rose-700 mt-2 text-right">-{{ number_format($totalOut, 3) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Units deducted</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Total Movement Events</p>
            <h3 class="tabular-nums text-3xl sm:text-4xl font-light text-slate-900 mt-2 text-right">{{ number_format($movements->total()) }}</h3>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Recorded entries</p>
        </div>
    </div>

    <!-- Main Workspace with Left Filter Panel & Grid Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filters</span>
                <button wire:click="resetFilters" type="button" class="text-[11px] text-[#00a3cc] hover:text-[#008fb3] underline font-medium">
                    Reset
                </button>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Product / SKU</label>
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Search name, SKU..." 
                    class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                />
            </div>

            <!-- Movement Type Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Movement Type</label>
                <select wire:model.live="type" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Types</option>
                    @foreach ($types as $movementType)
                        <option value="{{ $movementType }}">
                            @if ($movementType === 'sale') Point of Sale (Deduction)
                            @elseif ($movementType === 'stock_in') Stock-In (Addition)
                            @elseif ($movementType === 'physical_adjustment') Physical Count Adjustment
                            @else {{ strtoupper(str_replace('_', ' ', $movementType)) }}
                            @endif
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Brand Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Brand</label>
                <select wire:model.live="brandId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Category Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
                <select wire:model.live="categoryId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- User Filter -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">User</label>
                <select wire:model.live="userId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Users</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ ucfirst($user->role) }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Date From -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">From Date</label>
                <input wire:model.live="dateFrom" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- Date To -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">To Date</label>
                <input wire:model.live="dateTo" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
        </aside>

        <!-- Movements Grid Table Area -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Date</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20">Time</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">Product Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Category</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Type</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-16">Unit</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Change</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Before</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20">After</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Reference</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($movements as $movement)
                        @php
                            $change = (float) $movement->quantity_change;
                            $unit = $movement->product?->packageUnit?->abbreviation ?? 'pcs';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors" wire:key="movement-{{ $movement->id }}">
                            <!-- Separate Date Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-800 whitespace-nowrap">
                                {{ $movement->created_at->format('M d, Y') }}
                            </td>
                            <!-- Separate Time Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                {{ $movement->created_at->format('h:i A') }}
                            </td>
                            <!-- Separate Brand Column (Brand before Name) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $movement->product?->brand?->name ?? '—' }}
                            </td>
                            <!-- Product Name Column (Accommodates long names) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $movement->product?->name }}">
                                {{ $movement->product?->name ?? 'Unknown Product' }}
                            </td>
                            <!-- Separate SKU Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                {{ $movement->product?->sku ?? '—' }}
                            </td>
                            <!-- Separate Category Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $movement->product?->category?->name ?? '—' }}
                            </td>
                            <!-- Movement Type Minimal Badge -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($movement->type === 'stock_in')
                                    <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Stock-In
                                    </span>
                                @elseif ($movement->type === 'sale')
                                    <span class="inline-block rounded border border-blue-600 text-blue-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Sale
                                    </span>
                                @elseif ($movement->type === 'physical_adjustment')
                                    <span class="inline-block rounded border border-amber-600 text-amber-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Count Adj.
                                    </span>
                                @else
                                    <span class="inline-block rounded border border-slate-600 text-slate-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        {{ str_replace('_', ' ', $movement->type) }}
                                    </span>
                                @endif
                            </td>
                            <!-- Package Unit -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $unit }}
                            </td>
                            <!-- Quantity Change with explicit +/- -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $change > 0 ? 'text-emerald-700' : ($change < 0 ? 'text-rose-600' : 'text-slate-500') }}">
                                {{ $change > 0 ? '+' : '' }}{{ number_format($change, 3) }}
                            </td>
                            <!-- Quantity Before -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                {{ number_format((float) $movement->quantity_before, 3) }}
                            </td>
                            <!-- Quantity After -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                {{ number_format((float) $movement->quantity_after, 3) }}
                            </td>
                            <!-- Reference / Notes -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700">
                                {{ $movement->reference_text ?: ($movement->reference_type ? class_basename($movement->reference_type) . ' #' . $movement->reference_id : '—') }}
                            </td>
                            <!-- User -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $movement->user?->name ?? 'System' }}
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
</div>
</div>
