<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\PackageUnit;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    // Product catalog filters
    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitId = '';
    public bool $availableOnly = true;

    // Add-to-cart form
    public string $selectedProductId = '';
    public string $addQty = '1';
    public string $productId = '';
    public string $quantity = '1';

    // Cart
    public array $cart = [];

    // Quotation form
    public bool $showQuoteModal = false;
    public string $quoteCustomerName = '';
    public string $quoteCustomerContact = '';
    public string $quoteNotes = '';
    public string $quoteValidDays = '7';
    public string $quoteDiscountType = 'none';
    public string $quoteDiscountPercentage = '0';
    public string $quoteDiscountReason = '';
    public bool $quoteTaxApplied = true;

    // Custom Mix Modal
    public bool $showMixModal = false;
    public ?string $editingCartKey = null;
    public string $mixDescription = 'Custom mixed paint';
    public string $mixQuantity = '1';
    public string $mixResultUnit = 'L';
    public string $mixProductId = '';
    public string $mixEstimatedQuantity = '';
    public string $mixEstimatedQuantityUnit = 'L';
    public string $mixNotes = '';
    public array $mixComponents = [];

    // Load quote modal
    public bool $showLoadQuoteModal = false;
    public string $loadQuoteSearch = '';

    public const TAX_RATE = 12.0;

    public function mount(): void
    {
        $this->cart = session('pos.cart', []);
    }

    public function updatedSearch(): void
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

    public function updatedPackageUnitId(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'brandId', 'categoryId', 'packageUnitId']);
        $this->availableOnly = true;
        $this->resetPage();
    }

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = (string) $productId;
        $this->addQty = '1';
        $this->resetValidation(['selectedProductId', 'addQty']);
    }

    public function addToCart(): void
    {
        $this->validate([
            'selectedProductId' => ['required', 'exists:products,id'],
            'addQty' => ['required', 'integer', 'min:1'],
        ], [
            'selectedProductId.required' => 'Select a product first.',
            'addQty.integer' => 'Quantity must be a whole number.',
            'addQty.min' => 'Quantity must be at least 1.',
        ]);

        $product = Product::with(['inventory', 'packageUnit', 'brand'])->where('active', true)->findOrFail((int) $this->selectedProductId);
        $qty = (int) $this->addQty;
        $key = 'product:' . $product->id;
        $currentInCart = (int) ($this->cart[$key]['quantity'] ?? 0);
        $newQty = $currentInCart + $qty;

        $available = (int) ($product->inventory?->quantity ?? 0);
        if ($newQty > $available) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => "Cannot add {$qty}. Available stock: {$available} (already {$currentInCart} in cart).",
            ]);
            $this->addError('addQty', "Cannot exceed available stock ({$available} available).");
            $this->addError('productId', "Only {$available} available.");
            return;
        }

        $packageLabel = $product->formattedPackage();

        $this->cart[$key] = [
            'type' => 'normal',
            'product_id' => $product->id,
            'description' => $product->name,
            'package' => $packageLabel,
            'brand' => $product->brand?->name ?? '—',
            'unit' => $product->packageUnit?->abbreviation ?? 'unit',
            'sku' => $product->sku,
            'quantity' => $newQty,
            'unit_price' => (float) $product->selling_price,
            'subtotal' => round($newQty * (float) $product->selling_price, 2),
        ];

        session(['pos.cart' => $this->cart]);

        $this->reset(['selectedProductId', 'addQty', 'productId']);
        $this->addQty = '1';

        $this->dispatch('toast', ['type' => 'success', 'message' => "{$product->name} added to cart."]);
    }

    public function quickAddToCart(int $productId): void
    {
        $product = Product::with(['inventory', 'packageUnit', 'brand'])->where('active', true)->findOrFail($productId);
        $key = 'product:' . $product->id;
        $currentInCart = (int) ($this->cart[$key]['quantity'] ?? 0);
        $available = (int) ($product->inventory?->quantity ?? 0);

        if ($currentInCart + 1 > $available) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => "Stock limit reached: only {$available} available for {$product->name}.",
            ]);
            return;
        }

        $this->selectedProductId = (string) $productId;
        $this->addQty = '1';
        $this->addToCart();
    }

    public function addProduct(int $productId): void
    {
        $this->quickAddToCart($productId);
    }

    public function updateCartQuantity(string $key, string $value): bool
    {
        $this->updateCartQty($key, $value);
        return ! $this->getErrorBag()->has('cart') && ! $this->getErrorBag()->has('productId');
    }

    public function cartTotal(): float
    {
        return $this->cartSubtotal();
    }

    public function finalizeSale(): void
    {
        if ($this->cart === [] && $this->productId !== '') {
            $this->selectedProductId = $this->productId;
            $this->addQty = $this->quantity ?: '1';
            $this->addToCart();
            if ($this->getErrorBag()->has('productId') || $this->getErrorBag()->has('addQty')) {
                return;
            }
        }
        $this->checkout();
    }

    public function checkout(): void
    {
        if ($this->cart === []) {
            $this->addError('cart', 'Add at least one item before checkout.');
            return;
        }

        $subtotal = $this->cartSubtotal();
        $taxAmount = round($subtotal * (self::TAX_RATE / 100), 2);
        $total = round($subtotal + $taxAmount, 2);

        try {
            DB::transaction(function () use ($subtotal, $taxAmount, $total): void {
                $hasCustomMix = collect($this->cart)->contains('type', 'custom_mix');

                $sale = Sale::create([
                    'user_id' => auth()->id(),
                    'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                    'sold_at' => now(),
                    'type' => $hasCustomMix ? 'mixed' : 'normal',
                    'subtotal' => $subtotal,
                    'tax_rate' => self::TAX_RATE,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'payment_method' => 'cash',
                    'payment_amount' => $total,
                    'change_amount' => 0,
                ]);

                foreach ($this->cart as $line) {
                    if ($line['type'] === 'custom_mix') {
                        $sale->items()->create([
                            'product_id' => null,
                            'description' => $line['description'],
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'subtotal' => $line['subtotal'],
                        ]);

                        $mix = MixingTransaction::create([
                            'sale_id' => $sale->id,
                            'price_basis_product_id' => $line['basis_product_id'] ?? null,
                            'notes' => $line['notes'] ?: null,
                        ]);

                        foreach ($line['components'] as $component) {
                            $mix->components()->create([
                                'product_id' => $component['product_id'],
                                'estimated_quantity' => $component['estimated_quantity'],
                                'estimated_quantity_unit' => $component['estimated_quantity_unit'],
                            ]);
                        }
                        continue;
                    }

                    $product = Product::findOrFail($line['product_id']);
                    $inventory = Inventory::where('product_id', $product->id)
                        ->lockForUpdate()
                        ->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);

                    $before = (float) $inventory->quantity;
                    if ($before < (float) $line['quantity']) {
                        throw ValidationException::withMessages([
                            'productId' => "Insufficient stock for {$product->name}.",
                        ]);
                    }

                    $inventory->decrement('quantity', (float) $line['quantity']);
                    $after = $before - (float) $line['quantity'];

                    $sale->items()->create([
                        'product_id' => $product->id,
                        'description' => $line['description'] . ' ' . ($line['package'] ?? ''),
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'user_id' => auth()->id(),
                        'type' => 'sale',
                        'quantity_change' => -(float) $line['quantity'],
                        'quantity_before' => $before,
                        'quantity_after' => $after,
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->id,
                    ]);
                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'event' => 'sale_completed',
                    'auditable_type' => Sale::class,
                    'auditable_id' => $sale->id,
                    'context' => ['total' => $total, 'lines' => count($this->cart)],
                ]);
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
            return;
        }

        $this->clearCart();
    }

    public function updateCartQty(string $key, string $value): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $isNormal = ($this->cart[$key]['type'] ?? 'normal') === 'normal';
        $qty = $isNormal ? (int) round((float) $value) : (float) $value;

        if ($qty <= 0) {
            $this->removeFromCart($key);
            return;
        }

        if ($isNormal) {
            $product = Product::with('inventory')->find($this->cart[$key]['product_id']);
            $available = (int) ($product?->inventory?->quantity ?? 0);
            if ($available <= 0) {
                $this->dispatch('toast', [
                    'type' => 'error',
                    'message' => "Item '{$product?->name}' is out of stock.",
                ]);
                $this->removeFromCart($key);
                return;
            }
            if ($qty > $available) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => "Stock limit reached: only {$available} available for {$product?->name}.",
                ]);
                $qty = $available;
            }
            if ($qty < 1) {
                $this->removeFromCart($key);
                return;
            }
        }

        $this->cart[$key]['quantity'] = $qty;
        $this->cart[$key]['subtotal'] = round($qty * (float) $this->cart[$key]['unit_price'], 2);
        session(['pos.cart' => $this->cart]);
    }

    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
        $this->cart = array_values((array) $this->cart);
        // Re-key — handle both string and numeric keys
        $keyed = [];
        foreach (session('pos.cart', []) as $k => $v) {
            if ($k !== $key) {
                $keyed[$k] = $v;
            }
        }
        $this->cart = $keyed;
        session(['pos.cart' => $this->cart]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
        session()->forget(['pos.cart', 'pos.quotation_id', 'pos.customer_name', 'pos.customer_contact', 'pos.discount_percentage']);
    }

    public function cartSubtotal(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function proceedToCheckout(): void
    {
        if (empty($this->cart)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Cart is empty.']);
            return;
        }

        session(['pos.cart' => $this->cart]);
        $this->redirectRoute('sales.checkout');
    }

    // ── Custom Mix ──────────────────────────────────────────────────────────────

    public function openMixModal(): void
    {
        $this->resetMixForm();
        $this->showMixModal = true;
    }

    public function resetMixForm(): void
    {
        $this->showMixModal = false;
        $this->editingCartKey = null;
        $this->mixDescription = 'Custom mixed paint';
        $this->mixQuantity = '1';
        $this->mixResultUnit = 'L';
        $this->mixProductId = '';
        $this->mixEstimatedQuantity = '';
        $this->mixEstimatedQuantityUnit = 'L';
        $this->mixNotes = '';
        $this->mixComponents = [];
        $this->resetValidation();
    }

    public function addMixComponent(): void
    {
        $this->validate([
            'mixProductId' => ['required', 'exists:products,id'],
            'mixEstimatedQuantity' => ['required', 'numeric', 'gt:0'],
            'mixEstimatedQuantityUnit' => ['required', 'string', 'in:ml,L,gal'],
        ], [
            'mixProductId.required' => 'Select a mixing material.',
            'mixEstimatedQuantity.gt' => 'Estimated quantity must be greater than 0.',
        ]);

        $product = Product::with(['packageUnit', 'category'])->find($this->mixProductId);

        if (Category::where('is_for_mixing', true)->exists() && ! $product?->category?->is_for_mixing) {
            $this->addError('mixProductId', 'Only paints in designated mixing categories can be selected.');
            return;
        }

        $this->mixComponents[] = [
            'product_id' => (int) $this->mixProductId,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'category_name' => $product->category?->name ?? '—',
            'price' => (float) $product->selling_price,
            'estimated_quantity' => (float) $this->mixEstimatedQuantity,
            'estimated_quantity_unit' => $this->mixEstimatedQuantityUnit,
        ];

        $this->reset(['mixProductId', 'mixEstimatedQuantity']);
    }

    public function removeMixComponent(int $index): void
    {
        unset($this->mixComponents[$index]);
        $this->mixComponents = array_values($this->mixComponents);
    }

    public function currentBasisProduct(): ?array
    {
        if (empty($this->mixComponents)) {
            return null;
        }

        return collect($this->mixComponents)->sortByDesc('price')->first();
    }

    public function addMixToCart(): void
    {
        $this->validate([
            'mixDescription' => ['required', 'string', 'max:255'],
            'mixQuantity' => ['required', 'numeric', 'gt:0'],
            'mixResultUnit' => ['required', 'in:ml,L,gal'],
            'mixComponents' => ['required', 'array', 'min:1'],
        ], [
            'mixComponents.min' => 'Add at least one component material.',
        ]);

        $basis = $this->currentBasisProduct();
        if (! $basis) {
            $this->addError('mixComponents', 'Failed to determine price basis.');
            return;
        }

        $resultingQty = (float) $this->mixQuantity;
        $unitPrice = (float) $basis['price'];
        $subtotal = round($resultingQty * $unitPrice, 2);

        $key = $this->editingCartKey ?: ('mix:' . Str::uuid());

        $this->cart[$key] = [
            'type' => 'custom_mix',
            'product_id' => null,
            'description' => trim($this->mixDescription),
            'package' => $resultingQty . ' ' . $this->mixResultUnit,
            'brand' => 'Custom Mix',
            'unit' => $this->mixResultUnit,
            'sku' => 'CUSTOM-MIX',
            'resulting_quantity' => $resultingQty,
            'resulting_unit' => $this->mixResultUnit,
            'quantity' => $resultingQty,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'basis_product_id' => $basis['product_id'],
            'basis_product_name' => $basis['product_name'],
            'components' => $this->mixComponents,
            'notes' => $this->mixNotes,
        ];

        session(['pos.cart' => $this->cart]);
        $this->resetMixForm();

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Custom mix added to cart.']);
    }

    public function addMixAndOpenQuote(): void
    {
        $this->addMixToCart();
        if (! $this->getErrorBag()->has('mixDescription') 
            && ! $this->getErrorBag()->has('mixQuantity') 
            && ! $this->getErrorBag()->has('mixResultUnit') 
            && ! $this->getErrorBag()->has('mixComponents')) {
            $this->openQuoteModal();
        }
    }

    public function editMix(string $key): void
    {
        if (! isset($this->cart[$key]) || $this->cart[$key]['type'] !== 'custom_mix') {
            return;
        }

        $item = $this->cart[$key];
        $this->editingCartKey = $key;
        $this->mixDescription = $item['description'];
        $this->mixQuantity = (string) $item['resulting_quantity'];
        $this->mixResultUnit = $item['resulting_unit'] ?? 'L';
        $this->mixComponents = $item['components'] ?? [];
        $this->mixNotes = $item['notes'] ?? '';
        $this->showMixModal = true;
    }

    // ── Quotation ────────────────────────────────────────────────────────────────

    public function openQuoteModal(): void
    {
        if (empty($this->cart)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Cart is empty.']);
            return;
        }

        $this->showQuoteModal = true;
        $this->quoteDiscountType = 'none';
        $this->quoteDiscountPercentage = '0';
        $this->quoteDiscountReason = '';
        $this->quoteTaxApplied = true;
        $this->quoteValidDays = '7';
    }

    public function setQuoteDiscountPreset(string $type): void
    {
        $this->quoteDiscountType = $type;

        switch ($type) {
            case 'none':
                $this->quoteDiscountPercentage = '0';
                $this->quoteDiscountReason = '';
                break;
            case 'regular':
                $this->quoteDiscountPercentage = '5';
                $this->quoteDiscountReason = 'Regular Customer / Loyalty Discount';
                break;
            case 'volume':
                $this->quoteDiscountPercentage = '10';
                $this->quoteDiscountReason = 'Wholesale / Bulk Purchase Discount';
                break;
            case 'employee':
                $this->quoteDiscountPercentage = '15';
                $this->quoteDiscountReason = 'Employee Discount';
                break;
            case 'senior_pwd':
                $this->quoteDiscountPercentage = '20';
                $this->quoteDiscountReason = 'Senior Citizen / PWD Discount';
                break;
            case 'custom':
                if ((float) $this->quoteDiscountPercentage <= 0) {
                    $this->quoteDiscountPercentage = '5';
                }
                if (empty($this->quoteDiscountReason)) {
                    $this->quoteDiscountReason = 'Special Quotation Discount';
                }
                break;
        }
    }

    public function saveAsQuotation(): void
    {
        $rules = [
            'quoteCustomerName' => ['nullable', 'string', 'max:200'],
            'quoteCustomerContact' => ['nullable', 'string', 'max:100'],
            'quoteNotes' => ['nullable', 'string', 'max:1000'],
            'quoteValidDays' => ['required', 'integer', 'min:1', 'max:365'],
            'quoteDiscountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];

        if ((float) $this->quoteDiscountPercentage > 0) {
            if (empty($this->quoteDiscountReason)) {
                $this->quoteDiscountReason = 'Customer Discount';
            }
            $rules['quoteDiscountReason'] = ['required', 'string', 'min:3', 'max:255'];
        }

        $this->validate($rules);

        $subtotal = $this->cartSubtotal();
        $discountPct = (float) $this->quoteDiscountPercentage;
        $discountAmt = round($subtotal * ($discountPct / 100), 2);
        $afterDiscount = round($subtotal - $discountAmt, 2);
        $taxRate = $this->quoteTaxApplied ? self::TAX_RATE : 0.0;
        $taxAmt = round($afterDiscount * ($taxRate / 100), 2);
        $total = round($afterDiscount + $taxAmt, 2);

        $quote = DB::transaction(function () use ($subtotal, $discountPct, $discountAmt, $taxRate, $taxAmt, $total): Quotation {
            $q = Quotation::create([
                'user_id' => auth()->id(),
                'quote_number' => 'QUO-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'customer_name' => trim($this->quoteCustomerName) ?: null,
                'customer_contact' => trim($this->quoteCustomerContact) ?: null,
                'notes' => trim($this->quoteNotes) ?: null,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_percentage' => $discountPct,
                'discount_amount' => $discountAmt,
                'discount_type' => $discountPct > 0 ? $this->quoteDiscountType : null,
                'discount_reason' => $discountPct > 0 ? trim($this->quoteDiscountReason) : null,
                'discount_authorized_by' => (auth()->user()?->isManagerOrAbove() && $discountPct > 0) ? auth()->id() : null,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmt,
                'total' => $total,
                'valid_until' => now()->addDays((int) $this->quoteValidDays),
            ]);

            foreach ($this->cart as $line) {
                $q->items()->create([
                    'product_id' => $line['type'] === 'normal' ? $line['product_id'] : null,
                    'description' => $line['description'],
                    'package' => $line['package'] ?? null,
                    'sku' => $line['sku'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                    'mix_data' => $line['type'] === 'custom_mix' ? $line : null,
                ]);
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'quotation_created',
                'auditable_type' => Quotation::class,
                'auditable_id' => $q->id,
                'context' => [
                    'quote_number' => $q->quote_number, 
                    'total' => $total,
                    'discount_percentage' => $discountPct,
                    'discount_type' => $this->quoteDiscountType,
                    'discount_reason' => $this->quoteDiscountReason,
                ],
            ]);

            return $q;
        });

        $this->showQuoteModal = false;
        $this->clearCart();

        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} saved."]);
        $this->redirectRoute('sales.quotations');
    }

    // ── Load Quote into Cart ────────────────────────────────────────────────────

    public function openLoadQuoteModal(): void
    {
        $this->showLoadQuoteModal = true;
        $this->loadQuoteSearch = '';
    }

    public function loadQuote(int $quoteId): void
    {
        $quote = Quotation::with('items.product.packageUnit')->findOrFail($quoteId);

        if ($quote->isConverted()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'This quotation has already been converted to a sale.']);
            return;
        }

        $this->cart = [];
        foreach ($quote->items as $item) {
            if ($item->mix_data) {
                $mix = $item->mix_data;
                $key = 'mix:' . Str::uuid();
                $this->cart[$key] = array_merge($mix, [
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
                $this->cart[$key] = [
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
            'pos.cart' => $this->cart,
            'pos.quotation_id' => $quote->id,
            'pos.customer_name' => $quote->customer_name,
            'pos.customer_contact' => $quote->customer_contact,
            'pos.discount_percentage' => (string) ($quote->discount_percentage ?? '0'),
            'pos.discount_type' => $quote->discount_type ?? 'none',
            'pos.discount_reason' => $quote->discount_reason ?? '',
            'pos.discount_authorized_by' => $quote->discount_authorized_by,
        ]);
        $this->showLoadQuoteModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} loaded into cart."]);
    }

    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventory'])
            ->where('active', true)
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('sku', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->brandId, fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->packageUnitId, fn ($q) => $q->where('package_unit_id', $this->packageUnitId))
            ->when($this->availableOnly, fn ($q) => $q->whereHas('inventory', fn ($iq) => $iq->where('quantity', '>', 0)))
            ->orderBy('name')
            ->paginate(14);

        $loadQuotes = null;
        if ($this->showLoadQuoteModal) {
            $loadQuotes = Quotation::query()
                ->whereNull('converted_sale_id')
                ->whereNotIn('status', ['cancelled'])
                ->when($this->loadQuoteSearch, function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('quote_number', 'like', '%' . $this->loadQuoteSearch . '%')
                            ->orWhere('customer_name', 'like', '%' . $this->loadQuoteSearch . '%');
                    });
                })
                ->latest()
                ->limit(10)
                ->get();
        }

        $mixingCategoriesExist = Category::where('is_for_mixing', true)->exists();
        $stockMaterialsQuery = Product::query()
            ->with(['packageUnit', 'category', 'brand'])
            ->where('active', true);

        if ($mixingCategoriesExist) {
            $stockMaterialsQuery->whereHas('category', fn ($q) => $q->where('is_for_mixing', true));
        }

        return view('livewire.pages.sales.index', [
            'products' => $products,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => PackageUnit::where('active', true)->orderBy('name')->get(),
            'stockMaterials' => $stockMaterialsQuery->orderBy('name')->get(),
            'currency' => Currency::class,
            'loadQuotes' => $loadQuotes,
            'cartSubtotal' => $this->cartSubtotal(),
        ]);
    }
}; ?>

