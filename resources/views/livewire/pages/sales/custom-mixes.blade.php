<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all'; // 'all', 'downpaid', 'mixed', 'completed'
    public string $brandFilter = '';
    public int $perPage = 15;

    // Mix Formula & Price Modal (Mixer stage)
    public bool $showFormulaModal = false;
    public ?int $selectedTransactionId = null;
    public string $formulaProductId = '';
    public array $formulaComponents = [];


    // New Mix Order Modal (Downpayment stage)
    public bool $showNewMixModal = false;
    public string $newCustomerName = '';
    public string $newCustomerContact = '';
    public string $newBrandId = '';
    public string $newCustomColor = '';
    public string $newQuantity = '1';
    public string $newResultUnit = 'gal';
    public string $newNotes = '';
    public string $newDownpaymentAmount = '';
    public string $newTenderedAmount = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBrandFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    // ── 1. Downpayment / Order Stage ──────────────────────────────────────────

    public function openNewMixModal(): void
    {
        $this->resetNewMixForm();
        $this->showNewMixModal = true;
    }

    public function resetNewMixForm(): void
    {
        $this->showNewMixModal = false;
        $this->newCustomerName = '';
        $this->newCustomerContact = '';
        $this->newBrandId = '';
        $this->newCustomColor = '';
        $this->newQuantity = '1';
        $this->newResultUnit = 'gal';
        $this->newNotes = '';
        $this->newDownpaymentAmount = '';
        $this->newTenderedAmount = '';
        $this->resetValidation();
    }

    public function saveNewMixDownpayment(): void
    {
        $this->validate([
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerContact' => ['required', 'string', 'max:255'],
            'newBrandId' => ['required', 'exists:brands,id'],
            'newCustomColor' => ['required', 'string', 'max:255'],
            'newQuantity' => ['required', 'numeric', 'gt:0'],
            'newResultUnit' => ['required', 'in:gal,L'],
            'newDownpaymentAmount' => ['required', 'numeric', 'min:1'],
        ], [
            'newCustomerName.required' => 'Customer name is required.',
            'newCustomerContact.required' => 'Customer contact number is required.',
            'newBrandId.required' => 'Please select the brand.',
            'newCustomColor.required' => 'Custom color name or code is required.',
            'newDownpaymentAmount.required' => 'A downpayment is required before mixing begins.',
            'newDownpaymentAmount.min' => 'Downpayment must be at least ₱1.00.',
        ]);

        $brand = Brand::findOrFail($this->newBrandId);
        $qty = (float) $this->newQuantity;
        $unit = $this->newResultUnit;
        $downpayment = round((float) $this->newDownpaymentAmount, 2);

        session([
            'pos.cart' => [
                'mix:' . Str::uuid() => [
                    'type' => 'custom_mix',
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'brand' => $brand->name,
                    'custom_color' => trim($this->newCustomColor),
                    'description' => 'Custom Mix: ' . trim($this->newCustomColor) . ' (' . $brand->name . ')',
                    'package' => $qty . ' ' . $unit,
                    'unit' => $unit,
                    'sku' => 'CUSTOM-MIX',
                    'resulting_quantity' => $qty,
                    'resulting_unit' => $unit,
                    'quantity' => $qty,
                    'price_basis' => $downpayment,
                    'unit_price' => $downpayment,
                    'subtotal' => $downpayment,
                    'notes' => trim($this->newNotes),
                ],
            ],
            'pos.customer_name' => trim($this->newCustomerName),
            'pos.customer_contact' => trim($this->newCustomerContact),
            'pos.downpayment_amount' => (string) $downpayment,
        ]);

        $this->resetNewMixForm();
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot process downpayment checkout.');
        $this->redirectRoute('sales.checkout');
    }

    public function proceedToCollectBalance(int $saleId): void
    {
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot process balance checkout.');

        session([
            'pos.collect_mix_sale_id' => $saleId,
        ]);
        $this->redirectRoute('sales.checkout');
    }

    // ── 2. Mixer Stage: Log Formula & Determine Price ─────────────────────────

    public function openFormulaModal(int $transactionId): void
    {
        $tx = MixingTransaction::with(['brand', 'sale', 'components.product'])->findOrFail($transactionId);
        $this->selectedTransactionId = $transactionId;
        $this->formulaProductId = '';
        $this->formulaComponents = [];

        foreach ($tx->components as $c) {
            if ($c->product) {
                $this->formulaComponents[] = [
                    'product_id' => $c->product_id,
                    'product_name' => $c->product->name,
                    'sku' => $c->product->sku,
                    'price' => (float) $c->product->selling_price,
                ];
            }
        }

        $this->showFormulaModal = true;
    }

    public function closeFormulaModal(): void
    {
        $this->showFormulaModal = false;
        $this->selectedTransactionId = null;
        $this->formulaProductId = '';
        $this->formulaComponents = [];
        $this->resetValidation();
    }

    public function addFormulaComponent(): void
    {
        $tx = MixingTransaction::findOrFail($this->selectedTransactionId);

        $this->validate([
            'formulaProductId' => ['required', 'exists:products,id'],
        ], [
            'formulaProductId.required' => 'Select a material to add to the formula.',
        ]);

        $product = Product::with('brand')->findOrFail($this->formulaProductId);

        if ($product->brand_id != $tx->brand_id) {
            $this->addError('formulaProductId', 'Selected material must be of brand ' . ($tx->brand?->name ?? '') . '.');
            return;
        }

        if (collect($this->formulaComponents)->contains('product_id', $product->id)) {
            $this->addError('formulaProductId', 'This material is already in the formula list.');
            return;
        }

        $this->formulaComponents[] = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'price' => (float) $product->selling_price,
        ];

        $this->formulaProductId = '';
    }

    public function removeFormulaComponent(int $index): void
    {
        unset($this->formulaComponents[$index]);
        $this->formulaComponents = array_values($this->formulaComponents);
    }

    public function formulaBasisProduct(): ?array
    {
        if (empty($this->formulaComponents)) {
            return null;
        }

        return collect($this->formulaComponents)->sortByDesc('price')->first();
    }

    public function calculateFormulaTotal(): float
    {
        $basis = $this->formulaBasisProduct();
        if (! $basis) {
            return 0.0;
        }

        $tx = MixingTransaction::find($this->selectedTransactionId);
        if (! $tx) {
            return 0.0;
        }

        $qty = (float) $tx->resulting_quantity;
        $price = (float) $basis['price'];

        if ($tx->resulting_unit === 'L') {
            return round(($price / 4) * $qty, 2);
        }

        return round($price * $qty, 2);
    }

    public function saveFormula(): void
    {
        $this->validate([
            'formulaComponents' => ['required', 'array', 'min:1'],
        ], [
            'formulaComponents.min' => 'Add at least one material used in the mixture.',
        ]);

        $tx = MixingTransaction::with('sale')->findOrFail($this->selectedTransactionId);
        $sale = $tx->sale;

        $basis = $this->formulaBasisProduct();
        $basisProduct = Product::findOrFail($basis['product_id']);
        $total = $this->calculateFormulaTotal();
        $downpayment = (float) $sale->downpayment_amount;
        $balance = max(0, round($total - $downpayment, 2));

        DB::transaction(function () use ($tx, $sale, $basisProduct, $basis, $total, $balance) {
            // Re-sync components
            $tx->components()->delete();
            foreach ($this->formulaComponents as $comp) {
                $tx->components()->create([
                    'product_id' => $comp['product_id'],
                    'container_id' => null,
                ]);
                Product::where('id', $comp['product_id'])->update(['requires_physical_check' => true]);
            }

            $tx->update([
                'price_basis_product_id' => $basisProduct->id,
                'price_basis' => (float) $basisProduct->selling_price,
                'status' => 'mixed',
            ]);

            $unitPrice = $tx->resulting_unit === 'L' 
                ? round((float) $basisProduct->selling_price / 4, 2) 
                : (float) $basisProduct->selling_price;

            $sale->update([
                'subtotal' => $total,
                'total' => $total,
                'balance_due' => $balance,
                'payment_status' => $balance > 0 ? 'downpayment_paid' : 'paid',
            ]);

            $sale->items()->delete();
            $sale->items()->create([
                'product_id' => null,
                'description' => 'Custom Mix: ' . $tx->custom_color . ' (' . ($tx->brand?->name ?? '') . ') - ' . $tx->resulting_quantity . ' ' . $tx->resulting_unit,
                'quantity' => (float) $tx->resulting_quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $total,
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'custom_mix_formula_logged',
                'auditable_type' => MixingTransaction::class,
                'auditable_id' => $tx->id,
                'context' => [
                    'invoice_number' => $sale->invoice_number,
                    'price_basis' => $basisProduct->selling_price,
                    'total' => $total,
                    'balance_due' => $balance,
                    'materials_count' => count($this->formulaComponents),
                ],
            ]);
        });

        $this->closeFormulaModal();
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Formula logged for order #{$sale->invoice_number}. Order is marked ready for pickup.",
        ]);
    }



    public function render(): mixed
    {
        $query = MixingTransaction::query()
            ->with(['sale.user', 'brand', 'priceBasisProduct', 'components.product'])
            ->whereHas('sale')
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('custom_color', 'like', '%' . $this->search . '%')
                        ->orWhereHas('sale', function ($sq) {
                            $sq->where('invoice_number', 'like', '%' . $this->search . '%')
                                ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                                ->orWhere('customer_contact', 'like', '%' . $this->search . '%');
                        });
                });
            })
            ->when($this->brandFilter, fn ($q) => $q->where('brand_id', $this->brandFilter));

        if ($this->statusFilter === 'downpaid') {
            $query->whereIn('status', ['downpaid', 'pending_mix']);
        } elseif ($this->statusFilter === 'mixed') {
            $query->whereIn('status', ['mixed', 'ready_for_pickup']);
        } elseif ($this->statusFilter === 'completed') {
            $query->whereIn('status', ['completed', 'released']);
        }

        $mixes = $query->latest()->paginate($this->perPage);

        // Status counts
        $counts = [
            'all' => MixingTransaction::count(),
            'downpaid' => MixingTransaction::whereIn('status', ['downpaid', 'pending_mix'])->count(),
            'mixed' => MixingTransaction::whereIn('status', ['mixed', 'ready_for_pickup'])->count(),
            'completed' => MixingTransaction::whereIn('status', ['completed', 'released'])->count(),
        ];

        // Active Transaction for formula modal
        $activeTx = $this->selectedTransactionId ? MixingTransaction::with(['brand', 'sale'])->find($this->selectedTransactionId) : null;
        $brandMaterials = [];
        if ($activeTx && $activeTx->brand_id) {
            $brandMaterials = Product::where('active', true)
                ->where('brand_id', $activeTx->brand_id)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price']);
        }

        return view('livewire.pages.sales.custom-mixes', [
            'mixes' => $mixes,
            'counts' => $counts,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'activeTx' => $activeTx,
            'brandMaterials' => $brandMaterials,
            'currency' => Currency::class,
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
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Custom Paint Mix Queue</h1>
        </div>
        @if (auth()->user()?->canCheckout())
            <div class="flex items-center gap-2">
                <button wire:click="openNewMixModal" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-xs transition">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>New Mix Order</span>
                </button>
            </div>
        @endif
    </div>

    <!-- Table Section with Filter & Showing Count (space-y-2) -->
    <div class="space-y-2">
        <!-- Filter Controls Outside the Table Div -->
        <div class="flex flex-wrap items-center gap-2.5">
            <div class="w-64">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search invoice, customer, color..."
                    class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>
            <div class="w-40">
                <select wire:model.live="statusFilter" class="w-full h-9 rounded border border-slate-300 bg-white pl-2.5 pr-8 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Statuses</option>
                    <option value="downpaid">Awaiting Mix</option>
                    <option value="mixed">Ready for Pickup</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="w-44">
                <select wire:model.live="brandFilter" class="w-full h-9 rounded border border-slate-300 bg-white pl-2.5 pr-8 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Brands</option>
                    @foreach ($brands as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($search || $brandFilter || $statusFilter !== 'all')
                <button wire:click="$set('search', ''); $set('brandFilter', ''); $set('statusFilter', 'all');" type="button"
                    class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                    Reset
                </button>
            @endif
        </div>

        <!-- Showing X of N Items Indicator & Per Page Selector -->
        <div class="flex items-center justify-between text-xs text-slate-500">
            <div>
                @if ($mixes && $mixes->total() > 0)
                    Showing <span class="font-medium text-slate-700">{{ $mixes->firstItem() }}</span> to
                    <span class="font-medium text-slate-700">{{ $mixes->lastItem() }}</span> of <span
                        class="font-medium text-slate-700">{{ $mixes->total() }}</span> items
                @else
                    Showing <span class="font-medium text-slate-700">0</span> items
                @endif
            </div>
            <div class="flex items-center gap-1.5">
                <label for="mixesPerPage" class="text-xs text-slate-500">Show:</label>
                <select wire:model.live="perPage" id="mixesPerPage"
                    class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-xs text-slate-500">items</span>
            </div>
        </div>

        <!-- Main Table Container -->
        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 uppercase text-[10px] font-bold text-slate-700 tracking-wider">
                    <tr>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">Invoice #</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left w-32 whitespace-nowrap">Date / Time</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[120px] whitespace-nowrap">Customer</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[110px] whitespace-nowrap">Contact #</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24 whitespace-nowrap">Brand</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[130px]">Color Name</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-20 whitespace-nowrap">Volume</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Total</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Downpayment</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Balance</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-28 whitespace-nowrap">Status</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($mixes as $mix)
                        @php
                            $sale = $mix->sale;
                            $isDownpaid = in_array($mix->status, ['downpaid', 'pending_mix']);
                            $isMixed = in_array($mix->status, ['mixed', 'ready_for_pickup']);
                            $isCompleted = in_array($mix->status, ['completed', 'released']);
                        @endphp
                        <tr 
                            class="hover:bg-slate-50 transition cursor-default" 
                            wire:key="mix-{{ $mix->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $mix->id }}, saleId: {{ $sale?->id ?? 'null' }}, status: '{{ $mix->status }}', isDownpaid: {{ $isDownpaid ? 'true' : 'false' }}, isMixed: {{ $isMixed ? 'true' : 'false' }}, isCompleted: {{ $isCompleted ? 'true' : 'false' }}, invoice: '{{ $sale?->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale?->id ?? 0]) }}' })"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-bold text-slate-900 whitespace-nowrap">
                                {{ $sale?->invoice_number ?? '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $sale?->sold_at ? $sale->sold_at->format('M d, Y h:i A') : '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 whitespace-nowrap">
                                {{ $sale?->customer_name ?? 'Walk-in' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                {{ $sale?->customer_contact ?: '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $mix->brand?->name ?? '—' }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 font-bold text-slate-900">
                                {{ $mix->custom_color }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center font-medium text-slate-900 whitespace-nowrap">
                                {{ rtrim(rtrim(number_format((float) $mix->resulting_quantity, 2), '0'), '.') }} {{ $mix->resulting_unit }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap font-semibold text-slate-900">
                                @if ($isDownpaid)
                                    <span class="text-slate-400 italic font-normal text-[11px]">Pending mix</span>
                                @else
                                    {{ $currency::format($sale?->total ?? 0) }}
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap text-slate-700">
                                {{ $currency::format($sale?->downpayment_amount ?? 0) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap text-slate-700">
                                @if ($isDownpaid)
                                    <span class="text-slate-400 italic text-[11px]">TBD</span>
                                @elseif ((float) ($sale?->balance_due ?? 0) > 0)
                                    <span class="font-medium text-slate-900">{{ $currency::format($sale->balance_due) }}</span>
                                @else
                                    <span class="text-slate-500">₱0.00</span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($isDownpaid)
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-amber-700">
                                        Awaiting Mix
                                    </span>
                                @elseif ($isMixed)
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-sky-700">
                                        Ready for Pickup
                                    </span>
                                @else
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">
                                        Completed
                                    </span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5 justify-center">
                                    
                                    <button 
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $mix->id }}, saleId: {{ $sale?->id ?? 'null' }}, status: '{{ $mix->status }}', isDownpaid: {{ $isDownpaid ? 'true' : 'false' }}, isMixed: {{ $isMixed ? 'true' : 'false' }}, isCompleted: {{ $isCompleted ? 'true' : 'false' }}, invoice: '{{ $sale?->invoice_number }}', receiptUrl: '{{ route('sales.receipt', ['sale' => $sale?->id ?? 0]) }}' })" 
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="Actions"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="19" r="1.5" fill="currentColor" stroke="none" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                <p class="font-medium">No custom mix orders found.</p>
                                <p class="text-[11px] mt-1 text-slate-400">Customer requests with downpayments will appear here.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($mixes->hasPages())
            <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                <div>
                    <span class="text-slate-500">
                        Page <span class="font-medium text-slate-800">{{ $mixes->currentPage() }}</span> of
                        <span class="font-medium text-slate-800">{{ $mixes->lastPage() }}</span>
                    </span>
                </div>
                <div class="inline-flex items-center gap-1">
                    <button wire:click="previousPage" type="button" @disabled($mixes->onFirstPage())
                        class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                        Prev
                    </button>

                    @php
                        $currentPage = $mixes->currentPage();
                        $lastPage = $mixes->lastPage();
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($lastPage, $currentPage + 2);
                    @endphp

                    @if ($startPage > 1)
                        <button wire:click="gotoPage(1)" type="button"
                            class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                            1
                        </button>
                        @if ($startPage > 2)
                            <span class="px-1 text-slate-400">...</span>
                        @endif
                    @endif

                    @for ($page = $startPage; $page <= $endPage; $page++)
                        @if ($page === $currentPage)
                            <span
                                class="min-w-[28px] h-7 px-2 text-xs font-semibold rounded bg-slate-900 text-white flex items-center justify-center">
                                {{ $page }}
                            </span>
                        @else
                            <button wire:click="gotoPage({{ $page }})" type="button"
                                class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                {{ $page }}
                            </button>
                        @endif
                    @endfor

                    @if ($endPage < $lastPage)
                        @if ($endPage < $lastPage - 1)
                            <span class="px-1 text-slate-400">...</span>
                        @endif
                        <button wire:click="gotoPage({{ $lastPage }})" type="button"
                            class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                            {{ $lastPage }}
                        </button>
                    @endif

                    <button wire:click="nextPage" type="button" @disabled(!$mixes->hasMorePages())
                        class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                        Next
                    </button>
                </div>
            </div>
        @endif
        </div>
    </div>

    <!-- ── MODAL 1: Mix & Set Price (Mixer logs formula) ───────────────────── -->
    @if ($showFormulaModal && $activeTx)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-2xl rounded border border-slate-300 bg-white shadow-xl text-xs overflow-hidden flex flex-col max-h-[90vh]">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2.5 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">Log Mixing Formula &amp; Price</h2>
                        <p class="text-[11px] text-slate-500">Invoice: {{ $activeTx->sale?->invoice_number }} - {{ $activeTx->brand?->name }}</p>
                    </div>
                    <button wire:click="closeFormulaModal" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>

                <div class="p-4 overflow-y-auto space-y-3.5 flex-1">
                    <!-- Order Specs Banner -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 rounded border border-slate-200 bg-slate-50 p-2.5 text-[11px]">
                        <div>
                            <span class="text-slate-500 block">Customer:</span>
                            <span class="font-semibold text-slate-900">{{ $activeTx->sale?->customer_name }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Brand:</span>
                            <span class="font-semibold text-slate-900">{{ $activeTx->brand?->name }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Color:</span>
                            <span class="font-semibold text-slate-900">{{ $activeTx->custom_color }}</span>
                        </div>
                        <div>
                            <span class="text-slate-500 block">Volume:</span>
                            <span class="font-semibold text-slate-900">{{ $activeTx->resulting_quantity }} {{ $activeTx->resulting_unit }}</span>
                        </div>
                    </div>

                    <!-- Material Selector (Brand strictly enforced) -->
                    <div class="space-y-1">
                        <label class="block font-semibold text-slate-700">
                            Add Material Used ({{ $activeTx->brand?->name }} products only)
                        </label>
                        <div class="flex gap-2">
                            <select wire:model="formulaProductId" class="flex-1 rounded border-slate-300 text-xs py-1.5 focus:border-[#00a3cc] focus:ring-0">
                                <option value="">-- Select material used --</option>
                                @foreach ($brandMaterials as $mat)
                                    <option value="{{ $mat->id }}">
                                        {{ $mat->sku }} - {{ $mat->name }} ({{ $currency::format($mat->selling_price) }}/gal)
                                    </option>
                                @endforeach
                            </select>
                            <button wire:click="addFormulaComponent" type="button"
                                class="px-3.5 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] transition shadow-xs">
                                + Add
                            </button>
                        </div>
                        @error('formulaProductId') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                        @error('formulaComponents') <p class="text-rose-600 text-[11px]">{{ $message }}</p> @enderror
                    </div>

                    <!-- Materials Table -->
                    <div>
                        <span class="block font-semibold text-slate-700 mb-1">
                            Materials in Formula ({{ count($formulaComponents) }})
                        </span>
                        <div class="rounded border border-slate-200 overflow-hidden">
                            <table class="w-full border-collapse text-left text-xs">
                                <thead class="bg-slate-100 text-[10px] font-bold uppercase text-slate-700 tracking-wider border-b border-slate-200">
                                    <tr>
                                        <th class="px-2.5 py-1.5">SKU</th>
                                        <th class="px-2.5 py-1.5">Material</th>
                                        <th class="px-2.5 py-1.5 text-right">Gallon Price</th>
                                        <th class="px-2.5 py-1.5 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @php
                                        $basis = $this->formulaBasisProduct();
                                    @endphp
                                    @forelse ($formulaComponents as $idx => $comp)
                                        @php
                                            $isBasis = $basis && $basis['product_id'] == $comp['product_id'];
                                        @endphp
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-2.5 py-1.5 font-mono text-slate-600">{{ $comp['sku'] }}</td>
                                            <td class="px-2.5 py-1.5 text-slate-900 font-medium">
                                                {{ $comp['product_name'] }}
                                                @if ($isBasis)
                                                    <span class="ml-1.5 rounded bg-amber-100 text-amber-800 px-1 py-0.2 text-[9px] font-bold">Highest / Basis</span>
                                                @endif
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right tabular-nums text-slate-800 font-semibold">
                                                {{ $currency::format($comp['price']) }}
                                            </td>
                                            <td class="px-2.5 py-1.5 text-center">
                                                <button wire:click="removeFormulaComponent({{ $idx }})" type="button"
                                                    class="text-slate-400 hover:text-rose-600">&times;</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-4 py-6 text-center text-slate-400">
                                                No materials added yet. Add the products used in this mix.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Derived Pricing Summary -->
                    @if ($basis)
                        @php
                            $calculatedTotal = $this->calculateFormulaTotal();
                            $downpayment = (float) $activeTx->sale?->downpayment_amount;
                            $balance = max(0, round($calculatedTotal - $downpayment, 2));
                        @endphp
                        <div class="rounded border border-slate-200 bg-slate-50 p-3 space-y-1 text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>Price Basis (Highest Component):</span>
                                <span class="font-bold text-slate-900 tabular-nums">{{ $currency::format($basis['price']) }}/gal</span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Total Price ({{ $activeTx->resulting_quantity }} {{ $activeTx->resulting_unit }}):</span>
                                <span class="font-bold text-slate-900 tabular-nums">{{ $currency::format($calculatedTotal) }}</span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Less Downpayment Paid:</span>
                                <span class="font-semibold text-slate-700 tabular-nums">-{{ $currency::format($downpayment) }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-1.5 mt-1">
                                <span>Balance Due upon Pickup:</span>
                                <span class="text-rose-700 tabular-nums">{{ $currency::format($balance) }}</span>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 flex justify-end gap-2">
                    <button wire:click="closeFormulaModal" type="button"
                        class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button wire:click="saveFormula" type="button"
                        class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-xs transition disabled:opacity-50"
                        {{ empty($formulaComponents) ? 'disabled' : '' }}>
                        Save Formula &amp; Mark Ready
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ── MODAL 3: New Custom Mix Order (Downpayment stage) ────────────────── -->
    @if ($showNewMixModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded border border-slate-300 bg-white shadow-xl text-xs overflow-hidden flex flex-col max-h-[90vh]">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2.5 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">New Custom Mix Order</h2>
                        <p class="text-[11px] text-slate-500">Collect downpayment to queue mixing</p>
                    </div>
                    <button wire:click="resetNewMixForm" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>

                <form wire:submit="saveNewMixDownpayment" class="p-4 overflow-y-auto space-y-3 flex-1">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Customer Name <span class="text-rose-600">*</span></label>
                            <input wire:model="newCustomerName" type="text" placeholder="Full name"
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                            @error('newCustomerName') <p class="text-rose-600 text-[10px]">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Contact Number <span class="text-rose-600">*</span></label>
                            <input wire:model="newCustomerContact" type="text" placeholder="09XX..."
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                            @error('newCustomerContact') <p class="text-rose-600 text-[10px]">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Brand to Use <span class="text-rose-600">*</span></label>
                            <select wire:model="newBrandId" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                                <option value="">-- Select brand --</option>
                                @foreach ($brands as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                            @error('newBrandId') <p class="text-rose-600 text-[10px]">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Custom Color / Code <span class="text-rose-600">*</span></label>
                            <input wire:model="newCustomColor" type="text" placeholder="e.g. Sky Blue #B-101"
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                            @error('newCustomColor') <p class="text-rose-600 text-[10px]">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Quantity <span class="text-rose-600">*</span></label>
                            <input wire:model="newQuantity" type="number" step="0.25" min="0.25"
                                class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-slate-500 focus:ring-0" />
                            @error('newQuantity') <p class="text-rose-600 text-[10px]">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Unit <span class="text-rose-600">*</span></label>
                            <select wire:model="newResultUnit" class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                                <option value="gal">Gallon (gal)</option>
                                <option value="L">Liter (L)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Notes</label>
                        <input wire:model="newNotes" type="text" placeholder="Special customer instructions..."
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                    </div>

                    <div class="pt-1 border-t border-slate-200">
                        <label class="block font-semibold text-slate-700 mb-1">Downpayment (₱) <span class="text-rose-600">*</span></label>
                        <input wire:model.live.debounce.150ms="newDownpaymentAmount" type="number" step="0.01" min="1" placeholder="0.00"
                            class="w-full rounded border-slate-300 text-xs font-bold tabular-nums focus:border-slate-500 focus:ring-0" />
                        @error('newDownpaymentAmount') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                        <button wire:click="resetNewMixForm" type="button"
                            class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-xs transition">
                            Proceed to Downpayment Checkout
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Floating Context Menu -->
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
        class="w-56 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <template x-if="contextMenu.item?.isDownpaid">
            <button 
                @click="if (contextMenu.item) { $wire.openFormulaModal(contextMenu.item.id); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
            >
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                <span>Mix &amp; Set Price</span>
            </button>
        </template>

        @if (auth()->user()?->canCheckout())
            <template x-if="contextMenu.item?.isMixed">
                <button 
                    @click="if (contextMenu.item?.saleId) { $wire.proceedToCollectBalance(contextMenu.item.saleId); contextMenu.close(); }"
                    type="button" 
                    class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
                >
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span>Pay Balance at POS Checkout</span>
                </button>
            </template>
        @endif

        <template x-if="contextMenu.item?.saleId">
            <a 
                :href="contextMenu.item?.receiptUrl"
                target="_blank"
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
            >
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span>View Downpayment Receipt</span>
            </a>
        </template>

        <template x-if="contextMenu.item?.invoice">
            <button 
                @click="navigator.clipboard.writeText(contextMenu.item.invoice); $dispatch('toast', { type: 'success', message: 'Invoice copied: ' + contextMenu.item.invoice }); contextMenu.close();"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
            >
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                <span>Copy Invoice #</span>
            </button>
        </template>
    </div>
</div>
