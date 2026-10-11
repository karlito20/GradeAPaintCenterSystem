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
    public bool $showAddToCartModal = false;
    public string $selectedProductId = '';
    public string $purchaseMode = 'whole'; // 'whole' or 'repack'
    public string $repackFraction = '0.25'; // '1', '0.5', '0.25', '0.125', '0.0625'
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
    public bool $quoteTaxApplied = false;

    // Custom Mix Modal
    public bool $showMixModal = false;
    public string $mixCustomerName = '';
    public string $mixCustomerContact = '';
    public string $mixBrandId = '';
    public string $mixCustomColor = '';
    public string $mixQuantity = '1';
    public string $mixResultUnit = 'gal';
    public string $mixProductId = '';
    public string $mixNotes = '';
    public array $mixComponents = [];
    public string $mixDownpaymentAmount = '';
    public string $mixTenderedAmount = '';

    // Load quote modal
    public bool $showLoadQuoteModal = false;
    public string $loadQuoteSearch = '';

    // Mixing Orders Modal
    public bool $showMixingOrdersModal = false;

    public const TAX_RATE = 0.0;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessPos(), 403, 'Mixers cannot access Point of Sale.');
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

    public function selectUnitSize(string $mode, string $fraction = '1'): void
    {
        $this->purchaseMode = $mode;
        $this->repackFraction = $fraction;
    }

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = (string) $productId;
        $this->purchaseMode = 'whole';
        $this->repackFraction = '1';
        $this->addQty = '1';
        $this->resetValidation(['selectedProductId', 'addQty', 'productId', 'purchaseMode', 'repackFraction']);
    }

    public function openAddToCartModal(int $productId): void
    {
        $this->selectedProductId = (string) $productId;
        $this->purchaseMode = 'whole';
        $this->repackFraction = '1';
        $this->addQty = '1';
        $this->resetValidation(['selectedProductId', 'addQty', 'productId', 'purchaseMode', 'repackFraction']);
        $this->showAddToCartModal = true;
    }

    public function closeAddToCartModal(): void
    {
        $this->showAddToCartModal = false;
        $this->resetValidation(['selectedProductId', 'addQty', 'productId', 'purchaseMode', 'repackFraction']);
    }

    public function hasCustomMixInCart(): bool
    {
        return collect($this->cart)->contains('type', 'custom_mix');
    }

    public function addToCart(): void
    {
        $this->validate([
            'selectedProductId' => ['required', 'exists:products,id'],
            'addQty' => ['required', 'numeric', 'min:1', 'max:99999'],
        ], [
            'selectedProductId.required' => 'Select a product first.',
            'addQty.numeric' => 'Quantity must be a valid number.',
            'addQty.min' => 'Quantity must be at least 1.',
            'addQty.max' => 'Quantity cannot exceed 99,999.',
        ]);

        if ($this->hasCustomMixInCart()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Your cart has an active custom mix order. Custom mixes cannot be combined with standard retail items.',
            ]);
            return;
        }

        $product = Product::with(['inventories', 'packageUnit', 'brand', 'category'])->where('active', true)->findOrFail((int) $this->selectedProductId);
        $qty = (float) $this->addQty;

        $isRepack = ($this->purchaseMode === 'repack');

        if ($isRepack) {
            if (! $product->canBeRepacked()) {
                $this->addError('purchaseMode', 'This product cannot be repacked. Only gallon paint products can be repacked into fractions.');
                return;
            }

            $fraction = (float) ($this->repackFraction ?: '0.25');
            if ($fraction >= 1.0 && $this->purchaseMode === 'repack') {
                $fraction = 0.25;
            }

            $litersPerUnit = $fraction * 4.0;
            $totalLiters = $qty * $litersPerUnit;
            $galNeeded = $totalLiters * 0.25;
            $storefrontAvailable = $product->getStorefrontStock();

            $alreadyInCartGal = collect($this->cart)
                ->where('product_id', $product->id)
                ->where('type', 'repack')
                ->sum(fn ($item) => (float) ($item['total_liters'] ?? ((float) $item['quantity'] * 1.0)) * 0.25);

            if (($alreadyInCartGal + $galNeeded) > $storefrontAvailable) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => "Insufficient Storefront stock for repack. Needs {$galNeeded} gal, available in storefront: {$storefrontAvailable} gal. Please transfer stock from storage first.",
                ]);
                $this->addError('addQty', "Requires {$galNeeded} gal from storefront. Available: {$storefrontAvailable} gal. Please transfer from storage.");
                return;
            }

            $unitPrice = round((float) $product->selling_price * $fraction, 2);

            $fractionLabel = match ((string) $fraction) {
                '0.5' => '1/2 Gal (2L)',
                '0.25' => '1/4 Gal (1L)',
                '0.125' => '1/8 Gal (1/2L)',
                '0.0625' => '1/16 Gal (1/4L)',
                default => $litersPerUnit . 'L',
            };

            $key = ($fraction === 0.25)
                ? 'product:' . $product->id . ':repack'
                : 'product:' . $product->id . ':repack:' . str_replace('.', '_', (string) $fraction);

            $currentQty = (float) ($this->cart[$key]['quantity'] ?? 0);
            $newQty = $currentQty + $qty;

            $this->cart[$key] = [
                'type' => 'repack',
                'product_id' => $product->id,
                'description' => $product->name . ' (' . $fractionLabel . ' Repack)',
                'package' => $fractionLabel . ' Repack (' . $litersPerUnit . 'L from 4L Gallon)',
                'brand' => $product->brand?->name ?? '—',
                'unit' => $fractionLabel,
                'sku' => $product->sku,
                'quantity' => $newQty,
                'liters_per_unit' => $litersPerUnit,
                'total_liters' => $newQty * $litersPerUnit,
                'unit_price' => $unitPrice,
                'subtotal' => round($newQty * $unitPrice, 2),
            ];

            session(['pos.cart' => $this->cart]);
            $this->showAddToCartModal = false;
            $this->reset(['selectedProductId', 'addQty', 'productId']);
            $this->addQty = '1';
            $this->repackFraction = '0.25';
            $this->purchaseMode = 'whole';
            $this->dispatch('toast', ['type' => 'success', 'message' => "{$product->name} ({$fractionLabel}) added to cart."]);
            return;
        }

        // Standard / Whole product sale: storage first, then storefront
        $totalAvailable = $product->getTotalAvailableStock();
        $alreadyInCart = collect($this->cart)
            ->where('product_id', $product->id)
            ->where('type', 'normal')
            ->sum('quantity');

        if (($alreadyInCart + $qty) > $totalAvailable) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => "Stock limit reached: only {$totalAvailable} total available for {$product->name}.",
            ]);
            $this->addError('addQty', "Only {$totalAvailable} total available across storage and storefront.");
            $this->addError('productId', "Only {$totalAvailable} total available.");
            return;
        }

        $unitPrice = (float) $product->selling_price;
        $key = 'product:' . $product->id;
        $currentQty = (float) ($this->cart[$key]['quantity'] ?? 0);
        $newQty = $currentQty + $qty;

        $this->cart[$key] = [
            'type' => 'normal',
            'product_id' => $product->id,
            'description' => $product->name,
            'package' => $product->formattedPackage(),
            'brand' => $product->brand?->name ?? '—',
            'unit' => $product->packageUnit?->abbreviation ?? 'unit',
            'sku' => $product->sku,
            'quantity' => $newQty,
            'unit_price' => $unitPrice,
            'subtotal' => round($newQty * $unitPrice, 2),
        ];

        session(['pos.cart' => $this->cart]);
        $this->showAddToCartModal = false;
        $this->reset(['selectedProductId', 'addQty', 'productId']);
        $this->addQty = '1';
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$product->name} added to cart."]);
    }

    public function quickAddToCart(int $productId): void
    {
        if ($this->hasCustomMixInCart()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Your cart contains a custom mix order. Please complete or clear it before adding retail items.',
            ]);
            return;
        }

        $product = Product::with(['inventories', 'packageUnit', 'brand'])->where('active', true)->findOrFail($productId);
        $key = 'product:' . $product->id;
        $currentInCart = (float) ($this->cart[$key]['quantity'] ?? 0);
        $totalAvailable = $product->getTotalAvailableStock();

        if ($currentInCart + 1 > $totalAvailable) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => "Stock limit reached: only {$totalAvailable} available for {$product->name}.",
            ]);
            return;
        }

        $this->selectedProductId = (string) $productId;
        $this->purchaseMode = 'whole';
        $this->addQty = '1';
        $this->addToCart();
    }

    public function addProduct(int $productId): void
    {
        $this->quickAddToCart($productId);
    }

    public function updateCartQty(string $key, string $value): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        if (! is_numeric($value)) {
            return;
        }

        $isCustomMix = ($this->cart[$key]['type'] ?? '') === 'custom_mix';
        $isRepack = ($this->cart[$key]['type'] ?? '') === 'repack';
        $qty = $isCustomMix ? (float) $value : round((float) $value);

        if ($qty <= 0) {
            $this->removeFromCart($key);
            return;
        }

        if ($qty > 99999.99) {
            $qty = 99999.99;
        }

        if (! $isCustomMix) {
            $product = Product::with('inventories')->find($this->cart[$key]['product_id']);
            if ($isRepack) {
                $maxLiters = (float) ($product?->getStorefrontStock() * 4);
                if ($qty > $maxLiters) {
                    $this->dispatch('toast', [
                        'type' => 'warning',
                        'message' => "Storefront repack limit reached: only {$maxLiters}L available for {$product?->name}.",
                    ]);
                    $qty = $maxLiters;
                }
            } else {
                $maxAvailable = (float) $product?->getTotalAvailableStock();
                if ($qty > $maxAvailable) {
                    $this->dispatch('toast', [
                        'type' => 'warning',
                        'message' => "Total stock limit reached: only {$maxAvailable} available for {$product?->name}.",
                    ]);
                    $qty = $maxAvailable;
                }
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
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot access checkout.');

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
        if (! empty($this->cart) && ! $this->hasCustomMixInCart()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Custom paint mixtures require a separate downpayment order and cannot be combined with retail items. Please complete or clear your retail cart first.',
            ]);
            return;
        }

        $this->resetMixForm();
        $this->showMixModal = true;
    }

    public function resetMixForm(): void
    {
        $this->showMixModal = false;
        $this->mixCustomerName = '';
        $this->mixCustomerContact = '';
        $this->mixBrandId = '';
        $this->mixCustomColor = '';
        $this->mixQuantity = '1';
        $this->mixResultUnit = 'gal';
        $this->mixProductId = '';
        $this->mixNotes = '';
        $this->mixComponents = [];
        $this->mixDownpaymentAmount = '';
        $this->mixTenderedAmount = '';
        $this->resetValidation();
    }

    public function saveCustomMixDownpayment(): void
    {
        $this->validate([
            'mixCustomerName' => ['required', 'string', 'max:255'],
            'mixCustomerContact' => ['required', 'string', 'max:50', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'mixBrandId' => ['required', 'exists:brands,id'],
            'mixCustomColor' => ['required', 'string', 'max:255'],
            'mixQuantity' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'mixResultUnit' => ['required', 'in:gal,L'],
            'mixDownpaymentAmount' => ['required', 'numeric', 'min:1', 'max:999999.99'],
        ], [
            'mixCustomerName.required' => 'Customer name is required for mixing orders.',
            'mixCustomerContact.required' => 'Customer contact number is required.',
            'mixCustomerContact.regex' => 'Contact number must contain only numbers, dashes, spaces, or parentheses.',
            'mixBrandId.required' => 'Select the brand for this custom mix.',
            'mixCustomColor.required' => 'Custom color name or code is required.',
            'mixQuantity.numeric' => 'Requested quantity must be a valid number.',
            'mixQuantity.max' => 'Quantity cannot exceed 99,999.99.',
            'mixDownpaymentAmount.required' => 'A downpayment is required before mixing begins.',
            'mixDownpaymentAmount.numeric' => 'Downpayment must be a valid number.',
            'mixDownpaymentAmount.min' => 'Downpayment must be at least ₱1.00.',
            'mixDownpaymentAmount.max' => 'Downpayment cannot exceed ₱999,999.99.',
        ]);

        $brand = Brand::findOrFail($this->mixBrandId);
        $qty = (float) $this->mixQuantity;
        $unit = $this->mixResultUnit;
        $downpayment = round((float) $this->mixDownpaymentAmount, 2);

        $sale = null;

        DB::transaction(function () use ($brand, $qty, $unit, $downpayment, &$sale) {
            $sale = Sale::create([
                'user_id' => auth()->id(),
                'customer_name' => trim($this->mixCustomerName),
                'customer_contact' => trim($this->mixCustomerContact),
                'invoice_number' => 'MIX-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'sold_at' => now(),
                'type' => 'mixed',
                'subtotal' => 0.00,
                'total' => 0.00,
                'downpayment_amount' => $downpayment,
                'balance_due' => 0.00,
                'payment_status' => 'downpayment_paid',
                'payment_method' => 'cash',
                'payment_amount' => $downpayment,
                'change_amount' => 0.00,
            ]);

            $sale->items()->create([
                'product_id' => null,
                'description' => 'Custom Mix Request: ' . trim($this->mixCustomColor) . ' (' . $brand->name . ') - ' . $qty . ' ' . $unit,
                'quantity' => $qty,
                'unit_price' => 0.00,
                'subtotal' => 0.00,
            ]);

            MixingTransaction::create([
                'sale_id' => $sale->id,
                'brand_id' => $brand->id,
                'custom_color' => trim($this->mixCustomColor),
                'resulting_quantity' => $qty,
                'resulting_unit' => $unit,
                'notes' => trim($this->mixNotes) ?: null,
                'status' => 'downpaid',
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'custom_mix_requested',
                'auditable_type' => Sale::class,
                'auditable_id' => $sale->id,
                'context' => [
                    'invoice_number' => $sale->invoice_number,
                    'customer' => $sale->customer_name,
                    'brand' => $brand->name,
                    'color' => trim($this->mixCustomColor),
                    'downpayment' => $downpayment,
                    'status' => 'downpaid',
                ],
            ]);
        });

        $this->resetMixForm();
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Order #{$sale->invoice_number} downpayment recorded. Queued for mixing.",
        ]);

        $this->redirectRoute('sales.receipt', ['sale' => $sale]);
    }

    public function updatedMixBrandId(): void
    {
        if ($this->mixBrandId) {
            $this->mixComponents = collect($this->mixComponents)
                ->filter(fn ($comp) => Product::where('id', $comp['product_id'])->where('brand_id', $this->mixBrandId)->exists())
                ->values()
                ->all();
        } else {
            $this->mixComponents = [];
        }
        $this->mixProductId = '';
    }

    public function addMixComponent(): void
    {
        if (! $this->mixBrandId) {
            $this->addError('mixBrandId', 'Please select a brand first.');
            return;
        }

        $this->validate([
            'mixProductId' => ['required', 'exists:products,id'],
        ], [
            'mixProductId.required' => 'Select a mixing material.',
        ]);

        $product = Product::with(['packageUnit', 'category', 'brand'])->find($this->mixProductId);

        if ($product->brand_id != $this->mixBrandId) {
            $this->addError('mixProductId', 'Mixing materials must be strictly of the chosen brand (' . Brand::find($this->mixBrandId)?->name . ').');
            return;
        }

        if (collect($this->mixComponents)->contains('product_id', $product->id)) {
            $this->addError('mixProductId', 'This material is already added to the formula recipe.');
            return;
        }

        $this->mixComponents[] = [
            'product_id' => (int) $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'brand_name' => $product->brand?->name ?? '—',
            'price' => (float) $product->selling_price,
        ];

        $this->reset(['mixProductId']);
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

    public function proceedMixToCheckout(): void
    {
        $this->validate([
            'mixCustomerName' => ['required', 'string', 'max:255'],
            'mixCustomerContact' => ['required', 'string', 'max:50', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'mixBrandId' => ['required', 'exists:brands,id'],
            'mixCustomColor' => ['required', 'string', 'max:255'],
            'mixQuantity' => ['required', 'numeric', 'gt:0', 'max:99999.99'],
            'mixResultUnit' => ['required', 'in:gal,L'],
            'mixComponents' => ['required', 'array', 'min:1'],
        ], [
            'mixCustomerName.required' => 'Customer name is required for mixing orders.',
            'mixCustomerContact.required' => 'Customer contact number is required.',
            'mixCustomerContact.regex' => 'Contact number must contain only numbers, dashes, spaces, or parentheses.',
            'mixBrandId.required' => 'Select the brand for this custom mix.',
            'mixCustomColor.required' => 'Custom color name or code is required.',
            'mixQuantity.numeric' => 'Requested quantity must be a valid number.',
            'mixQuantity.max' => 'Quantity cannot exceed 99,999.99.',
            'mixComponents.min' => 'Add at least one material to the mixture formula.',
        ]);

        $brand = Brand::findOrFail($this->mixBrandId);
        $basis = $this->currentBasisProduct();
        if (! $basis) {
            $this->addError('mixComponents', 'Failed to determine price basis.');
            return;
        }

        $resultingQty = (float) $this->mixQuantity;
        $priceBasis = (float) $basis['price'];
        $unitPrice = $this->mixResultUnit === 'gal' ? $priceBasis : round($priceBasis / 4, 2);
        $subtotal = round($resultingQty * $unitPrice, 2);

        $key = 'mix:' . Str::uuid();

        $this->cart = [
            $key => [
                'type' => 'custom_mix',
                'product_id' => null,
                'brand_id' => $brand->id,
                'brand' => $brand->name,
                'custom_color' => trim($this->mixCustomColor),
                'description' => 'Custom Mix: ' . trim($this->mixCustomColor) . ' (' . $brand->name . ')',
                'package' => $resultingQty . ' ' . $this->mixResultUnit,
                'unit' => $this->mixResultUnit,
                'sku' => 'CUSTOM-MIX',
                'resulting_quantity' => $resultingQty,
                'resulting_unit' => $this->mixResultUnit,
                'quantity' => $resultingQty,
                'price_basis' => $priceBasis,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'basis_product_id' => $basis['product_id'],
                'basis_product_name' => $basis['product_name'],
                'components' => $this->mixComponents,
                'notes' => $this->mixNotes,
            ],
        ];

        session([
            'pos.cart' => $this->cart,
            'pos.customer_name' => trim($this->mixCustomerName),
            'pos.customer_contact' => trim($this->mixCustomerContact),
        ]);

        $this->resetMixForm();
        $this->redirectRoute('sales.checkout');
    }

    // ── Quotations ──────────────────────────────────────────────────────────────

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
        $this->quoteValidDays = '7';
    }

    public function saveAsQuotation(): void
    {
        $rules = [
            'quoteCustomerName' => ['nullable', 'string', 'max:200'],
            'quoteCustomerContact' => ['nullable', 'string', 'max:50'],
            'quoteNotes' => ['nullable', 'string', 'max:1000'],
            'quoteValidDays' => ['required', 'integer', 'min:1', 'max:365'],
            'quoteDiscountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];

        if (trim($this->quoteCustomerContact) !== '') {
            $rules['quoteCustomerContact'][] = 'regex:/^[0-9+\-\s()]{7,30}$/';
        }

        if ((float) $this->quoteDiscountPercentage > 0) {
            if (empty($this->quoteDiscountReason)) {
                $this->quoteDiscountReason = 'Customer Discount';
            }
            $rules['quoteDiscountReason'] = ['required', 'string', 'min:3', 'max:255'];
        }

        $this->validate($rules, [
            'quoteCustomerContact.regex' => 'Contact number must contain only numbers, dashes, spaces, or parentheses.',
            'quoteValidDays.integer' => 'Valid days must be a whole number.',
            'quoteValidDays.min' => 'Validity must be at least 1 day.',
            'quoteValidDays.max' => 'Validity cannot exceed 365 days.',
            'quoteDiscountPercentage.numeric' => 'Discount percentage must be a valid number.',
            'quoteDiscountPercentage.min' => 'Discount cannot be negative.',
            'quoteDiscountPercentage.max' => 'Discount cannot exceed 100%.',
        ]);

        $subtotal = $this->cartSubtotal();
        $discountPct = (float) $this->quoteDiscountPercentage;
        $discountAmt = round($subtotal * ($discountPct / 100), 2);
        $total = round($subtotal - $discountAmt, 2);

        $quote = DB::transaction(function () use ($subtotal, $discountPct, $discountAmt, $total): Quotation {
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
                'discount_authorized_by' => (auth()->user()?->isAdminOrSuperadmin() && $discountPct > 0) ? auth()->id() : null,
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'total' => $total,
                'valid_until' => now()->addDays((int) $this->quoteValidDays),
            ]);

            foreach ($this->cart as $line) {
                $q->items()->create([
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'],
                    'package' => $line['package'] ?? null,
                    'sku' => $line['sku'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                    'mix_data' => $line['type'] === 'custom_mix' ? $line : null,
                ]);
            }

            return $q;
        });

        $this->showQuoteModal = false;
        $this->clearCart();
        $this->dispatch('toast', ['type' => 'success', 'message' => "Quotation {$quote->quote_number} saved."]);
        $this->redirectRoute('sales.quotations');
    }

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
                $this->cart[$key] = [
                    'type' => 'normal',
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'package' => $product->formattedPackage(),
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

    // ── Mixing Orders & Collect Balance ─────────────────────────────────────────

    public function openMixingOrdersModal(): void
    {
        $this->showMixingOrdersModal = true;
    }

    public function proceedToCollectBalance(int $saleId): void
    {
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot access checkout.');

        session([
            'pos.collect_mix_sale_id' => $saleId,
        ]);
        $this->redirectRoute('sales.checkout');
    }


    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventories'])
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
            ->when($this->availableOnly, fn ($q) => $q->whereHas('inventories', fn ($iq) => $iq->where('quantity', '>', 0)))
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

        $pendingMixOrders = Sale::query()
            ->with(['mixingTransaction.brand', 'user'])
            ->where('payment_status', 'downpayment_paid')
            ->where(function ($q) {
                $q->where('balance_due', '>', 0)
                    ->orWhereHas('mixingTransaction', function ($mq) {
                        $mq->whereIn('status', ['mixed', 'ready_for_pickup']);
                    });
            })
            ->latest()
            ->get();

        $brandMaterials = [];
        if ($this->mixBrandId) {
            $brandMaterials = Product::query()
                ->where('active', true)
                ->where('brand_id', $this->mixBrandId)
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'selling_price']);
        }

        return view('livewire.pages.sales.index', [
            'products' => $products,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => PackageUnit::where('active', true)->orderBy('name')->get(),
            'brandMaterials' => $brandMaterials,
            'currency' => Currency::class,
            'loadQuotes' => $loadQuotes,
            'pendingMixOrders' => $pendingMixOrders,
            'cartSubtotal' => $this->cartSubtotal(),
        ]);
    }
}; ?>

