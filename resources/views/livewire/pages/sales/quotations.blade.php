<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
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
    public int $perPage = 15;

    // Quotation detail modal
    public bool $showDetailModal = false;
    public ?int $viewingQuotationId = null;

    // Quotation create/edit modal
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

    // Catalog selector inside modal
    public string $productSearch = '';
    public string $catalogBrandId = '';
    public string $catalogCategoryId = '';
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

    public function updatedPerPage(): void
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

    public function openCreateModal(): void
    {
        $this->editingQuotationId = null;
        $this->editCustomerName = '';
        $this->editCustomerContact = '';
        $this->editValidUntil = now()->addDays(7)->format('Y-m-d');
        $this->editNotes = '';
        $this->editStatus = 'draft';
        $this->editDiscountPercentage = '0';
        $this->editDiscountType = 'none';
        $this->editDiscountReason = '';
        $this->editItems = [];
        $this->productSearch = '';
        $this->catalogBrandId = '';
        $this->catalogCategoryId = '';
        $this->showDetailModal = false;
        $this->showEditModal = true;
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
            $qty = max(1, (float) ($item['quantity'] ?? 1));
            $this->editItems[$index]['quantity'] = $qty;
            $this->editItems[$index]['subtotal'] = round($qty * (float) $item['unit_price'], 2);
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
        $rules = [
            'editCustomerName' => ['nullable', 'string', 'max:200'],
            'editCustomerContact' => ['nullable', 'string', 'max:50'],
            'editValidUntil' => ['nullable', 'date'],
            'editNotes' => ['nullable', 'string', 'max:1000'],
            'editStatus' => ['required', 'in:draft,sent,accepted,cancelled'],
            'editDiscountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'editItems' => ['required', 'array', 'min:1'],
            'editItems.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'editItems.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ];

        if (trim($this->editCustomerContact) !== '') {
            $rules['editCustomerContact'][] = 'regex:/^[0-9+\-\s()]{7,30}$/';
        }

        $this->validate($rules, [
            'editCustomerContact.regex' => 'Contact number must contain only numbers, dashes, spaces, or parentheses.',
            'editItems.min' => 'Please add at least one item to the quotation.',
            'editItems.*.quantity.min' => 'Quantity must be greater than zero.',
            'editItems.*.quantity.max' => 'Quantity cannot exceed 99,999.99.',
            'editItems.*.quantity.numeric' => 'Quantity must be a valid number.',
        ]);

        $subtotal = $this->editSubtotal;
        $total = $subtotal;

        if (! $this->editingQuotationId) {
            $quote = DB::transaction(function () use ($subtotal, $total) {
                $q = Quotation::create([
                    'user_id' => auth()->id(),
                    'quote_number' => 'QUO-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                    'customer_name' => trim($this->editCustomerName) ?: null,
                    'customer_contact' => trim($this->editCustomerContact) ?: null,
                    'valid_until' => $this->editValidUntil ?: now()->addDays(7),
                    'notes' => trim($this->editNotes) ?: null,
                    'status' => $this->editStatus ?: 'draft',
                    'subtotal' => $subtotal,
                    'discount_percentage' => 0.0,
                    'discount_amount' => 0.0,
                    'discount_type' => null,
                    'discount_reason' => null,
                    'discount_authorized_by' => null,
                    'tax_rate' => 0.0,
                    'tax_amount' => 0.0,
                    'total' => $total,
                ]);

                foreach ($this->editItems as $item) {
                    $q->items()->create([
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
                    'event' => 'quotation_created',
                    'auditable_type' => Quotation::class,
                    'auditable_id' => $q->id,
                    'context' => [
                        'quote_number' => $q->quote_number,
                        'customer' => $q->customer_name,
                        'total' => $total,
                        'items_count' => count($this->editItems),
                    ],
                ]);

                return $q;
            });

            $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} created successfully."]);
            $this->closeEditModal();
            return;
        }

        $quote = Quotation::findOrFail($this->editingQuotationId);
        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Converted quotations cannot be modified.']);
            return;
        }

        DB::transaction(function () use ($quote, $subtotal, $total) {
            $quote->update([
                'customer_name' => trim($this->editCustomerName) ?: null,
                'customer_contact' => trim($this->editCustomerContact) ?: null,
                'valid_until' => $this->editValidUntil ?: null,
                'notes' => trim($this->editNotes) ?: null,
                'status' => $this->editStatus,
                'subtotal' => $subtotal,
                'discount_percentage' => 0.0,
                'discount_amount' => 0.0,
                'discount_type' => null,
                'discount_reason' => null,
                'discount_authorized_by' => null,
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
        abort_unless(auth()->user()?->canAccessPos(), 403, 'Mixers cannot load quotations into POS for checkout.');

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

        $catalogQuery = Product::query()
            ->with(['brand', 'packageUnit', 'category', 'inventories'])
            ->where('active', true)
            ->when($this->productSearch, function ($q) {
                $term = '%' . trim($this->productSearch) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->catalogBrandId, fn($q) => $q->where('brand_id', $this->catalogBrandId))
            ->when($this->catalogCategoryId, fn($q) => $q->where('category_id', $this->catalogCategoryId))
            ->orderBy('name')
            ->limit(10);

        $catalogProducts = $this->showEditModal ? $catalogQuery->get() : collect();

        return view('livewire.pages.sales.quotations', [
            'quotations' => $query->paginate($this->perPage),
            'selectedQuotation' => $selectedQuotation,
            'stats' => $stats,
            'currency' => Currency::class,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'catalogProducts' => $catalogProducts,
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
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Quotations</h1>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openCreateModal" type="button"
                class="inline-flex items-center gap-1.5 rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Create Quotation</span>
            </button>
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

    <!-- Table Section with Filter & Showing Count (space-y-2) -->
    <div class="space-y-2">
        <!-- Filter Controls Outside the Table Div -->
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Search -->
            <div class="w-64">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search quote #, customer..."
                    class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- Status Filter -->
            <div class="w-40">
                <select wire:model.live="status" class="w-full h-9 rounded border border-slate-300 bg-white pl-2.5 pr-8 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="accepted">Accepted</option>
                    <option value="expired">Expired</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- Date From -->
            <div class="flex items-center gap-1.5">
                <label for="quoteDateFrom" class="text-[10px] font-bold text-slate-500 uppercase">From:</label>
                <input wire:model.live="dateFrom" id="quoteDateFrom" type="date" title="Created From"
                    class="h-9 rounded border border-slate-300 bg-white px-2.5 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- Date To -->
            <div class="flex items-center gap-1.5">
                <label for="quoteDateTo" class="text-[10px] font-bold text-slate-500 uppercase">To:</label>
                <input wire:model.live="dateTo" id="quoteDateTo" type="date" title="Created To"
                    class="h-9 rounded border border-slate-300 bg-white px-2.5 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- Reset Button -->
            @if ($search || $status || $dateFrom || $dateTo)
                <button wire:click="resetFilters" type="button"
                    class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                    Reset
                </button>
            @endif
        </div>

        <!-- Showing X of N Items Indicator & Per Page Selector -->
        <div class="flex items-center justify-between text-xs text-slate-500">
            <div>
                @if ($quotations && $quotations->total() > 0)
                    Showing <span class="font-medium text-slate-700">{{ $quotations->firstItem() }}</span> to
                    <span class="font-medium text-slate-700">{{ $quotations->lastItem() }}</span> of <span
                        class="font-medium text-slate-700">{{ $quotations->total() }}</span> items
                @else
                    Showing <span class="font-medium text-slate-700">0</span> items
                @endif
            </div>
            <div class="flex items-center gap-1.5">
                <label for="quotePerPage" class="text-xs text-slate-500">Show:</label>
                <select wire:model.live="perPage" id="quotePerPage"
                    class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span class="text-xs text-slate-500">items</span>
            </div>
        </div>

        <!-- Table Container -->
        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
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
                        <th class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
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
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $quote->id }}, number: '{{ $quote->quote_number }}', status: '{{ $quote->status }}', canConvert: {{ !$quote->isConverted() && $quote->status !== 'cancelled' ? 'true' : 'false' }}, canEdit: {{ !$quote->isConverted() ? 'true' : 'false' }} })" 
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
            <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                <div>
                    <span class="text-slate-500">
                        Page <span class="font-medium text-slate-800">{{ $quotations->currentPage() }}</span> of
                        <span class="font-medium text-slate-800">{{ $quotations->lastPage() }}</span>
                    </span>
                </div>
                <div class="inline-flex items-center gap-1">
                    @if ($quotations->onFirstPage())
                        <span class="px-2 py-1 text-slate-300 cursor-not-allowed">Previous</span>
                    @else
                        <button wire:click="previousPage" type="button" class="px-2 py-1 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded">Previous</button>
                    @endif

                    @php
                        $cur = $quotations->currentPage();
                        $last = $quotations->lastPage();
                        $start = max(1, $cur - 2);
                        $end = min($last, $cur + 2);
                    @endphp

                    @if ($start > 1)
                        <button wire:click="gotoPage(1)" type="button" class="px-2 py-1 rounded text-slate-600 hover:bg-slate-100">1</button>
                        @if ($start > 2)
                            <span class="px-1 text-slate-400">...</span>
                        @endif
                    @endif

                    @for ($p = $start; $p <= $end; $p++)
                        @if ($p == $cur)
                            <span class="px-2 py-1 rounded bg-slate-900 text-white font-medium">{{ $p }}</span>
                        @else
                            <button wire:click="gotoPage({{ $p }})" type="button" class="px-2 py-1 rounded text-slate-600 hover:bg-slate-100">{{ $p }}</button>
                        @endif
                    @endfor

                    @if ($end < $last)
                        @if ($end < $last - 1)
                            <span class="px-1 text-slate-400">...</span>
                        @endif
                        <button wire:click="gotoPage({{ $last }})" type="button" class="px-2 py-1 rounded text-slate-600 hover:bg-slate-100">{{ $last }}</button>
                    @endif

                    @if ($quotations->hasMorePages())
                        <button wire:click="nextPage" type="button" class="px-2 py-1 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded">Next</button>
                    @else
                        <span class="px-2 py-1 text-slate-300 cursor-not-allowed">Next</span>
                    @endif
                </div>
            </div>
        @endif
        </div>
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

        @if (auth()->user()?->canAccessPos())
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
        @endif

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
                            <p class="text-[10px] text-slate-600">Lapu-Lapu St., Agdao, Davao City</p>
                        </div>
                        <div class="sm:text-right text-[11px] space-y-0.5">
                            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block">Price Quotation</span>
                            <p class="text-slate-600">Issued: {{ $selectedQuotation->created_at->format('M d, Y') }}</p>
                            <p class="text-slate-600">Valid Until: {{ $selectedQuotation->valid_until ? $selectedQuotation->valid_until->format('M d, Y') : '—' }}</p>
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

                        <div class="w-full sm:w-72 rounded-lg  p-3.5 space-y-2 tabular-nums text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>Subtotal:</span>
                                <span class="font-medium text-slate-900">{{ $currency::format($selectedQuotation->subtotal) }}</span>
                            </div>

                            @if ((float) $selectedQuotation->discount_percentage > 0)
                                <div class="flex justify-between text-emerald-700 font-semibold">
                                    <span>Discount ({{ number_format((float) $selectedQuotation->discount_percentage, 2) }}%{{ $selectedQuotation->discount_type && $selectedQuotation->discount_type !== 'none' ? ' - ' . ucfirst(str_replace('_', ' ', $selectedQuotation->discount_type)) : '' }}):</span>
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

                            @if ($selectedQuotation->status !== 'cancelled' && auth()->user()?->canAccessPos())
                                <button wire:click="convertToSale({{ $selectedQuotation->id }})" type="button"
                                    class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] transition shadow-xs">
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
            <div class="w-full max-w-6xl rounded-xl bg-white shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 px-5 bg-slate-50">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">{{ $editingQuotationId ? 'Edit Price Quotation' : 'Create New Quotation' }}</h2>
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
                            @error('editCustomerName') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-700 mb-1">Contact (Phone / Email)</label>
                            <input wire:model="editCustomerContact" type="text" placeholder="e.g. 0917-123-4567"
                                inputmode="tel"
                                x-on:keydown="if(!/[0-9+\-\s()]/.test($event.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'].includes($event.key) && !$event.ctrlKey && !$event.metaKey) $event.preventDefault()"
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                            @error('editCustomerContact') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
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

                    <!-- Product Catalog Selector Section -->
                    <div class="">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-200 pb-2">
                            <div>
                                <h3 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">Select Products from Catalog</h3>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- Search -->
                                <div class="w-44 sm:w-52">
                                    <input wire:model.live.debounce.250ms="productSearch" type="search" placeholder="Search name or SKU..."
                                        class="w-full h-8 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                </div>
                                <!-- Brand -->
                                <div class="w-32">
                                    <select wire:model.live="catalogBrandId" class="w-full h-8 rounded border border-slate-300 bg-white pl-2 pr-6 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                        <option value="">All Brands</option>
                                        @foreach ($brands as $b)
                                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <!-- Category -->
                                <div class="w-32">
                                    <select wire:model.live="catalogCategoryId" class="w-full h-8 rounded border border-slate-300 bg-white pl-2 pr-6 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                        <option value="">All Categories</option>
                                        @foreach ($categories as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Catalog Items Table -->
                        <div class="overflow-x-auto max-h-52 overflow-y-auto border border-slate-200 rounded">
                            <table class="w-full border-collapse text-xs">
                                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-200 sticky top-0 z-10">
                                    <tr>
                                        <th class="px-2.5 py-1.5 text-left w-24">SKU</th>
                                        <th class="px-2.5 py-1.5 text-left">Product Name</th>
                                        <th class="px-2.5 py-1.5 text-left w-28">Brand</th>
                                        <th class="px-2.5 py-1.5 text-left w-24">Package</th>
                                        <th class="px-2.5 py-1.5 text-right w-24">Price</th>
                                        <th class="px-2.5 py-1.5 text-center w-20">Stock</th>
                                        <th class="px-2.5 py-1.5 text-center w-20">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($catalogProducts as $cp)
                                        <tr class="hover:bg-cyan-50/40 transition-colors">
                                            <td class="px-2.5 py-1.5 font-mono text-[11px] text-slate-600">{{ $cp->sku }}</td>
                                            <td class="px-2.5 py-1.5 font-semibold text-slate-900">{{ $cp->name }}</td>
                                            <td class="px-2.5 py-1.5 text-slate-600">{{ $cp->brand?->name ?? '—' }}</td>
                                            <td class="px-2.5 py-1.5 text-slate-600">{{ $cp->formattedPackage() }}</td>
                                            <td class="px-2.5 py-1.5 text-right font-medium text-slate-900 tabular-nums">{{ $currency::format($cp->selling_price) }}</td>
                                            <td class="px-2.5 py-1.5 text-center tabular-nums text-slate-600">{{ number_format($cp->getTotalAvailableStock(), 1) }}</td>
                                            <td class="px-2.5 py-1.5 text-center">
                                                <button wire:click="addProductToEdit({{ $cp->id }})" type="button"
                                                    class="inline-flex items-center gap-1 rounded bg-[#00a3cc] px-2 py-0.5 text-[11px] font-bold text-white hover:bg-[#008fb3] transition shadow-xs">
                                                    + Add
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-3 py-6 text-center text-slate-400">
                                                No products match your catalog filters.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Items Management Section -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-xs uppercase tracking-wider text-slate-800">
                                Quotation Line Items ({{ count($editItems) }})
                            </h3>
                        </div>

                        <!-- Non-Arbitrary Items Table (Read-only details, editable quantity) -->
                        <div class="overflow-x-auto rounded border border-slate-200">
                            <table class="w-full border-collapse text-xs">
                                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-200">
                                    <tr>
                                        <th class="px-2.5 py-2 text-left w-24">SKU</th>
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
                                            <td class="px-2.5 py-2 font-mono text-[11px] text-slate-600">
                                                {{ $item['sku'] }}
                                            </td>
                                            <td class="px-2.5 py-2 font-semibold text-slate-900">
                                                {{ $item['description'] }}
                                            </td>
                                            <td class="px-2.5 py-2 text-slate-600">
                                                {{ $item['package'] }}
                                            </td>
                                            <td class="px-2.5 py-2 text-center">
                                                <input wire:model.live.debounce.250ms="editItems.{{ $index }}.quantity" type="number" step="1" min="1" max="99999.99"
                                                    inputmode="decimal"
                                                    x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                                    class="w-20 text-center rounded border border-slate-300 px-1.5 py-1 text-xs tabular-nums text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                                @error('editItems.' . $index . '.quantity')
                                                    <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p>
                                                @enderror
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-medium text-slate-700 tabular-nums">
                                                {{ $currency::format($item['unit_price']) }}
                                            </td>
                                            <td class="px-2.5 py-2 text-right font-bold text-slate-900 tabular-nums">
                                                {{ $currency::format($item['subtotal']) }}
                                            </td>
                                            <td class="px-2.5 py-2 text-center">
                                                <button wire:click="removeEditItem({{ $index }})" type="button"
                                                    class="inline-flex items-center justify-center h-6 w-6 rounded text-rose-500 hover:text-rose-700 hover:bg-rose-50 transition" title="Remove Item">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-4 py-6 text-center text-slate-400">
                                                No items in this quotation. Search and add products above.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @error('editItems')
                            <p class="text-rose-600 text-[11px] font-semibold mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Notes and Totals Section (No discounts in quotation modal) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <!-- Notes & Terms -->
                        <div class="space-y-1">
                            <label class="block text-[11px] font-semibold text-slate-700">Quotation Notes &amp; Terms</label>
                            <textarea wire:model="editNotes" rows="3" placeholder="Enter special terms, delivery notes, or payment conditions..."
                                class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"></textarea>
                        </div>

                        <!-- Financial Calculation (Subtotal = Total, no discounts) -->
                        <div class=" p-3.5 space-y-2 tabular-nums text-xs flex flex-col justify-between">
                            <div class="flex justify-between text-slate-600">
                                <span class="font-sans font-normal">Quotation Items Subtotal:</span>
                                <span class="font-medium text-slate-900">{{ $currency::format($this->editSubtotal) }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-2">
                                <span class="font-sans font-bold">Total Quoted Amount:</span>
                                <span class="text-base font-black text-slate-900">{{ $currency::format($this->editSubtotal) }}</span>
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
                        class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] transition shadow-xs">
                        <span wire:loading.remove wire:target="saveQuotationChanges">{{ $editingQuotationId ? 'Save Changes' : 'Create Quotation' }}</span>
                        <span wire:loading wire:target="saveQuotationChanges">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