<div class="space-y-3 w-full min-w-0" x-data="{ quoteDiscount: $wire.entangle('quoteDiscountPercentage'), quoteTax: $wire.entangle('quoteTaxApplied') }">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Point of Sale</h1>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="openLoadQuoteModal" type="button"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span>Load Quote</span>
            </button>
            <button wire:click="openMixModal" type="button"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                <span>Custom Mix</span>
            </button>
        </div>
    </div>

    {{-- Main POS Container --}}
    <div class="flex flex-col text-xs rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden h-[calc(100vh-9.5rem)] min-h-[500px]">
        {{-- Main Layout: Left = Product Browser, Right = Cart --}}
        <div class="flex flex-1 min-h-0 overflow-hidden">

        {{-- LEFT: Product Browser --}}
        <div class="flex w-[55%] flex-col border-r border-slate-200 bg-slate-50">

            {{-- Filter Bar --}}
            <div class="border-b border-slate-200 bg-white px-3 py-1.5 space-y-1.5">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <svg class="absolute left-2.5 top-2 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input wire:model.live.debounce.200ms="search" type="search" placeholder="Search by product name or SKU..."
                            class="w-full rounded border-slate-300 pl-8 pr-3 py-1 text-xs text-slate-900 focus:border-slate-500 focus:ring-0" />
                    </div>
                    <label class="flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none">
                        <input wire:model.live="availableOnly" type="checkbox" class="rounded border-slate-300 text-[#00a3cc] focus:ring-[#00a3cc]" />
                        In Stock Only
                    </label>
                </div>
                <div class="flex items-center gap-2">
                    <select wire:model.live="brandId" class="flex-1 rounded border-slate-300 text-xs py-1 focus:border-slate-500 focus:ring-0">
                        <option value="">All Brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="categoryId" class="flex-1 rounded border-slate-300 text-xs py-1 focus:border-slate-500 focus:ring-0">
                        <option value="">All Categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="packageUnitId" class="flex-1 rounded border-slate-300 text-xs py-1 focus:border-slate-500 focus:ring-0">
                        <option value="">All Units</option>
                        @foreach ($packageUnits as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->abbreviation }}</option>
                        @endforeach
                    </select>
                    @if ($search || $brandId || $categoryId || $packageUnitId)
                        <button wire:click="resetFilters" type="button" class="text-[11px] text-rose-600 hover:underline whitespace-nowrap">Reset</button>
                    @endif
                </div>
            </div>

            {{-- Product Table --}}
            <div class="flex-1 overflow-y-auto">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="sticky top-0 bg-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-700 z-10 border-b border-slate-300">
                        <tr>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">SKU</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Product Name</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center">Unit</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-left">Brand</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right">Stock</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-right">Price</th>
                            <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($products as $product)
                            @php
                                $stock = (float) ($product->inventory?->quantity ?? 0);
                                $isOut = $stock <= 0;
                                $isLow = !$isOut && $stock <= (float) $product->low_stock_threshold;
                                $isSelected = $selectedProductId == $product->id;
                            @endphp
                            <tr wire:click="selectProduct({{ $product->id }})"
                                class="cursor-pointer transition-colors {{ $isSelected ? 'bg-cyan-50' : 'hover:bg-slate-50' }} {{ $isOut ? 'opacity-50' : '' }}"
                                wire:key="prod-{{ $product->id }}">
                                <td class="border border-slate-200 px-2.5 py-1.5 font-mono text-slate-600 whitespace-nowrap">{{ $product->sku }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 max-w-[190px]">
                                    <span class="line-clamp-1 block" title="{{ $product->name }}">{{ $product->name }}</span>
                                    @if ($product->category)
                                        <span class="text-[10px] text-slate-400">{{ $product->category->name }}</span>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-700 whitespace-nowrap font-medium">
                                    {{ $product->formattedPackage() }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">{{ $product->brand?->name ?? '—' }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap">
                                    @if ($isOut)
                                        <span class="text-rose-600 font-semibold">Out</span>
                                    @elseif ($isLow)
                                        <span class="text-amber-600 font-bold">{{ number_format($stock, 2) }}</span>
                                    @else
                                        <span class="text-emerald-700 font-medium">{{ number_format($stock, 2) }}</span>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-semibold text-slate-900 whitespace-nowrap">{{ $currency::format($product->selling_price) }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if (!$isOut)
                                        <button wire:click.stop="quickAddToCart({{ $product->id }})" type="button"
                                            title="Add 1 to cart"
                                            class="inline-flex items-center gap-1 rounded bg-[#00a3cc] px-2 py-0.5 text-[11px] font-bold text-white hover:bg-[#008fb3] transition shadow-xs">
                                            <span>+ Add</span>
                                        </button>
                                    @else
                                        <span class="text-[10px] text-slate-400 italic">None</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                    <p class="font-medium">No products found.</p>
                                    <button wire:click="resetFilters" type="button" class="mt-2 text-[#00a3cc] hover:underline text-[11px]">Clear filters</button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($products->hasPages())
                <div class="border-t border-slate-200 bg-white px-3 py-1.5">
                    {{ $products->links() }}
                </div>
            @endif

            {{-- Add-to-Cart Bar (pinned at bottom) --}}
            <div class="border-t border-slate-300 bg-white px-3 py-2">
                <form wire:submit="addToCart" class="flex items-center gap-2">
                    <div class="flex-1 min-w-0">
                        @if ($selectedProductId)
                            @php
                                $selProd = $products->firstWhere('id', (int)$selectedProductId)
                                    ?? \App\Models\Product::with('packageUnit')->find((int)$selectedProductId);
                            @endphp
                            <div class="flex items-center gap-2 rounded border border-[#00a3cc] bg-cyan-50 px-2 py-1">
                                <svg class="h-3.5 w-3.5 text-[#008fb3] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span class="font-semibold text-[#00a3cc] truncate">{{ $selProd?->name ?? 'Selected' }}</span>
                                <span class="text-[10px] text-slate-500 font-normal">({{ $selProd?->formattedPackage() }})</span>
                                <button wire:click="$set('selectedProductId', '')" type="button" class="ml-auto text-slate-400 hover:text-slate-600 text-base leading-none">&times;</button>
                            </div>
                        @else
                            <div class="rounded border border-slate-200 bg-slate-50 px-2 py-1 text-slate-400 italic text-[11px]">
                                ← Click "+ Add" on any item, or select a row to enter custom quantity
                            </div>
                        @endif
                        @error('selectedProductId')<p class="mt-0.5 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="w-24 shrink-0">
                        <div class="relative">
                            <input wire:model="addQty" type="number" step="1" min="1" placeholder="Qty"
                                class="w-full rounded border-slate-300 text-xs tabular-nums text-slate-900 focus:border-[#00a3cc] focus:ring-0 py-1 pr-2" />
                            @if ($selectedProductId && ($selProd?->packageUnit?->abbreviation))
                                <span class="absolute inset-y-0 right-2 flex items-center pointer-events-none text-[10px] text-slate-400 font-semibold">{{ $selProd->packageUnit->abbreviation }}</span>
                            @endif
                        </div>
                        @error('addQty')<p class="mt-0.5 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit"
                        class="inline-flex items-center gap-1 rounded bg-[#00a3cc] px-3.5 py-1 text-xs font-bold text-white hover:bg-[#008fb3] transition shadow-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add
                    </button>
                </form>
            </div>
        </div>

        {{-- RIGHT: Cart --}}
        <div class="flex w-[45%] flex-col bg-white">
            {{-- Cart Header --}}
            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="font-bold uppercase tracking-wider text-slate-700 text-[11px]">Current Order</span>
                    @if (!empty($cart))
                        <span class="rounded-full bg-[#00a3cc] text-white text-[10px] font-bold px-1.5 py-0.5 leading-none">{{ count($cart) }}</span>
                    @endif
                </div>
                @if (!empty($cart))
                    <button wire:click="clearCart" wire:confirm="Clear the entire cart?" type="button"
                        class="text-[11px] text-rose-600 hover:underline">Clear All</button>
                @endif
            </div>

            {{-- Cart Items --}}
            <div class="flex-1 overflow-y-auto">
                @if (empty($cart))
                    <div class="flex flex-col items-center justify-center h-full text-slate-400 py-16 px-6 text-center">
                        <svg class="h-12 w-12 text-slate-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <p class="font-medium text-sm text-slate-500">Cart is empty</p>
                        <p class="text-[11px] mt-1 text-slate-400">Select a product from the catalog on the left and click Add.</p>
                    </div>
                @else
                    <table class="w-full border-collapse text-xs">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="border-b border-slate-100 px-3 py-1.5 text-left">Item</th>
                                <th class="border-b border-slate-100 px-2 py-1.5 text-center w-24">Qty</th>
                                <th class="border-b border-slate-100 px-2 py-1.5 text-right w-20">Unit Price</th>
                                <th class="border-b border-slate-100 px-2 py-1.5 text-right w-20">Subtotal</th>
                                <th class="border-b border-slate-100 px-1 py-1.5 w-6"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($cart as $key => $line)
                                <tr class="hover:bg-slate-50 transition-colors" wire:key="cart-{{ $key }}">
                                    <td class="px-3 py-2">
                                        <div class="font-semibold text-slate-900 line-clamp-1">{{ $line['description'] }}</div>
                                        <div class="text-[10px] text-slate-400 mt-0.5">
                                            @if ($line['type'] === 'custom_mix')
                                                <span class="text-purple-600 font-semibold">Custom Mix</span> · {{ $line['package'] ?? '' }}
                                            @else
                                                {{ $line['sku'] ?? '' }}{{ $line['package'] ? ' · ' . $line['package'] : '' }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 text-center whitespace-nowrap">
                                        @if ($line['type'] === 'custom_mix')
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                value="{{ (float) $line['quantity'] }}"
                                                wire:change="updateCartQty('{{ $key }}', $event.target.value)"
                                                class="w-16 rounded border-slate-300 text-center text-xs tabular-nums font-bold text-slate-900 focus:border-[#00a3cc] focus:ring-0 py-1"
                                            />
                                        @else
                                            <div class="inline-flex items-center rounded border border-slate-300 bg-white shadow-xs">
                                                <button type="button"
                                                    wire:click="updateCartQty('{{ $key }}', '{{ (int)$line['quantity'] - 1 }}')"
                                                    title="Decrease quantity"
                                                    class="px-2 py-1 text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition rounded-l text-xs font-bold leading-none">
                                                    −
                                                </button>
                                                <input
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    value="{{ (int) $line['quantity'] }}"
                                                    wire:change="updateCartQty('{{ $key }}', $event.target.value)"
                                                    class="w-10 border-0 p-0 text-center text-xs tabular-nums font-bold text-slate-900 focus:ring-0 py-1"
                                                />
                                                <button type="button"
                                                    wire:click="updateCartQty('{{ $key }}', '{{ (int)$line['quantity'] + 1 }}')"
                                                    title="Increase quantity"
                                                    class="px-2 py-1 text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition rounded-r text-xs font-bold leading-none">
                                                    +
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums text-slate-600 whitespace-nowrap">{{ $currency::format($line['unit_price']) }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">{{ $currency::format($line['subtotal']) }}</td>
                                    <td class="px-1 py-2 text-center">
                                        @if ($line['type'] === 'custom_mix')
                                            <button wire:click="editMix('{{ $key }}')" type="button" class="text-slate-400 hover:text-slate-700 block mb-0.5" title="Edit mix">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>
                                        @endif
                                        <button wire:click="removeFromCart('{{ $key }}')" type="button" class="text-slate-300 hover:text-rose-500 transition" title="Remove">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Order Totals & Actions --}}
            @if (!empty($cart))
                <div class="border-t border-slate-200 bg-slate-50 px-3 py-2 space-y-1.5">
                    {{-- Subtotal --}}
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-600">Subtotal ({{ count($cart) }} item{{ count($cart) > 1 ? 's' : '' }})</span>
                        <span class="font-bold tabular-nums text-slate-900">{{ $currency::format($cartSubtotal) }}</span>
                    </div>
                    <div class="text-[10px] text-slate-400 italic">Tax (12% VAT) & discounts applied at checkout.</div>

                    {{-- Action Buttons --}}
                    <div class="grid grid-cols-2 gap-2 pt-0.5">
                        <button wire:click="openQuoteModal" type="button"
                            class="inline-flex items-center justify-center gap-1.5 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-xs transition">
                            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Save Quote
                        </button>
                        <button wire:click="proceedToCheckout" type="button"
                            class="inline-flex items-center justify-center gap-1.5 rounded bg-[#00a3cc] px-2.5 py-1.5 text-xs font-bold text-white hover:bg-[#008fb3] shadow-xs transition">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            Checkout
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

    {{-- ── QUOTATION SAVE MODAL ──────────────────────────────────────────────── --}}
    @if ($showQuoteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-md rounded border border-slate-300 bg-white shadow-xl text-xs overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between">
                    <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Save as Quotation</h2>
                    <button wire:click="$set('showQuoteModal', false)" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>
                <div class="p-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Customer Name</label>
                            <input wire:model="quoteCustomerName" type="text" placeholder="Optional"
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Contact / Phone</label>
                            <input wire:model="quoteCustomerContact" type="text" placeholder="Optional"
                                class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Valid For (days)</label>
                            <input wire:model="quoteValidDays" type="number" min="1" max="365"
                                class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-slate-500 focus:ring-0" />
                            @error('quoteValidDays')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Discount Scheme</label>
                            <div class="grid grid-cols-3 gap-1 text-[11px] mb-2">
                                <button type="button" wire:click="setQuoteDiscountPreset('none')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'none' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    None (0%)
                                </button>
                                <button type="button" wire:click="setQuoteDiscountPreset('regular')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'regular' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    Regular (5%)
                                </button>
                                <button type="button" wire:click="setQuoteDiscountPreset('volume')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'volume' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    Bulk (10%)
                                </button>
                                <button type="button" wire:click="setQuoteDiscountPreset('employee')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'employee' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    Employee (15%)
                                </button>
                                <button type="button" wire:click="setQuoteDiscountPreset('senior_pwd')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'senior_pwd' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    Senior/PWD (20%)
                                </button>
                                <button type="button" wire:click="setQuoteDiscountPreset('custom')"
                                    class="rounded border px-1.5 py-1 font-medium transition text-center {{ $quoteDiscountType === 'custom' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    Custom %
                                </button>
                            </div>
                            @if ($quoteDiscountType === 'custom')
                                <div class="relative mb-2">
                                    <input wire:model.live="quoteDiscountPercentage" type="number" step="0.5" min="0" max="100" placeholder="0"
                                        class="w-full rounded border-slate-300 text-xs tabular-nums pr-7 focus:border-slate-500 focus:ring-0" />
                                    <span class="absolute inset-y-0 right-2 flex items-center text-slate-400 font-bold text-[11px] pointer-events-none">%</span>
                                </div>
                            @endif
                            @if ((float) $quoteDiscountPercentage > 0)
                                <div class="space-y-0.5">
                                    <label class="block font-semibold text-slate-700 text-[11px]">Discount Reason / Reference <span class="text-rose-600">*</span></label>
                                    <input wire:model="quoteDiscountReason" type="text" placeholder="e.g. Employee ID, Senior Citizen ID #, Contractor account..."
                                        class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                                    @error('quoteDiscountReason')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model.live="quoteTaxApplied" type="checkbox" class="rounded border-slate-300 text-[#00a3cc] focus:ring-[#00a3cc]" />
                            <span class="font-semibold text-slate-700">Apply 12% VAT</span>
                        </label>
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Notes</label>
                        <textarea wire:model="quoteNotes" rows="2" placeholder="Internal notes or special instructions..."
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0"></textarea>
                    </div>

                    {{-- Quote Amount Preview --}}
                    @php
                        $qSubtotal = $cartSubtotal;
                        $qDiscPct = (float) $quoteDiscountPercentage;
                        $qDiscAmt = round($qSubtotal * ($qDiscPct / 100), 2);
                        $qAfterDisc = round($qSubtotal - $qDiscAmt, 2);
                        $qTaxAmt = $quoteTaxApplied ? round($qAfterDisc * 0.12, 2) : 0;
                        $qTotal = round($qAfterDisc + $qTaxAmt, 2);
                    @endphp
                    <div class="rounded bg-slate-50 border border-slate-200 p-3 space-y-1 tabular-nums">
                        <div class="flex justify-between text-slate-600">
                            <span>Subtotal:</span><span>{{ $currency::format($qSubtotal) }}</span>
                        </div>
                        @if ($qDiscAmt > 0)
                            <div class="flex justify-between text-emerald-700">
                                <span>Discount ({{ number_format($qDiscPct, 2) }}%):</span>
                                <span>-{{ $currency::format($qDiscAmt) }}</span>
                            </div>
                        @endif
                        @if ($qTaxAmt > 0)
                            <div class="flex justify-between text-slate-600">
                                <span>VAT (12%):</span><span>{{ $currency::format($qTaxAmt) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between font-bold text-slate-900 border-t border-slate-200 pt-1">
                            <span>Quotation Total:</span><span>{{ $currency::format($qTotal) }}</span>
                        </div>
                    </div>

                    <div class="flex gap-2 pt-1">
                        <button wire:click="$set('showQuoteModal', false)" type="button"
                            class="flex-1 rounded border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button wire:click="saveAsQuotation" type="button" wire:loading.attr="disabled"
                            class="flex-1 rounded bg-slate-800 px-3 py-2 text-xs font-bold text-white hover:bg-slate-900 transition disabled:opacity-50">
                            <span wire:loading.remove wire:target="saveAsQuotation">Save Quotation</span>
                            <span wire:loading wire:target="saveAsQuotation">Saving...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── LOAD QUOTE MODAL ─────────────────────────────────────────────────── --}}
    @if ($showLoadQuoteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-lg rounded border border-slate-300 bg-white shadow-xl text-xs overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between">
                    <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Load Quotation into Cart</h2>
                    <button wire:click="$set('showLoadQuoteModal', false)" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>
                <div class="p-4">
                    <div class="mb-3">
                        <input wire:model.live.debounce.200ms="loadQuoteSearch" type="search" placeholder="Search by quote number or customer name..."
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                    </div>
                    @if ($loadQuotes && $loadQuotes->count())
                        <div class="divide-y divide-slate-100 border border-slate-200 rounded">
                            @foreach ($loadQuotes as $q)
                                <div class="flex items-center justify-between px-3 py-2.5 hover:bg-slate-50">
                                    <div>
                                        <div class="font-semibold text-slate-900">{{ $q->quote_number }}</div>
                                        <div class="text-slate-500 text-[11px]">
                                            {{ $q->customer_name ?? 'No customer' }}
                                            · {{ $q->items->count() ?? '?' }} items
                                            · {{ $currency::format($q->total) }}
                                            @if ($q->valid_until)
                                                · Valid until {{ $q->valid_until->format('M d, Y') }}
                                                @if ($q->isExpired()) <span class="text-rose-600 font-semibold">(Expired)</span> @endif
                                            @endif
                                        </div>
                                    </div>
                                    <button wire:click="loadQuote({{ $q->id }})" type="button"
                                        class="rounded border border-[#00a3cc] text-[#00a3cc] bg-white px-3 py-1 text-[11px] font-bold hover:bg-cyan-50">
                                        Load
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($loadQuotes && $loadQuotes->isEmpty())
                        <p class="py-6 text-center text-slate-400">No open quotations found.</p>
                    @else
                        <p class="py-6 text-center text-slate-400">Search for a quotation above.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ── CUSTOM MIX MODAL ─────────────────────────────────────────────────── --}}
    @if ($showMixModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-xl border border-slate-300 bg-white shadow-2xl text-xs overflow-hidden">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3">
                    <div>
                        <h2 class="font-heading text-sm font-bold text-slate-900">
                            {{ $editingCartKey ? 'Edit Custom Paint Mixture' : 'Custom Paint Mix Studio' }}
                        </h2>
                        <p class="text-[11px] text-slate-500">Formulate custom mixtures using designated paint mixing stocks.</p>
                    </div>
                    <button wire:click="resetMixForm" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700 leading-none">&times;</button>
                </div>

                <!-- Modal Body: 2 Clean Side-by-Side Panels -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-5 p-5 overflow-y-auto flex-1">
                    <!-- Left Panel: Mixture Definition & Pricing Summary -->
                    <div class="md:col-span-5 space-y-4 flex flex-col justify-between border-b md:border-b-0 md:border-r border-slate-200 pb-4 md:pb-0 md:pr-5">
                        <div class="space-y-3.5">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Mixture Name / Description</label>
                                <input wire:model="mixDescription" type="text" placeholder="e.g. Custom Auto Metallic Silver"
                                    class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                @error('mixDescription') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block font-semibold text-slate-700 mb-1">Batch Output Qty</label>
                                    <input wire:model="mixQuantity" type="number" step="0.01" min="0.01"
                                        class="w-full rounded border-slate-300 tabular-nums text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                    @error('mixQuantity') <p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block font-semibold text-slate-700 mb-1">Resulting Unit</label>
                                    <select wire:model="mixResultUnit" class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                        <option value="L">Liter (L)</option>
                                        <option value="gal">Gallon (gal)</option>
                                        <option value="ml">Milliliter (ml)</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Formula & Mixing Notes</label>
                                <textarea wire:model="mixNotes" rows="2" placeholder="e.g. 50% primer base + 250ml tinting black..."
                                    class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"></textarea>
                            </div>

                            <!-- Live Recipe Summary Box -->
                            @php
                                $basis = $this->currentBasisProduct();
                                $qty = (float) ($mixQuantity ?: 1);
                                $unitPrice = $basis ? (float) $basis['price'] : 0.0;
                                $estimatedTotal = $qty * $unitPrice;
                            @endphp
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-1.5">
                                <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Formula Summary</span>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600">Ingredients Count:</span>
                                    <span class="font-bold text-slate-900 tabular-nums">{{ count($mixComponents) }} item(s)</span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600">Price Basis:</span>
                                    <span class="font-medium text-slate-900 truncate max-w-[150px]" title="{{ $basis['product_name'] ?? '—' }}">
                                        {{ $basis['sku'] ?? '—' }} ({{ \App\Support\Currency::format($unitPrice) }})
                                    </span>
                                </div>
                                <div class="flex items-center justify-between text-xs pt-1 border-t border-slate-200">
                                    <span class="font-bold text-slate-800">Estimated Total:</span>
                                    <span class="font-bold text-sm text-[#008fb3] tabular-nums">{{ \App\Support\Currency::format($estimatedTotal) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="pt-2 space-y-2">
                            <button wire:click="addMixToCart" type="button"
                                class="w-full rounded bg-[#00a3cc] py-2 text-xs font-bold text-white hover:bg-[#008fb3] transition shadow-xs">
                                {{ $editingCartKey ? 'Update Mixture in Cart' : 'Add Mixture to POS Cart' }}
                            </button>
                            <button wire:click="addMixAndOpenQuote" type="button"
                                class="w-full rounded border border-slate-300 bg-white py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition shadow-xs flex items-center justify-center gap-1.5">
                                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span>Add &amp; Save as Quotation</span>
                            </button>
                            <div class="flex items-center gap-2">
                                <button wire:click="resetMixForm" type="button"
                                    class="flex-1 rounded border border-slate-300 bg-white py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">
                                    Clear & Start Over
                                </button>
                                <button wire:click="$set('showMixModal', false)" type="button"
                                    class="rounded border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-500 hover:text-slate-700 transition">
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Right Panel: Mixing Materials & Formula Table -->
                    <div class="md:col-span-7 space-y-3.5 flex flex-col justify-between">
                        <div class="space-y-3">
                            <div>
                                <span class="block font-semibold text-slate-800 text-xs mb-1.5">Add Mixing Material (Mixing Category Only)</span>
                                <div class="grid items-end gap-2 grid-cols-1 sm:grid-cols-[1fr_90px_80px_auto]">
                                    <div>
                                        <select wire:model="mixProductId" class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                            <option value="">Select mixing paint...</option>
                                            @foreach ($stockMaterials as $mat)
                                                <option value="{{ $mat->id }}">
                                                    {{ $mat->sku }} · {{ $mat->name }} ({{ \App\Support\Currency::format($mat->selling_price) }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <input wire:model="mixEstimatedQuantity" type="number" step="0.01" min="0.01" placeholder="Qty"
                                            class="w-full rounded border-slate-300 tabular-nums text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]" />
                                    </div>
                                    <div>
                                        <select wire:model="mixEstimatedQuantityUnit" class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]">
                                            <option value="L">L</option>
                                            <option value="ml">ml</option>
                                            <option value="gal">gal</option>
                                        </select>
                                    </div>
                                    <div>
                                        <button wire:click="addMixComponent" type="button"
                                            class="w-full rounded bg-slate-900 px-3 py-1.5 font-semibold text-white hover:bg-slate-800 transition shadow-xs text-xs">
                                            + Add
                                        </button>
                                    </div>
                                </div>
                                @error('mixProductId') <p class="text-rose-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                                @error('mixEstimatedQuantity') <p class="text-rose-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                                @error('mixComponents') <p class="text-rose-600 text-[11px] mt-1">{{ $message }}</p> @enderror
                            </div>

                            <!-- Formula Ingredients Table -->
                            <div>
                                <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Formula Recipe Ingredients</span>
                                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <div class="overflow-x-auto max-h-[260px] overflow-y-auto">
                                        <table class="w-full text-left text-xs border-collapse">
                                            <thead class="bg-slate-100 text-[10px] font-semibold uppercase text-slate-700 tracking-wider sticky top-0 border-b border-slate-200">
                                                <tr>
                                                    <th class="px-3 py-2 whitespace-nowrap">SKU</th>
                                                    <th class="px-3 py-2">Material Name</th>
                                                    <th class="px-3 py-2 text-right whitespace-nowrap">Est. Qty</th>
                                                    <th class="px-3 py-2 text-right whitespace-nowrap">Price</th>
                                                    <th class="px-2 py-2 text-center w-10"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200">
                                                @forelse ($mixComponents as $idx => $comp)
                                                    @php
                                                        $isBasis = $basis && $basis['product_id'] == $comp['product_id'];
                                                    @endphp
                                                    <tr class="hover:bg-slate-50 transition">
                                                        <td class="px-3 py-2 font-mono text-slate-600 tabular-nums whitespace-nowrap">{{ $comp['sku'] }}</td>
                                                        <td class="px-3 py-2 font-medium text-slate-900">
                                                            {{ $comp['product_name'] }}
                                                            @if ($isBasis)
                                                                <span class="ml-1 rounded bg-amber-100 text-amber-800 px-1 py-0.5 text-[9px] font-bold uppercase">Price Basis</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-3 py-2 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                                            {{ number_format((float) $comp['estimated_quantity'], 2) }} {{ $comp['estimated_quantity_unit'] }}
                                                        </td>
                                                        <td class="px-3 py-2 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                                            {{ \App\Support\Currency::format($comp['price']) }}
                                                        </td>
                                                        <td class="px-2 py-2 text-center whitespace-nowrap">
                                                            <button wire:click="removeMixComponent({{ $idx }})" type="button"
                                                                title="Remove ingredient"
                                                                class="text-slate-400 hover:text-rose-600 transition p-1">
                                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                                                            <p class="font-medium text-xs">No mixing ingredients added yet.</p>
                                                            <p class="text-[11px] text-slate-400 mt-0.5">Select a mixing paint above to begin formulating.</p>
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="text-[11px] text-slate-400 italic">
                            * Custom mixtures use the highest-priced component material as the formula price basis.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