<div class="space-y-3 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Point of Sale</h1>
        </div>
        <div class="flex items-center gap-2">
            @if ($pendingMixOrders->isNotEmpty())
                <button wire:click="openMixingOrdersModal" type="button"
                    class="inline-flex items-center gap-1.5 rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 shadow-xs transition">
                    <svg class="h-3.5 w-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    <span>Paint Orders ({{ $pendingMixOrders->count() }})</span>
                </button>
            @endif
            <button wire:click="openLoadQuoteModal" type="button"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span>Load Quote</span>
            </button>
        </div>
    </div>

    {{-- Main POS Container --}}
    <div class="flex flex-col text-xs rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden h-[calc(100vh-9.5rem)] min-h-[500px]">
        {{-- Main Layout: Left = Product Browser, Right = Cart --}}
        <div class="flex flex-1 min-h-0 overflow-hidden">

            {{-- LEFT: Product Browser --}}
            <div class="flex w-[55%] flex-col border-r border-slate-200 bg-white">

                {{-- Filter Bar with standardized h-9 controls --}}
                <div class="border-b border-slate-200 bg-white p-3 space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input wire:model.live.debounce.200ms="search" type="search" placeholder="Search product name or SKU..."
                                class="w-full h-9 rounded-lg border-slate-300 pl-9 pr-3 text-xs text-slate-900 focus:border-slate-500 focus:ring-0 shadow-xs" />
                        </div>
                        <label class="h-9 inline-flex items-center gap-2 px-3 rounded-lg border border-slate-300 bg-white text-xs font-medium text-slate-700 cursor-pointer select-none shadow-xs hover:bg-slate-50 transition">
                            <input wire:model.live="availableOnly" type="checkbox" class="rounded border-slate-300 text-slate-900 focus:ring-0" />
                            <span>In Stock Only</span>
                        </label>
                    </div>
                    <div class="flex items-center gap-2">
                        <select wire:model.live="brandId" class="flex-1 h-9 rounded-lg border-slate-300 text-xs px-2.5 focus:border-slate-500 focus:ring-0 shadow-xs bg-white text-slate-700">
                            <option value="">All Brands</option>
                            @foreach ($brands as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="categoryId" class="flex-1 h-9 rounded-lg border-slate-300 text-xs px-2.5 focus:border-slate-500 focus:ring-0 shadow-xs bg-white text-slate-700">
                            <option value="">All Categories</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="packageUnitId" class="flex-1 h-9 rounded-lg border-slate-300 text-xs px-2.5 focus:border-slate-500 focus:ring-0 shadow-xs bg-white text-slate-700">
                            <option value="">All Units</option>
                            @foreach ($packageUnits as $u)
                                <option value="{{ $u->id }}">{{ $u->abbreviation }}</option>
                            @endforeach
                        </select>
                        @if ($search || $brandId || $categoryId || $packageUnitId)
                            <button wire:click="resetFilters" type="button" class="h-9 px-2.5 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium whitespace-nowrap">Reset</button>
                        @endif
                    </div>
                </div>

                {{-- Product Table: Clean unbordered rows --}}
                <div class="flex-1 overflow-y-auto">
                    <table class="w-full border-collapse text-xs">
                        <thead class="sticky top-0 bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-600 z-10 border-b border-slate-200">
                            <tr>
                                <th class="px-3 py-2 text-left w-24">Brand</th>
                                <th class="px-3 py-2 text-left min-w-[150px]">Product Name</th>
                                <th class="px-3 py-2 text-center w-16">Unit</th>
                                <th class="px-3 py-2 text-right w-24">Stock</th>
                                <th class="px-3 py-2 text-right w-20">Price</th>
                                <th class="px-3 py-2 text-center w-12">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse ($products as $product)
                                @php
                                    $storageStock = $product->getStorageStock();
                                    $storefrontStock = $product->getStorefrontStock();
                                    $totalStock = $storageStock + $storefrontStock;
                                    $isOut = $totalStock <= 0;
                                    $isLow = !$isOut && $totalStock <= (float) $product->low_stock_threshold;
                                    $isSelected = $selectedProductId == $product->id;
                                @endphp
                                <tr wire:click="selectProduct({{ $product->id }})"
                                    wire:dblclick="openAddToCartModal({{ $product->id }})"
                                    class="cursor-pointer transition-colors {{ $isSelected ? 'bg-slate-100' : 'hover:bg-slate-50' }} {{ $isOut ? 'opacity-50' : '' }}"
                                    wire:key="prod-{{ $product->id }}">
                                    <td class="px-3 py-2 text-slate-600 whitespace-nowrap">
                                        {{ $product->brand?->name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 font-medium text-slate-900 max-w-[200px]">
                                        <span class="line-clamp-1 block" title="{{ $product->name }}">{{ $product->name }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-center text-slate-700 whitespace-nowrap font-medium">
                                        {{ $product->packageUnit?->abbreviation ?? ($product->packageUnit?->name ?? '—') }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                        @if ($isOut)
                                            <span class="text-rose-600 font-semibold">Out</span>
                                        @else
                                            <div class="leading-tight">
                                                <span class="{{ $isLow ? 'text-amber-600 font-bold' : 'text-slate-800 font-semibold' }}">
                                                    {{ number_format($totalStock, 2) }}
                                                </span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-slate-900 whitespace-nowrap">
                                        {{ $currency::format($product->selling_price) }}
                                    </td>
                                    <td class="px-3 py-2 text-center whitespace-nowrap">
                                        @if (!$isOut)
                                            <button wire:click.stop="openAddToCartModal({{ $product->id }})" type="button"
                                                title="Add to cart"
                                                class="inline-flex items-center justify-center rounded border border-slate-300 bg-white px-2 py-1 text-xs font-bold text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition shadow-xs">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            </button>
                                        @else
                                            <span class="text-[10px] text-slate-400 italic">None</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-slate-400">
                                        <p class="font-medium">No products found.</p>
                                        <button wire:click="resetFilters" type="button" class="mt-2 text-slate-600 hover:underline text-[11px]">Clear filters</button>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination & Status Footer --}}
                <div class="px-3 py-2 border-t border-slate-200 bg-slate-50 flex items-center justify-between text-xs text-slate-600">
                    <span class="text-[11px] text-slate-500">
                        Showing <span class="font-semibold text-slate-700">{{ $products->firstItem() ?? 0 }}</span> to <span class="font-semibold text-slate-700">{{ $products->lastItem() ?? 0 }}</span> of <span class="font-semibold text-slate-700">{{ $products->total() }}</span> items
                    </span>
                    <div class="inline-flex items-center gap-1">
                        @if ($products->onFirstPage())
                            <span class="px-2 py-1 text-[11px] text-slate-300 cursor-not-allowed">Previous</span>
                        @else
                            <button wire:click="previousPage" type="button" class="px-2 py-1 text-[11px] text-slate-600 hover:text-slate-900 hover:bg-white rounded border border-transparent hover:border-slate-300 transition">Previous</button>
                        @endif

                        @if ($products->hasMorePages())
                            <button wire:click="nextPage" type="button" class="px-2 py-1 text-[11px] text-slate-600 hover:text-slate-900 hover:bg-white rounded border border-transparent hover:border-slate-300 transition">Next</button>
                        @else
                            <span class="px-2 py-1 text-[11px] text-slate-300 cursor-not-allowed">Next</span>
                        @endif
                    </div>
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
                            <span class="rounded-full bg-slate-900 text-white text-[10px] font-bold px-1.5 py-0.5 leading-none">{{ count($cart) }}</span>
                            @if ($this->hasCustomMixInCart())
                                <span class="rounded border border-slate-300 bg-slate-100 text-slate-700 text-[10px] font-medium px-1.5 py-0.5">Custom Mix Order</span>
                            @endif
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
                            <p class="text-[11px] mt-1 text-slate-400">Select products on the left or launch Custom Mix.</p>
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
                                                    <span class="text-slate-700 font-semibold">Custom Mix</span> / {{ $line['package'] ?? '' }}
                                                @elseif (($line['type'] ?? '') === 'repack')
                                                    <span class="text-slate-700 font-semibold">Storefront Repack</span> / {{ $line['package'] ?? '' }}
                                                @else
                                                    {{ $line['sku'] ?? '' }}{{ $line['package'] ? ' / ' . $line['package'] : '' }}
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 text-center whitespace-nowrap">
                                            @if ($line['type'] === 'custom_mix')
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    max="99999.99"
                                                    inputmode="decimal"
                                                    x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                                    value="{{ (float) $line['quantity'] }}"
                                                    wire:change="updateCartQty('{{ $key }}', $event.target.value)"
                                                    class="w-16 rounded border-slate-300 text-center text-xs tabular-nums font-bold text-slate-900 focus:border-slate-500 focus:ring-0 py-1"
                                                />
                                            @else
                                                <div class="inline-flex items-center rounded border border-slate-300 bg-white shadow-xs">
                                                    <button type="button"
                                                        wire:click="updateCartQty('{{ $key }}', '{{ (int)$line['quantity'] - 1 }}')"
                                                        title="Decrease"
                                                        class="px-2 py-1 text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition rounded-l text-xs font-bold leading-none">
                                                        −
                                                    </button>
                                                    <input
                                                        type="number"
                                                        step="1"
                                                        min="1"
                                                        max="99999"
                                                        inputmode="numeric"
                                                        x-on:keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                        value="{{ (int) $line['quantity'] }}"
                                                        wire:change="updateCartQty('{{ $key }}', $event.target.value)"
                                                        class="w-12 border-0 p-0 text-center text-xs tabular-nums font-bold text-slate-900 focus:ring-0 py-1"
                                                    />
                                                    <button type="button"
                                                        wire:click="updateCartQty('{{ $key }}', '{{ (int)$line['quantity'] + 1 }}')"
                                                        title="Increase"
                                                        class="px-2 py-1 text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition rounded-r text-xs font-bold leading-none">
                                                        +
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 text-right tabular-nums text-slate-600 whitespace-nowrap">{{ $currency::format($line['unit_price']) }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">{{ $currency::format($line['subtotal']) }}</td>
                                        <td class="px-1 py-2 text-center">
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
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-600">Subtotal ({{ count($cart) }} item{{ count($cart) > 1 ? 's' : '' }})</span>
                            <span class="font-bold tabular-nums text-slate-900">{{ $currency::format($cartSubtotal) }}</span>
                        </div>
                        <div class="text-[10px] text-slate-400 italic">Discounts applied at checkout.</div>

                        <div class="pt-0.5">
                            <button wire:click="proceedToCheckout" type="button"
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded bg-[#00a3cc] px-3 py-2 text-xs font-semibold text-white hover:bg-[#008fb3] shadow-xs transition">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                <span>Proceed to Checkout</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── ADD TO CART MODAL ─────────────────────────────────────────────── --}}
    @if ($showAddToCartModal && $selectedProductId)
        @php
            $modalProd = \App\Models\Product::with(['brand', 'category', 'packageUnit', 'inventories'])->find((int)$selectedProductId);
            $modalStorage = $modalProd?->getStorageStock() ?? 0;
            $modalStorefront = $modalProd?->getStorefrontStock() ?? 0;
            $modalTotal = $modalStorage + $modalStorefront;
            $canRepack = $modalProd?->canBeRepacked() ?? false;

            $fractionVal = (float) ($purchaseMode === 'whole' ? 1.0 : ($repackFraction ?: 0.25));
            $unitPrice = round((float) ($modalProd?->selling_price ?? 0) * $fractionVal, 2);
            $subtotalCalc = round((float) ($addQty ?: 0) * $unitPrice, 2);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-xl border border-slate-300 bg-white shadow-2xl text-xs overflow-hidden" @click.outside="$wire.closeAddToCartModal()">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Add to Cart</h2>
                    </div>
                    <button wire:click="closeAddToCartModal" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700 leading-none">&times;</button>
                </div>

                <form wire:submit="addToCart" class="p-5 space-y-4">
                    <!-- Product Header (Clean typography, NO nested cards, NO bullet points) -->
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-[11px] text-slate-500 font-mono">
                            <span>SKU: {{ $modalProd?->sku ?? '—' }}</span>
                            <span class="text-slate-700 font-medium">{{ $modalProd?->brand?->name ?? '—' }}</span>
                        </div>
                        <h3 class="text-sm font-bold text-slate-900 leading-snug">
                            {{ $modalProd?->name }}
                        </h3>
                        <div class="flex items-center justify-between text-xs text-slate-600 pt-1">
                            <span>Available Stock:</span>
                            <span class="font-semibold text-slate-900 tabular-nums">
                                Storefront: {{ number_format($modalStorefront, 2) }} / Storage: {{ number_format($modalStorage, 2) }}
                            </span>
                        </div>
                    </div>

                    <div class="border-t border-slate-200"></div>

                    <!-- Package Unit & Fraction Selection -->
                    @if ($canRepack)
                        <div class="space-y-1.5">
                            <label class="block font-semibold text-slate-800">
                                Unit / Size
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" 
                                    wire:click="selectUnitSize('whole', '1')"
                                    class="px-3 py-2 text-left rounded border transition {{ $purchaseMode === 'whole' ? 'border-[#00a3cc] bg-sky-50/50 text-[#00a3cc] font-semibold ring-1 ring-[#00a3cc]' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    <div class="font-bold text-slate-900">Whole Gallon (4L)</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ Currency::format($modalProd?->selling_price ?? 0) }}</div>
                                </button>
                                <button type="button" 
                                    wire:click="selectUnitSize('repack', '0.5')"
                                    class="px-3 py-2 text-left rounded border transition {{ $purchaseMode === 'repack' && $repackFraction === '0.5' ? 'border-[#00a3cc] bg-sky-50/50 text-[#00a3cc] font-semibold ring-1 ring-[#00a3cc]' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    <div class="font-bold text-slate-900">1/2 Gal (2L)</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ Currency::format(round(($modalProd?->selling_price ?? 0) * 0.5, 2)) }}</div>
                                </button>
                                <button type="button" 
                                    wire:click="selectUnitSize('repack', '0.25')"
                                    class="px-3 py-2 text-left rounded border transition {{ $purchaseMode === 'repack' && $repackFraction === '0.25' ? 'border-[#00a3cc] bg-sky-50/50 text-[#00a3cc] font-semibold ring-1 ring-[#00a3cc]' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    <div class="font-bold text-slate-900">1/4 Gal (1L)</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ Currency::format(round(($modalProd?->selling_price ?? 0) * 0.25, 2)) }}</div>
                                </button>
                                <button type="button" 
                                    wire:click="selectUnitSize('repack', '0.125')"
                                    class="px-3 py-2 text-left rounded border transition {{ $purchaseMode === 'repack' && $repackFraction === '0.125' ? 'border-[#00a3cc] bg-sky-50/50 text-[#00a3cc] font-semibold ring-1 ring-[#00a3cc]' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                    <div class="font-bold text-slate-900">1/8 Gal (1/2L)</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">{{ Currency::format(round(($modalProd?->selling_price ?? 0) * 0.125, 2)) }}</div>
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center justify-between text-xs py-1">
                            <span class="text-slate-600 font-medium">Packaging Unit:</span>
                            <span class="font-bold text-slate-900">{{ $modalProd?->packageUnit?->name ?? 'Standard Piece' }}</span>
                        </div>
                    @endif

                    <!-- Quantity Input -->
                    <div class="space-y-1.5">
                        <label class="block font-semibold text-slate-800">Quantity</label>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                wire:click="$set('addQty', '{{ max(1, (int)$addQty - 1) }}')"
                                class="h-9 w-9 flex items-center justify-center rounded border border-slate-300 bg-white text-slate-700 font-bold text-sm hover:bg-slate-100 transition shadow-xs">
                                −
                            </button>
                            <input wire:model.live="addQty" type="number" step="1" min="1" max="99999"
                                inputmode="numeric"
                                x-on:keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                class="h-9 flex-1 rounded border border-slate-300 text-center font-bold text-sm tabular-nums text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                            <button type="button"
                                wire:click="$set('addQty', '{{ (int)$addQty + 1 }}')"
                                class="h-9 w-9 flex items-center justify-center rounded border border-slate-300 bg-white text-slate-700 font-bold text-sm hover:bg-slate-100 transition shadow-xs">
                                +
                            </button>
                        </div>
                        <div class="flex items-center gap-1.5 pt-1">
                            @foreach ([1, 2, 5, 10] as $preset)
                                <button type="button" wire:click="$set('addQty', '{{ $preset }}')"
                                    class="flex-1 py-1 rounded border border-slate-300 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                    +{{ $preset }}
                                </button>
                            @endforeach
                        </div>
                        @error('addQty')<p class="text-rose-600 text-[11px] font-medium">{{ $message }}</p>@enderror
                        @error('purchaseMode')<p class="text-rose-600 text-[11px] font-medium">{{ $message }}</p>@enderror
                        @error('productId')<p class="text-rose-600 text-[11px] font-medium">{{ $message }}</p>@enderror
                    </div>

                    <div class="border-t border-slate-200"></div>

                    <!-- Clean Price & Subtotal Summary (NO cards, clean tabular typography) -->
                    <div class="space-y-1 tabular-nums text-xs">
                        <div class="flex items-center justify-between text-slate-600">
                            <span>Unit Price:</span>
                            <span class="font-medium text-slate-900">{{ Currency::format($unitPrice) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm font-bold text-slate-900 pt-1">
                            <span>Total Subtotal:</span>
                            <span class="text-base text-slate-900 font-extrabold">{{ Currency::format($subtotalCalc) }}</span>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex justify-end gap-2 pt-3 border-t border-slate-200">
                        <button wire:click="closeAddToCartModal" type="button"
                            class="px-3.5 py-1.5 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 transition shadow-xs">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] transition shadow-xs">
                            Add to Cart
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── CUSTOM MIX MODAL (Downpayment Stage) ────────────────────────────── --}}
    @if ($showMixModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-xl border border-slate-300 bg-white shadow-2xl text-xs overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-2.5">
                    <div>
                        <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">New Custom Paint Mix Order</h2>
                        <p class="text-[11px] text-slate-500">Collect downpayment to queue mixing</p>
                    </div>
                    <button wire:click="resetMixForm" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700 leading-none">&times;</button>
                </div>

                <form wire:submit="saveCustomMixDownpayment" class="p-4 overflow-y-auto space-y-3 flex-1">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Customer Name <span class="text-rose-600">*</span></label>
                            <input wire:model="mixCustomerName" type="text" placeholder="Full name"
                                class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs" />
                            @error('mixCustomerName') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Contact Number <span class="text-rose-600">*</span></label>
                            <input wire:model="mixCustomerContact" type="text" placeholder="09XX..."
                                inputmode="tel"
                                x-on:keydown="if(!/[0-9+\-\s()]/.test($event.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'].includes($event.key) && !$event.ctrlKey && !$event.metaKey) $event.preventDefault()"
                                class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs" />
                            @error('mixCustomerContact') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Brand to Use <span class="text-rose-600">*</span></label>
                            <select wire:model.live="mixBrandId" class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs bg-white text-slate-700">
                                <option value="">-- Select brand --</option>
                                @foreach ($brands as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                            @error('mixBrandId') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Custom Color Code / Name <span class="text-rose-600">*</span></label>
                            <input wire:model="mixCustomColor" type="text" placeholder="e.g. Laguna Blue Matte #B-302"
                                class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs" />
                            @error('mixCustomColor') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Requested Quantity <span class="text-rose-600">*</span></label>
                            <input wire:model.live="mixQuantity" type="number" step="0.25" min="0.25" max="99999.99"
                                inputmode="decimal"
                                x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                class="w-full rounded-lg border-slate-300 text-xs tabular-nums focus:border-slate-500 focus:ring-0 shadow-xs" />
                            @error('mixQuantity') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Requested Unit <span class="text-rose-600">*</span></label>
                            <select wire:model.live="mixResultUnit" class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs bg-white text-slate-700">
                                <option value="gal">Gallon (gal)</option>
                                <option value="L">Liter (L)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Notes / Specifications</label>
                        <input wire:model="mixNotes" type="text" placeholder="Special customer instructions..."
                            class="w-full rounded-lg border-slate-300 text-xs focus:border-slate-500 focus:ring-0 shadow-xs" />
                    </div>

                    <div class="pt-1 border-t border-slate-200">
                        <label class="block font-semibold text-slate-700 mb-1">Downpayment (₱) <span class="text-rose-600">*</span></label>
                        <input wire:model.live.debounce.150ms="mixDownpaymentAmount" type="number" step="0.01" min="1" max="999999.99" placeholder="0.00"
                            inputmode="decimal"
                            x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                            class="w-full rounded-lg border-slate-300 text-xs font-bold tabular-nums focus:border-slate-500 focus:ring-0 shadow-xs" />
                        @error('mixDownpaymentAmount') <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                        <button wire:click="resetMixForm" type="button"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 transition shadow-xs">
                            Cancel
                        </button>
                        <button type="submit"
                            class="px-4 py-1.5 rounded bg-[#00a3cc] text-white text-xs font-semibold hover:bg-[#008fb3] shadow-xs transition">
                            Confirm Downpayment &amp; Queue Mix
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── MIXING ORDERS MODAL ──────────────────────────────────────────────── --}}
    @if ($showMixingOrdersModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-3xl rounded-xl border border-slate-300 bg-white shadow-2xl text-xs overflow-hidden flex flex-col max-h-[85vh]">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Custom Mix Orders In Progress</h2>
                        <p class="text-[11px] text-slate-500">Orders with downpayment received. Collect remaining balance when customer picks up mixed paint.</p>
                    </div>
                    <button wire:click="$set('showMixingOrdersModal', false)" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700 leading-none">&times;</button>
                </div>
                <div class="p-4 overflow-y-auto flex-1">
                    @if ($pendingMixOrders->isEmpty())
                        <div class="py-12 text-center text-slate-400">
                            <p class="font-medium text-xs">No pending custom mix orders.</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">All custom paint mixing orders have been fully paid and completed.</p>
                        </div>
                    @else
                        <div class="overflow-x-auto border border-slate-200 rounded-lg">
                            <table class="w-full border-collapse text-left text-xs">
                                <thead class="bg-slate-100 text-[10px] font-bold uppercase text-slate-700 tracking-wider border-b border-slate-200">
                                    <tr>
                                        <th class="px-3 py-2">Invoice #</th>
                                        <th class="px-3 py-2">Customer</th>
                                        <th class="px-3 py-2">Specification</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                        <th class="px-3 py-2 text-right">Downpayment</th>
                                        <th class="px-3 py-2 text-right">Balance Due</th>
                                        <th class="px-3 py-2 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($pendingMixOrders as $order)
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-3 py-2 font-mono font-bold text-slate-900 whitespace-nowrap">
                                                <a href="{{ route('sales.receipt', $order) }}" class="text-[#008fb3] hover:underline">
                                                    {{ $order->invoice_number }}
                                                </a>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="font-medium text-slate-900">{{ $order->customer_name ?? 'Walk-in' }}</div>
                                                <div class="text-[10px] text-slate-500">{{ $order->customer_contact }}</div>
                                            </td>
                                            <td class="px-3 py-2">
                                                <div class="font-medium text-slate-900">
                                                    {{ $order->mixingTransaction?->custom_color ?? 'Custom Color' }}
                                                </div>
                                                <div class="text-[10px] text-slate-500">
                                                    {{ $order->mixingTransaction?->brand?->name ?? '—' }} - {{ $order->mixingTransaction?->resulting_quantity }} {{ $order->mixingTransaction?->resulting_unit }}
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums font-medium text-slate-900 whitespace-nowrap">
                                                {{ Currency::format($order->total) }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-slate-700 whitespace-nowrap">
                                                {{ Currency::format($order->downpayment_amount) }}
                                            </td>
                                            <td class="px-3 py-2 text-right tabular-nums font-bold whitespace-nowrap">
                                                @if ((float) $order->downpayment_amount > (float) $order->total && in_array($order->mixingTransaction?->status, ['mixed', 'ready_for_pickup']))
                                                    <span class="text-emerald-700">Refund {{ Currency::format((float) $order->downpayment_amount - (float) $order->total) }}</span>
                                                @else
                                                    <span class="text-slate-900">{{ Currency::format($order->balance_due) }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                                <button wire:click="proceedToCollectBalance({{ $order->id }})" type="button"
                                                    class="inline-flex items-center gap-1 rounded bg-[#00a3cc] px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-[#008fb3] transition shadow-xs">
                                                    @if ((float) $order->downpayment_amount > (float) $order->total && in_array($order->mixingTransaction?->status, ['mixed', 'ready_for_pickup']))
                                                        Release &amp; Refund
                                                    @else
                                                        Collect Balance
                                                    @endif
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

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
                            <input wire:model="quoteCustomerName" type="text" placeholder="e.g. Maria Santos"
                                class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                            @error('quoteCustomerName')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Contact / Phone</label>
                            <input wire:model="quoteCustomerContact" type="text" placeholder="e.g. 0917-123-4567"
                                inputmode="tel"
                                x-on:keydown="if(!/[0-9+\-\s()]/.test($event.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'].includes($event.key) && !$event.ctrlKey && !$event.metaKey) $event.preventDefault()"
                                class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                            @error('quoteCustomerContact')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">
                                Valid For (days) <span class="text-rose-500">*</span>
                            </label>
                            <input wire:model="quoteValidDays" type="number" min="1" max="365"
                                inputmode="numeric"
                                x-on:keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-[#00a3cc] focus:ring-0" />
                            @error('quoteValidDays')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Discount Rate (%)</label>
                            <input wire:model.live="quoteDiscountPercentage" type="number" step="0.5" min="0" max="100" placeholder="0"
                                inputmode="decimal"
                                x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                class="w-full rounded border-slate-300 text-xs tabular-nums focus:border-[#00a3cc] focus:ring-0" />
                            @error('quoteDiscountPercentage')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                            @if ((float) $quoteDiscountPercentage > 0)
                                <div class="space-y-0.5 mt-1">
                                    <label class="block font-semibold text-slate-700 text-[11px]">Discount Reason <span class="text-rose-600">*</span></label>
                                    <input wire:model="quoteDiscountReason" type="text" placeholder="e.g. Contractor loyalty..."
                                        class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                                    @error('quoteDiscountReason')<p class="text-rose-600 text-[11px]">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Notes</label>
                        <textarea wire:model="quoteNotes" rows="2" placeholder="Internal notes or special instructions..."
                            class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0"></textarea>
                    </div>

                    <div class="flex gap-2 pt-1">
                        <button wire:click="$set('showQuoteModal', false)" type="button"
                            class="flex-1 rounded border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button wire:click="saveAsQuotation" type="button" wire:loading.attr="disabled"
                            class="flex-1 rounded bg-[#00a3cc] px-3 py-2 text-xs font-semibold text-white hover:bg-[#008fb3] transition shadow-xs disabled:opacity-50">
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
                        <input wire:model.live.debounce.200ms="loadQuoteSearch" type="search" placeholder="Search quote # or customer name..."
                            class="w-full rounded border-slate-300 text-xs focus:border-[#00a3cc] focus:ring-0" />
                    </div>
                    @if ($loadQuotes && $loadQuotes->count())
                        <div class="divide-y divide-slate-100 border border-slate-200 rounded">
                            @foreach ($loadQuotes as $q)
                                <div class="flex items-center justify-between px-3 py-2.5 hover:bg-slate-50">
                                    <div>
                                        <div class="font-semibold text-slate-900">{{ $q->quote_number }}</div>
                                        <div class="text-slate-500 text-[11px]">
                                            {{ $q->customer_name ?? 'No customer' }}
                                            - {{ $currency::format($q->total) }}
                                        </div>
                                    </div>
                                    <button wire:click="loadQuote({{ $q->id }})" type="button"
                                        class="rounded border border-[#00a3cc] text-[#00a3cc] bg-white px-3 py-1 text-[11px] font-bold hover:bg-cyan-50">
                                        Load
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="py-6 text-center text-slate-400">No open quotations found.</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
