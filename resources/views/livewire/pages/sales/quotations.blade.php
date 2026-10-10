<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // Quotation detail modal
    public bool $showDetailModal = false;
    public ?int $viewingQuotationId = null;

    // Quotation edit modal
    public bool $showEditModal = false;
    public ?int $editingQuotationId = null;
    public string $editCustomerName = '';
    public string $editCustomerContact = '';
    public string $editValidUntil = '';
    public string $editNotes = '';
    public string $editStatus = 'draft';
    public string $editDiscountPercentage = '0';
    public string $editDiscountType = 'none';
    public string $editDiscountReason = '';
    public array $editItems = [];

    // Product search inside edit modal
    public string $productSearch = '';
    public array $productSearchResults = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewQuotations(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
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
        $this->reset(['search', 'status', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function viewDetails(int $id): void
    {
        $this->viewingQuotationId = $id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->viewingQuotationId = null;
    }

    public function openEditModal(int $id): void
    {
        $quote = Quotation::with(['items.product.packageUnit', 'items.product.brand'])->findOrFail($id);

        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Converted quotations cannot be edited.']);
            return;
        }

        $this->editingQuotationId = $quote->id;
        $this->editCustomerName = (string) ($quote->customer_name ?? '');
        $this->editCustomerContact = (string) ($quote->customer_contact ?? '');
        $this->editValidUntil = $quote->valid_until ? $quote->valid_until->format('Y-m-d') : '';
        $this->editNotes = (string) ($quote->notes ?? '');
        $this->editStatus = (string) $quote->status;
        $this->editDiscountPercentage = (string) ($quote->discount_percentage ?? '0');
        $this->editDiscountType = (string) ($quote->discount_type ?? 'none');
        $this->editDiscountReason = (string) ($quote->discount_reason ?? '');
        
        $this->editItems = [];
        foreach ($quote->items as $item) {
            $this->editItems[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku ?? ($item->product?->sku ?? 'CUSTOM-MIX'),
                'description' => $item->description,
                'package' => $item->package ?? ($item->product?->formattedPackage() ?? '—'),
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'mix_data' => $item->mix_data,
            ];
        }

        $this->productSearch = '';
        $this->productSearchResults = [];
        $this->showDetailModal = false;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingQuotationId = null;
        $this->editItems = [];
        $this->productSearch = '';
        $this->productSearchResults = [];
    }

    public function updatedProductSearch(): void
    {
        $query = trim($this->productSearch);
        if (strlen($query) < 1) {
            $this->productSearchResults = [];
            return;
        }

        $term = '%' . $query . '%';
        $this->productSearchResults = Product::query()
            ->with(['brand', 'packageUnit'])
            ->where('active', true)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('sku', 'like', $term);
            })
            ->limit(6)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand' => $p->brand?->name ?? '—',
                'package' => $p->formattedPackage(),
                'price' => (float) $p->selling_price,
            ])
            ->toArray();
    }

    public function addProductToEdit(int $productId): void
    {
        $p = Product::with(['brand', 'packageUnit'])->find($productId);
        if (! $p) {
            return;
        }

        foreach ($this->editItems as $index => $item) {
            if (($item['product_id'] ?? null) === $p->id) {
                $this->editItems[$index]['quantity'] += 1;
                $this->editItems[$index]['subtotal'] = round($this->editItems[$index]['quantity'] * $this->editItems[$index]['unit_price'], 2);
                $this->productSearch = '';
                $this->productSearchResults = [];
                return;
            }
        }

        $this->editItems[] = [
            'id' => null,
            'product_id' => $p->id,
            'sku' => $p->sku,
            'description' => $p->name,
            'package' => $p->formattedPackage(),
            'quantity' => 1.0,
            'unit_price' => (float) $p->selling_price,
            'subtotal' => (float) $p->selling_price,
            'mix_data' => null,
        ];

        $this->productSearch = '';
        $this->productSearchResults = [];
    }

    public function removeEditItem(int $index): void
    {
        if (isset($this->editItems[$index])) {
            unset($this->editItems[$index]);
            $this->editItems = array_values($this->editItems);
        }
    }

    public function updatedEditItems(): void
    {
        foreach ($this->editItems as $index => $item) {
            $qty = max(0.01, (float) ($item['quantity'] ?? 1));
            $price = max(0, (float) ($item['unit_price'] ?? 0));
            $this->editItems[$index]['quantity'] = $qty;
            $this->editItems[$index]['unit_price'] = $price;
            $this->editItems[$index]['subtotal'] = round($qty * $price, 2);
        }
    }

    public function getEditSubtotalProperty(): float
    {
        return (float) array_sum(array_column($this->editItems, 'subtotal'));
    }

    public function getEditDiscountAmountProperty(): float
    {
        $pct = max(0, min(100, (float) $this->editDiscountPercentage));
        return round($this->editSubtotal * ($pct / 100), 2);
    }

    public function getEditTotalProperty(): float
    {
        return max(0, round($this->editSubtotal - $this->editDiscountAmount, 2));
    }

    public function saveQuotationChanges(): void
    {
        $this->validate([
            'editCustomerName' => ['nullable', 'string', 'max:200'],
            'editCustomerContact' => ['nullable', 'string', 'max:100'],
            'editValidUntil' => ['nullable', 'date'],
            'editNotes' => ['nullable', 'string', 'max:1000'],
            'editStatus' => ['required', 'in:draft,sent,accepted,cancelled'],
            'editDiscountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'editItems' => ['required', 'array', 'min:1'],
            'editItems.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'editItems.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ((float) $this->editDiscountPercentage > 0 && empty($this->editDiscountReason)) {
            $this->editDiscountReason = 'Customer Discount';
        }

        $quote = Quotation::findOrFail($this->editingQuotationId);
        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Converted quotations cannot be modified.']);
            return;
        }

        $subtotal = $this->editSubtotal;
        $discountPct = (float) $this->editDiscountPercentage;
        $discountAmt = $this->editDiscountAmount;
        $total = $this->editTotal;

        DB::transaction(function () use ($quote, $subtotal, $discountPct, $discountAmt, $total) {
            $quote->update([
                'customer_name' => trim($this->editCustomerName) ?: null,
                'customer_contact' => trim($this->editCustomerContact) ?: null,
                'valid_until' => $this->editValidUntil ?: null,
                'notes' => trim($this->editNotes) ?: null,
                'status' => $this->editStatus,
                'subtotal' => $subtotal,
                'discount_percentage' => $discountPct,
                'discount_amount' => $discountAmt,
                'discount_type' => $discountPct > 0 ? $this->editDiscountType : null,
                'discount_reason' => $discountPct > 0 ? trim($this->editDiscountReason) : null,
                'discount_authorized_by' => (auth()->user()?->isManagerOrAbove() && $discountPct > 0) ? auth()->id() : $quote->discount_authorized_by,
                'total' => $total,
            ]);

            $quote->items()->delete();
            foreach ($this->editItems as $item) {
                $quote->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'package' => $item['package'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'subtotal' => (float) $item['subtotal'],
                    'mix_data' => $item['mix_data'] ?? null,
                ]);
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'quotation_updated',
                'auditable_type' => Quotation::class,
                'auditable_id' => $quote->id,
                'context' => [
                    'quote_number' => $quote->quote_number,
                    'total' => $total,
                    'items_count' => count($this->editItems),
                    'status' => $this->editStatus,
                ],
            ]);
        });

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} updated successfully."]);
        $this->showEditModal = false;
        $this->editingQuotationId = null;
        $this->editItems = [];
    }

    public function updateStatus(int $id, string $newStatus): void
    {
        $allowed = ['draft', 'sent', 'accepted', 'cancelled'];
        if (! in_array($newStatus, $allowed, true)) {
            return;
        }

        $quote = Quotation::findOrFail($id);
        $oldStatus = $quote->status;
        $quote->update(['status' => $newStatus]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => 'quotation_status_updated',
            'auditable_type' => Quotation::class,
            'auditable_id' => $quote->id,
            'context' => [
                'quote_number' => $quote->quote_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation status updated to {$newStatus}."]);
    }

    public function convertToSale(int $id): void
    {
        $quote = Quotation::with('items.product.packageUnit')->findOrFail($id);

        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'This quotation was already converted to an invoice.']);
            return;
        }

        $cart = [];
        foreach ($quote->items as $item) {
            if ($item->mix_data) {
                $mix = $item->mix_data;
                $key = 'mix:' . Str::uuid();
                $cart[$key] = array_merge($mix, [
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ]);
            } else {
                $product = $item->product;
                if (! $product) {
                    continue;
                }
                $key = 'product:' . $product->id;
                $packageLabel = $product->formattedPackage();
                $cart[$key] = [
                    'type' => 'normal',
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'package' => $packageLabel,
                    'brand' => $product->brand?->name ?? '—',
                    'unit' => $product->packageUnit?->abbreviation ?? 'unit',
                    'sku' => $product->sku,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ];
            }
        }

        session([
            'pos.cart' => $cart,
            'pos.quotation_id' => $quote->id,
            'pos.customer_name' => $quote->customer_name,
            'pos.customer_contact' => $quote->customer_contact,
            'pos.discount_percentage' => (string) ($quote->discount_percentage ?? '0'),
        ]);

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} loaded into POS cart."]);
        $this->redirectRoute('sales.index');
    }

    public function render(): mixed
    {
        $query = Quotation::query()
            ->with(['user', 'items', 'convertedSale'])
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('quote_number', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_contact', 'like', '%' . $this->search . '%')
                        ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->status, fn($q) => $q->where('status', $this->status))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest();

        $selectedQuotation = $this->viewingQuotationId
            ? Quotation::with(['user', 'items.product.packageUnit', 'convertedSale', 'discountAuthorizer'])->find($this->viewingQuotationId)
            : null;

        $stats = [
            'total' => Quotation::count(),
            'draft' => Quotation::where('status', 'draft')->count(),
            'sent' => Quotation::where('status', 'sent')->count(),
            'accepted' => Quotation::where('status', 'accepted')->count(),
            'total_value' => (float) Quotation::whereNotIn('status', ['cancelled'])->sum('total'),
        ];

        return view('livewire.pages.sales.quotations', [
            'quotations' => $query->paginate(15),
            'selectedQuotation' => $selectedQuotation,
            'stats' => $stats,
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
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Quotations</h1>
            <p class="text-[11px] text-slate-500 mt-0.5">Formal price quotes, specs, printable estimates, and cart conversions</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('sales.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>New Quote (via POS)</span>
            </a>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Total Quotes</span>
            <p class="text-2xl font-light tabular-nums text-slate-900 mt-1">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Draft / In Progress</span>
            <p class="text-2xl font-light tabular-nums text-slate-700 mt-1">{{ number_format($stats['draft']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-sky-700">Sent to Customer</span>
            <p class="text-2xl font-light tabular-nums text-sky-800 mt-1">{{ number_format($stats['sent']) }}</p>
        </div>
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Accepted / Converted</span>
            <p class="text-2xl font-light tabular-nums text-emerald-700 mt-1">{{ number_format($stats['accepted']) }}</p>
        </div>
    </div>

    <!-- Table Container with Horizontal Filter Bar Directly Above Table -->
    <div class="rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden">
        <!-- Horizontal Filter Bar -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <!-- Search -->
            <div class="flex-1 min-w-[200px]">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search quote #, customer, contact, user..."
                    class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Status Filter -->
            <div class="w-36">
                <select wire:model.live="status" class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="accepted">Accepted</option>
                    <option value="expired">Expired</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="w-36">
                <input wire:model.live="dateFrom" type="date" title="Created From"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Date To -->
            <div class="w-36">
                <input wire:model.live="dateTo" type="date" title="Created To"
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
            </div>

            <!-- Reset Button -->
            <button wire:click="resetFilters" type="button"
                class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                Reset
            </button>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Quote #</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Date Created</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left">Customer</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Items</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Total Due</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-left whitespace-nowrap">Valid Until</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Status</th>
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-24">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($quotations as $quote)
                        <tr 
                            class="hover:bg-slate-50 transition-colors cursor-default" 
                            wire:key="quote-row-{{ $quote->id }}"
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $quote->id }}, number: '{{ $quote->quote_number }}', status: '{{ $quote->status }}', canConvert: {{ !$quote->isConverted() && $quote->status !== 'cancelled' ? 'true' : 'false' }}, canEdit: {{ !$quote->isConverted() ? 'true' : 'false' }} })"
                        >
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 whitespace-nowrap">
                                <button wire:click="viewDetails({{ $quote->id }})" type="button" class="text-[#008fb3] hover:underline font-bold text-left">
                                    {{ $quote->quote_number }}
                                </button>
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                {{ $quote->created_at->format('M d, Y') }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800">
                                <div class="font-medium text-slate-900">{{ $quote->customer_name ?: 'Walk-in / Unspecified' }}</div>
                                @if ($quote->customer_contact)
                                    <div class="text-[10px] text-slate-500">{{ $quote->customer_contact }}</div>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700 whitespace-nowrap">
                                {{ $quote->items->count() }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right font-bold text-slate-900 tabular-nums whitespace-nowrap">
                                {{ $currency::format($quote->total) }}
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">
                                @if ($quote->valid_until)
                                    <span class="{{ $quote->isExpired() ? 'text-amber-600 font-semibold' : '' }}">
                                        {{ $quote->valid_until->format('M d, Y') }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @php
                                    $statusClasses = match ($quote->status) {
                                        'draft' => 'text-slate-600',
                                        'sent' => 'text-sky-700 font-bold',
                                        'accepted' => 'text-emerald-700 font-bold',
                                        'expired' => 'text-amber-700 font-bold',
                                        'cancelled' => 'text-rose-700 font-bold',
                                        default => 'text-slate-600',
                                    };
                                @endphp
                                <span class="text-[10px] font-bold uppercase tracking-wider {{ $statusClasses }}">
                                    {{ $quote->status }}
                                </span>
                            </td>
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    <button 
                                        wire:click="viewDetails({{ $quote->id }})" 
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-600 hover:text-slate-900 transition"
                                        title="View Details"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>

                                    @if (! $quote->isConverted())
                                        <button 
                                            wire:click="openEditModal({{ $quote->id }})" 
                                            type="button" 
                                            class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-600 hover:text-slate-900 transition"
                                            title="Edit Quotation"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                    @endif

                                    <a 
                                        href="{{ route('quotations.print', $quote->id) }}" 
                                        target="_blank"
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-600 hover:text-slate-900 transition"
                                        title="Print Formal Quotation"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                    </a>

                                    <button 
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $quote->id }}, number: '{{ $quote->quote_number }}', status: '{{ $quote->status }}', canConvert: {{ !$quote->isConverted() && $quote->status !== 'cancelled' ? 'true' : 'false' }}, canEdit: {{ !$quote->isConverted() ? 'true' : 'false' }} })" 
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="More Options"
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
                            <td colspan="8" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                No quotations found matching current filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($quotations->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $quotations->links() }}
            </div>
        @endif
    </div>

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
        class="w-52 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.viewDetails(contextMenu.item.id); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View Details</span>
        </button>

        <template x-if="contextMenu.item?.canEdit">
            <button 
                @click="if (contextMenu.item) { $wire.openEditModal(contextMenu.item.id); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
            >
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <span>Edit Quotation</span>
            </button>
        </template>

        <a 
            :href="contextMenu.item ? '/quotations/' + contextMenu.item.id + '/print' : '#'"
            target="_blank"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            <span>Print Formal Quote</span>
        </a>

        <template x-if="contextMenu.item?.canConvert">
            <button 
                @click="if (contextMenu.item) { $wire.convertToSale(contextMenu.item.id); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-emerald-700 hover:bg-emerald-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Load to POS Cart</span>
            </button>
        </template>

        <template x-if="contextMenu.item?.status === 'draft'">
            <button 
                @click="if (contextMenu.item) { $wire.updateStatus(contextMenu.item.id, 'sent'); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-sky-700 hover:bg-sky-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                <span>Mark as Sent</span>
            </button>
        </template>

        <div class="my-1 border-t border-slate-100"></div>

        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.number); $dispatch('toast', { type: 'success', message: 'Quote #' + contextMenu.item.number + ' copied.' }); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Quote #</span>
        </button>

        <template x-if="contextMenu.item?.status !== 'cancelled' && contextMenu.item?.canConvert">
            <button 
                @click="if (contextMenu.item && confirm('Cancel this quotation?')) { $wire.updateStatus(contextMenu.item.id, 'cancelled'); contextMenu.close(); }"
                type="button" 
                class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-rose-700 hover:bg-rose-50 transition-colors"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <span>Cancel Quote</span>
            </button>
        </template>
    </div>

    <!-- Proper, Formal & Uncluttered Quotation Details Modal -->
    @if ($showDetailModal && $selectedQuotation)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-3xl rounded-xl bg-white shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Topbar -->
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 bg-slate-50">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-sm font-bold text-[#008fb3]">{{ $selectedQuotation->quote_number }}</span>
                        @php
                            $badgeStyle = match ($selectedQuotation->status) {
                                'draft' => 'bg-slate-100 text-slate-700 border-slate-300',
                                'sent' => 'bg-sky-50 text-sky-800 border-sky-300',
                                'accepted' => 'bg-emerald-50 text-emerald-800 border-emerald-300',
                                'expired' => 'bg-amber-50 text-amber-800 border-amber-300',
                                'cancelled' => 'bg-rose-50 text-rose-800 border-rose-300',
                                default => 'bg-slate-100 text-slate-700 border-slate-300',
                            };
                        @endphp
                        <span class="inline-block px-2 py-0.5 text-[10px] font-bold uppercase rounded border {{ $badgeStyle }}">
                            {{ $selectedQuotation->status }}
                        </span>
                    </div>
                    <button wire:click="closeDetailModal" type="button" class="text-slate-400 hover:text-slate-700 text-xl font-bold p-1">
                        &times;
                    </button>
                </div>

                <!-- Clean, Formal Document Card Body -->
                <div class="overflow-y-auto p-6 space-y-5 text-xs">
                    <!-- Formal Header Section -->
                    <div class="border-b border-slate-200 pb-4 flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-black uppercase tracking-tight text-slate-900 font-heading">Grade A Paint Center</h2>
                            <p class="text-[11px] text-slate-600">Retail Paint, Tinting &amp; Painting Supplies</p>
                            <p class="text-[10px] text-slate-400">Lapu-Lapu St., Agdao, Davao City · Contact: (082) 227-1234</p>
                        </div>
                        <div class="sm:text-right text-[11px] space-y-0.5">
                            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block">Price Quotation</span>
                            <p class="text-slate-600">Issued: <strong class="text-slate-900">{{ $selectedQuotation->created_at->format('M d, Y') }}</strong></p>
                            <p class="text-slate-600">Valid Until: <strong class="text-slate-900">{{ $selectedQuotation->valid_until ? $selectedQuotation->valid_until->format('M d, Y') : '—' }}</strong></p>
                        </div>
                    </div>

                    <!-- Client & Specification Meta -->
                    <div class="grid grid-cols-2 gap-4 rounded-lg bg-slate-50 border border-slate-200 p-4">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-500 tracking-wider block mb-1">Prepared For</span>
                            <p class="text-sm font-bold text-slate-900">{{ $selectedQuotation->customer_name ?: 'Walk-in / Valued Client' }}</p>
                            @if ($selectedQuotation->customer_contact)
                                <p class="text-slate-600 mt-0.5">{{ $selectedQuotation->customer_contact }}</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] uppercase font-bold text-slate-500 tracking-wider block mb-1">Issued By</span>
                            <p class="font-bold text-slate-800">{{ $selectedQuotation->user?->name ?? 'Staff' }}</p>
                            <p class="text-slate-500 text-[10px]">{{ ucfirst($selectedQuotation->user?->role ?? 'Personnel') }}</p>
                        </div>
                    </div>

                    <!-- Line Items Table -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-bold text-xs uppercase tracking-wider text-slate-700">Quotation Line Items</h3>
                            <span class="text-[11px] text-slate-500">{{ $selectedQuotation->items->count() }} item(s)</span>
                        </div>
                        <div class="overflow-hidden rounded border border-slate-200">
                            <table class="w-full border-collapse text-xs">
                                <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700 border-b border-slate-200">
                                    <tr>
                                        <th class="px-3 py-2 text-left">SKU</th>
                                        <th class="px-3 py-2 text-left">Description</th>
                                        <th class="px-3 py-2 text-left">Package</th>
                                        <th class="px-3 py-2 text-right">Qty</th>
                                        <th class="px-3 py-2 text-right">Unit Price</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($selectedQuotation->items as $item)
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="px-3 py-2 font-mono text-[11px] text-slate-600">
                                                {{ $item->sku ?? ($item->product?->sku ?? 'CUSTOM-MIX') }}
                                            </td>
                                            <td class="px-3 py-2 font-medium text-slate-900">
                                                {{ $item->description }}
                                                @if ($item->mix_data)
                                                    <span class="block text-[10px] text-purple-700 font-semibold">Custom Paint Formulation</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-slate-600">
                                                {{ $item->package ?? ($item->product?->packageUnit?->abbreviation ?? '—') }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums text-slate-900 font-medium">
                                                {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                                {{ $currency::format($item->unit_price) }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums font-bold text-slate-900">
                                                {{ $currency::format($item->subtotal) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Notes & Financial Summary -->
                    <div class="flex flex-col sm:flex-row justify-between items-start gap-4">
                        <div class="flex-1 w-full sm:max-w-md">
                            @if ($selectedQuotation->notes)
                                <div class="rounded border border-slate-200 bg-slate-50 p-3 text-xs">
                                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-600 mb-1">Notes &amp; Terms:</span>
                                    <p class="text-slate-700 whitespace-pre-line">{{ $selectedQuotation->notes }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="w-full sm:w-72 rounded-lg bg-slate-50 border border-slate-200 p-3.5 space-y-2 tabular-nums text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>Subtotal:</span>
                                <span class="font-medium text-slate-900">{{ $currency::format($selectedQuotation->subtotal) }}</span>
                            </div>

                            @if ((float) $selectedQuotation->discount_percentage > 0)
                                <div class="flex justify-between text-emerald-700 font-semibold">
                                    <span>Discount ({{ number_format((float) $selectedQuotation->discount_percentage, 2) }}%{{ $selectedQuotation->discount_type && $selectedQuotation->discount_type !== 'none' ? ' · ' . ucfirst(str_replace('_', ' ', $selectedQuotation->discount_type)) : '' }}):</span>
                                    <span>-{{ $currency::format($selectedQuotation->discount_amount) }}</span>
                                </div>
                                @if ($selectedQuotation->discount_reason)
                                    <div class="text-[10px] text-slate-500 italic pl-1 -mt-1">
                                        Reason: {{ $selectedQuotation->discount_reason }}
                                        @if ($selectedQuotation->discountAuthorizer)
                                            <span class="font-medium text-slate-700"> (Auth: {{ $selectedQuotation->discountAuthorizer->name }})</span>
                                        @endif
                                    </div>
                                @endif
                            @endif

                            <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-2">
                                <span>Total Estimated:</span>
                                <span class="text-base font-black text-slate-900">{{ $currency::format($selectedQuotation->total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer Toolbar -->
                <div class="border-t border-slate-200 px-5 py-3.5 bg-slate-50 flex items-center justify-between">
                    <button wire:click="closeDetailModal" type="button"
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                        Close
                    </button>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('quotations.print', $selectedQuotation->id) }}" target="_blank"
                            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            <span>Print Formal Quote</span>
                        </a>

                        @if (! $selectedQuotation->isConverted())
                            <button wire:click="openEditModal({{ $selectedQuotation->id }})" type="button"
                                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition">
                                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                <span>Edit Quotation</span>
                            </button>

                            @if ($selectedQuotation->status !== 'cancelled')
                                <button wire:click="convertToSale({{ $selectedQuotation->id }})" type="button"
                                    class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-white hover:bg-[#008fb3] transition shadow-xs">
                                    Load to POS Cart
                                </button>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Comprehensive Edit Quotation Modal -->
    @if ($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-4xl rounded-xl bg-white shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 bg-slate-50">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Edit Price Quotation</h2>
                        <p class="text-xs text-slate-500">Modify customer information, validity, line items, and discount settings</p>
                    </div>
                    <button wire:click="closeEditModal" type="button" class="text-slate-400 hover:text-slate-700 text-xl font-bold p-1">
                        &times;
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="overflow-y-auto p-5 space-y-4 text-xs">
                    <!-- Basic Meta Inputs -->
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-slate-50 p-3.5 rounded-lg border border-slate-200">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Customer / Client Name</label>
                            <input wire:model="editCustomerName" type="text" placeholder="e.g. John Doe / Davao Builders"
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Contact (Phone / Email)</label>
                            <input wire:model="editCustomerContact" type="text" placeholder="e.g. 0917-123-4567"
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Valid Until Date</label>
                            <input wire:model="editValidUntil" type="date"
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Status</label>
                            <select wire:model="editStatus"
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="accepted">Accepted</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <!-- Items Management Section -->
                    <div class="space-y-2">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <h3 class="font-bold text-xs uppercase tracking-wider text-slate-800">Quotation Line Items</h3>
                            
                            <!-- Search & Add Product -->
                            <div class="relative w-full sm:w-80">
                                <input wire:model.live.debounce.250ms="productSearch" type="search" placeholder="+ Search product by name or SKU to add..."
                                    class="w-full rounded border-slate-300 px-2.5 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />

                                @if (!empty($productSearchResults))
                                    <div class="absolute left-0 right-0 top-full mt-1 bg-white rounded border border-slate-200 shadow-xl z-20 max-h-48 overflow-y-auto">
                                        @foreach ($productSearchResults as $result)
                                            <button wire:click="addProductToEdit({{ $result['id'] }})" type="button"
                                                class="w-full text-left px-3 py-1.5 text-xs hover:bg-cyan-50 flex items-center justify-between border-b border-slate-100 last:border-b-0">
                                                <div>
                                                    <span class="font-medium text-slate-900">{{ $result['name'] }}</span>
                                                    <span class="text-[10px] text-slate-500 block">{{ $result['sku'] }} · {{ $result['brand'] }} ({{ $result['package'] }})</span>
                                                </div>
                                                <span class="font-bold text-slate-800 tabular-nums">{{ $currency::format($result['price']) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Editable Items Table -->
                        <div class="overflow-x-auto rounded border border-slate-200">
                            <table class="w-full border-collapse text-xs">
                                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-200">
                                    <tr>
                                        <th class="px-2.5 py-2 text-left w-20">SKU</th>
                                        <th class="px-2.5 py-2 text-left">Description</th>
                                        <th class="px-2.5 py-2 text-left w-24">Spec/Package</th>
                                        <th class="px-2.5 py-2 text-center w-24">Qty</th>
                                        <th class="px-2.5 py-2 text-right w-28">Unit Price</th>
                                        <th class="px-2.5 py-2 text-right w-28">Subtotal</th>
                                        <th class="px-2.5 py-2 text-center w-12">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($editItems as $index => $item)
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="px-2.5 py-1.5 font-mono text-[11px] text-slate-600">
                                                {{ $item['sku'] }}
                                            </td>
                                            <td class="px-2.5 py-1.5">
                                                <input wire:model="editItems.{{ $index }}.description" type="text"
                                                    class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                            </td>
                                            <td class="px-2.5 py-1.5 text-slate-600">
                                                {{ $item['package'] }}
                                            </td>
                                            <td class="px-2.5 py-1.5 text-center">
                                                <input wire:model.live="editItems.{{ $index }}.quantity" type="number" step="0.01" min="0.01"
                                                    class="w-20 text-center rounded border-slate-300 px-1.5 py-1 text-xs tabular-nums text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right">
                                                <input wire:model.live="editItems.{{ $index }}.unit_price" type="number" step="0.01" min="0"
                                                    class="w-24 text-right rounded border-slate-300 px-1.5 py-1 text-xs tabular-nums text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                            </td>
                                            <td class="px-2.5 py-1.5 text-right font-bold text-slate-900 tabular-nums">
                                                {{ $currency::format($item['subtotal']) }}
                                            </td>
                                            <td class="px-2.5 py-1.5 text-center">
                                                <button wire:click="removeEditItem({{ $index }})" type="button"
                                                    class="inline-flex items-center justify-center h-6 w-6 rounded text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition" title="Remove Item">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-4 py-6 text-center text-slate-400">
                                                No items in this quotation. Search above to add items.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Notes, Discount, and Totals Section -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <!-- Notes & Terms -->
                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-slate-700">Quotation Notes &amp; Terms</label>
                            <textarea wire:model="editNotes" rows="4" placeholder="Enter special terms, delivery notes, or payment conditions..."
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"></textarea>
                        </div>

                        <!-- Discount & Financial Calculation -->
                        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3.5 space-y-2.5">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-600 mb-0.5">Discount Type</label>
                                    <select wire:model.live="editDiscountType"
                                        class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                        <option value="none">None</option>
                                        <option value="percentage">Standard %</option>
                                        <option value="senior_pwd">Senior / PWD (5%)</option>
                                        <option value="wholesale">Wholesale / Volume</option>
                                        <option value="special">Special Quotation</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-600 mb-0.5">Discount %</label>
                                    <input wire:model.live="editDiscountPercentage" type="number" step="0.5" min="0" max="100"
                                        class="w-full rounded border-slate-300 px-2 py-1 text-xs tabular-nums text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                </div>
                            </div>

                            @if ((float) $editDiscountPercentage > 0)
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-600 mb-0.5">Discount Justification / Reason</label>
                                    <input wire:model="editDiscountReason" type="text" placeholder="e.g. Contractor volume discount"
                                        class="w-full rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                </div>
                            @endif

                            <div class="border-t border-slate-200 pt-2 space-y-1 text-xs tabular-nums">
                                <div class="flex justify-between text-slate-600">
                                    <span>Subtotal:</span>
                                    <span class="font-medium text-slate-900">{{ $currency::format($this->editSubtotal) }}</span>
                                </div>
                                @if ((float) $editDiscountPercentage > 0)
                                    <div class="flex justify-between text-emerald-700 font-semibold">
                                        <span>Discount:</span>
                                        <span>-{{ $currency::format($this->editDiscountAmount) }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-1.5">
                                    <span>Quotation Total:</span>
                                    <span class="text-base text-slate-900">{{ $currency::format($this->editTotal) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="border-t border-slate-200 px-5 py-3.5 bg-slate-50 flex items-center justify-between">
                    <button wire:click="closeEditModal" type="button"
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
                        Cancel
                    </button>

                    <button wire:click="saveQuotationChanges" type="button" wire:loading.attr="disabled"
                        class="rounded bg-[#00a3cc] px-5 py-1.5 text-xs font-bold uppercase tracking-wider text-white hover:bg-[#008fb3] transition shadow-xs">
                        <span wire:loading.remove wire:target="saveQuotationChanges">Save Changes</span>
                        <span wire:loading wire:target="saveQuotationChanges">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
