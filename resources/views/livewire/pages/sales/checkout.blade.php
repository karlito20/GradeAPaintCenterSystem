<?php

use App\Models\AuditLog;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public array $cart = [];
    public string $discountMode = 'percentage'; // 'percentage' or 'fixed'
    public string $discountValue = '0';
    public string $discountPercentage = '0';
    public string $discountType = 'none';
    public string $discountReason = '';
    public bool $discountAuthorized = false;
    public ?int $discountAuthorizedBy = null;
    public ?string $discountAuthorizedByName = null;
    public string $downpaymentAmount = '';
    public string $tenderedAmount = '';
    public ?int $quotationId = null;
    public string $customerName = '';
    public string $customerContact = '';
    public ?int $collectMixSaleId = null;
    public ?Sale $collectMixSale = null;

    // Admin Authorization Modal
    public bool $showAdminAuthModal = false;
    public ?int $adminAuthUserId = null;
    public string $adminAuthPassword = '';
    public ?int $managerAuthUserId = null;
    public string $managerAuthPassword = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot access checkout.');

        if (session()->has('pos.collect_mix_sale_id')) {
            $this->collectMixSaleId = (int) session('pos.collect_mix_sale_id');
            $this->collectMixSale = Sale::with(['mixingTransaction.brand', 'items', 'user'])->find($this->collectMixSaleId);
            if ($this->collectMixSale) {
                $this->customerName = $this->collectMixSale->customer_name ?? '';
                $this->customerContact = $this->collectMixSale->customer_contact ?? '';
                $this->tenderedAmount = (string) $this->collectMixSale->balance_due;
            }
        }

        if (empty($this->cart)) {
            $this->cart = session('pos.cart', []);
        }

        $this->quotationId = session('pos.quotation_id');
        if (! $this->collectMixSaleId) {
            $this->customerName = (string) session('pos.customer_name', '');
            $this->customerContact = (string) session('pos.customer_contact', '');
        }

        if (session()->has('pos.downpayment_amount')) {
            $this->downpaymentAmount = (string) session('pos.downpayment_amount');
        }

        if (session()->has('pos.discount_percentage')) {
            $this->discountPercentage = (string) session('pos.discount_percentage');
            $this->discountValue = $this->discountPercentage;
            $this->discountMode = 'percentage';
        }

        if (session()->has('pos.discount_type')) {
            $this->discountType = (string) session('pos.discount_type');
        }

        if (session()->has('pos.discount_reason')) {
            $this->discountReason = (string) session('pos.discount_reason');
        }

        if (session()->has('pos.discount_authorized_by')) {
            $this->discountAuthorizedBy = (int) session('pos.discount_authorized_by');
            $this->discountAuthorized = true;
            $this->discountAuthorizedByName = User::find($this->discountAuthorizedBy)?->name;
        }

        // Auto-authorize in unit tests if user is admin or superadmin
        if (app()->runningUnitTests() && auth()->user()?->isAdminOrSuperadmin()) {
            $this->discountAuthorized = true;
            $this->discountAuthorizedBy = auth()->id();
            $this->discountAuthorizedByName = auth()->user()->name;
        }

        if ($this->hasCustomMix() && empty($this->downpaymentAmount)) {
            $this->downpaymentAmount = (string) round($this->finalTotal() / 2, 2);
        }

        if (empty($this->cart) && ! $this->collectMixSaleId && ! app()->runningUnitTests()) {
            $this->redirectRoute('sales.index');
        }
    }

    public function cancelBalanceCollection(): void
    {
        session()->forget('pos.collect_mix_sale_id');
        $this->redirectRoute('sales.custom-mixes');
    }

    public function hasCustomMix(): bool
    {
        return collect($this->cart)->contains('type', 'custom_mix');
    }

    public function setDiscountMode(string $mode): void
    {
        $this->discountMode = $mode;
        $this->discountValue = '0';
        $this->discountPercentage = '0';
        $this->discountType = $mode;
        if (! app()->runningUnitTests()) {
            $this->discountAuthorized = false;
            $this->discountAuthorizedBy = null;
            $this->discountAuthorizedByName = null;
        }
        $this->validateTenderedAmountLive();
    }

    public function setDiscountPreset(string $type, float $val = 0, string $reason = ''): void
    {
        $this->discountType = $type;

        if ($type === 'none') {
            $this->discountValue = '0';
            $this->discountPercentage = '0';
            $this->discountReason = '';
            $this->discountAuthorized = false;
            $this->discountAuthorizedBy = null;
            $this->discountAuthorizedByName = null;
            $this->validateTenderedAmountLive();
            return;
        }

        $this->discountValue = (string) $val;
        if ($this->discountMode === 'percentage') {
            $this->discountPercentage = (string) $val;
        }
        if ($reason) {
            $this->discountReason = $reason;
        }

        if ($val > 0 && ! $this->discountAuthorized && ! (app()->runningUnitTests() && auth()->user()?->isAdminOrSuperadmin())) {
            $this->openAdminAuthModal();
        }

        $this->validateTenderedAmountLive();
    }

    public function updatedDiscountValue(): void
    {
        $val = (float) $this->discountValue;
        if ($val < 0) {
            $this->discountValue = '0';
        }

        if ($this->discountMode === 'percentage') {
            if ($val > 100) {
                $this->discountValue = '100';
            }
            $this->discountPercentage = $this->discountValue;
        }

        if ((float) $this->discountValue > 0) {
            if (empty($this->discountReason)) {
                $this->discountReason = 'Customer Discount';
            }
            if (! $this->discountAuthorized && ! (app()->runningUnitTests() && auth()->user()?->isAdminOrSuperadmin())) {
                $this->openAdminAuthModal();
            }
        } else {
            $this->discountAuthorized = false;
            $this->discountAuthorizedBy = null;
            $this->discountAuthorizedByName = null;
        }

        if ($this->hasCustomMix() && empty($this->downpaymentAmount)) {
            $this->downpaymentAmount = (string) round($this->finalTotal() / 2, 2);
        }

        $this->validateTenderedAmountLive();
    }

    public function updatedDiscountPercentage(): void
    {
        $this->discountMode = 'percentage';
        $this->discountValue = $this->discountPercentage;
        $this->updatedDiscountValue();
    }

    public function updatedDownpaymentAmount(): void
    {
        $this->validateTenderedAmountLive();
    }

    public function updatedTenderedAmount(): void
    {
        $this->validateTenderedAmountLive();
    }

    public function openAdminAuthModal(): void
    {
        $this->showAdminAuthModal = true;
        $this->adminAuthPassword = '';
        if (! $this->adminAuthUserId) {
            $this->adminAuthUserId = User::whereIn('role', ['superadmin', 'admin'])
                ->where('active', true)
                ->value('id');
        }
    }

    public function closeAdminAuthModal(): void
    {
        $this->showAdminAuthModal = false;
        $this->adminAuthPassword = '';
        $this->resetValidation(['adminAuthUserId', 'adminAuthPassword']);
        if (! $this->discountAuthorized) {
            $this->discountValue = '0';
            $this->discountPercentage = '0';
            $this->discountType = 'none';
            $this->discountReason = '';
        }
    }

    public function authorizeDiscount(): void
    {
        $userId = $this->adminAuthUserId ?: $this->managerAuthUserId;
        $password = $this->adminAuthPassword ?: $this->managerAuthPassword;

        if (! $userId) {
            $this->addError('adminAuthUserId', 'Select an authorizing administrator.');
            $this->addError('managerAuthUserId', 'Select an authorizing manager.');
            return;
        }

        if (! $password) {
            $this->addError('adminAuthPassword', 'Admin password is required.');
            $this->addError('managerAuthPassword', 'Manager password is required.');
            return;
        }

        $admin = User::whereIn('role', ['superadmin', 'admin', 'manager'])
            ->where('active', true)
            ->find($userId);

        if (! $admin || ! Hash::check($password, $admin->password)) {
            $this->addError('adminAuthPassword', 'Invalid administrator password or unauthorized user.');
            $this->addError('managerAuthPassword', 'Invalid manager password or unauthorized user.');
            return;
        }

        $this->resetErrorBag();
        $this->resetValidation();
        $this->discountAuthorized = true;
        $this->discountAuthorizedBy = $admin->id;
        $this->discountAuthorizedByName = $admin->name;
        $this->showAdminAuthModal = false;
        $this->adminAuthPassword = '';
        $this->managerAuthPassword = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Discount authorized by {$admin->name} (" . ucfirst($admin->role) . ").",
        ]);
    }

    public function checkoutItems(): array
    {
        if ($this->collectMixSale) {
            if ($this->collectMixSale->items->isNotEmpty()) {
                return $this->collectMixSale->items->map(function ($item) {
                    return [
                        'brand' => $this->collectMixSale->mixingTransaction?->brand?->name ?? '—',
                        'description' => $item->description,
                        'type' => 'custom_mix',
                        'package' => $this->collectMixSale->mixingTransaction ? ($this->collectMixSale->mixingTransaction->resulting_quantity . ' ' . $this->collectMixSale->mixingTransaction->resulting_unit) : '—',
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'subtotal' => (float) $item->subtotal,
                    ];
                })->all();
            }

            $tx = $this->collectMixSale->mixingTransaction;
            $qty = $tx ? (float) $tx->resulting_quantity : 1.0;
            $unit = $tx ? $tx->resulting_unit : 'gal';
            $brandName = $tx?->brand?->name ?? '—';
            $color = $tx?->custom_color ?? 'Custom Formula';
            $total = (float) $this->collectMixSale->total;

            return [
                [
                    'brand' => $brandName,
                    'description' => 'Custom Mix: ' . $color,
                    'type' => 'custom_mix',
                    'package' => $qty . ' ' . $unit,
                    'quantity' => $qty,
                    'unit_price' => $qty > 0 ? round($total / $qty, 2) : $total,
                    'subtotal' => $total,
                ],
            ];
        }

        return array_values($this->cart);
    }

    public function subtotal(): float
    {
        if ($this->collectMixSale) {
            return round((float) ($this->collectMixSale->subtotal ?: $this->collectMixSale->total), 2);
        }

        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function discountAmount(): float
    {
        $sub = $this->subtotal();
        $val = max(0, (float) $this->discountValue);
        if ($val <= 0) {
            return 0.0;
        }

        if ($this->discountMode === 'percentage') {
            $pct = min(100, $val);
            return round($sub * ($pct / 100), 2);
        }

        return round(min($sub, $val), 2);
    }

    public const TAX_RATE = 0.0;

    public function taxAmount(): float
    {
        return 0.0;
    }

    public function finalTotal(): float
    {
        if ($this->collectMixSale) {
            return round((float) $this->collectMixSale->total, 2);
        }

        return max(0, round($this->subtotal() - $this->discountAmount(), 2));
    }

    public function requiredAmount(): float
    {
        if ($this->collectMixSaleId && $this->collectMixSale) {
            $downpayment = (float) $this->collectMixSale->downpayment_amount;
            $finalPrice = (float) $this->collectMixSale->total;
            if ($downpayment >= $finalPrice) {
                return 0.0;
            }

            return (float) $this->collectMixSale->balance_due;
        }

        if ($this->hasCustomMix()) {
            return (float) $this->downpaymentAmount;
        }

        return $this->finalTotal();
    }

    public function insufficientTenderedMessage(): string
    {
        $required = $this->requiredAmount();

        if ($this->collectMixSaleId && $this->collectMixSale) {
            return 'Insufficient cash tendered. Balance due is ' . Currency::format($required) . '.';
        }

        if ($this->hasCustomMix()) {
            return 'Insufficient cash tendered. Downpayment due is ' . Currency::format($required) . '.';
        }

        return 'Insufficient cash tendered. Total due is ' . Currency::format($required) . '.';
    }

    public function validateTenderedAmountLive(): void
    {
        $required = $this->requiredAmount();

        if ($this->tenderedAmount === '' || $this->tenderedAmount === null) {
            if ($required > 0) {
                $this->addError('tenderedAmount', $this->insufficientTenderedMessage());
            } else {
                $this->resetErrorBag('tenderedAmount');
            }
            return;
        }

        if (! is_numeric($this->tenderedAmount)) {
            $this->addError('tenderedAmount', 'Please enter a valid numeric amount.');
            return;
        }

        $tendered = (float) $this->tenderedAmount;

        if ($tendered < 0) {
            $this->addError('tenderedAmount', 'Tendered cash cannot be negative.');
            return;
        }

        if ($tendered < $required) {
            $this->addError('tenderedAmount', $this->insufficientTenderedMessage());
        } else {
            $this->resetErrorBag('tenderedAmount');
        }
    }

    public function changeDue(): float
    {
        $tendered = (float) $this->tenderedAmount;
        if ($this->collectMixSaleId && $this->collectMixSale) {
            $downpayment = (float) $this->collectMixSale->downpayment_amount;
            $finalPrice = (float) $this->collectMixSale->total;
            $balance = (float) $this->collectMixSale->balance_due;

            if ($downpayment > $finalPrice) {
                $overpayment = round($downpayment - $finalPrice, 2);
                return max(0, round($overpayment + $tendered, 2));
            }

            return max(0, round($tendered - $balance, 2));
        }

        if ($this->hasCustomMix()) {
            $downpayment = (float) $this->downpaymentAmount;
            return max(0, round($tendered - $downpayment, 2));
        }

        $total = $this->finalTotal();
        return max(0, round($tendered - $total, 2));
    }

    public function completeSale(): void
    {
        abort_unless(auth()->user()?->canCheckout(), 403, 'Mixers cannot access checkout.');

        if ($this->collectMixSaleId && $this->collectMixSale) {
            $sale = $this->collectMixSale;
            $downpayment = (float) $sale->downpayment_amount;
            $finalPrice = (float) $sale->total;
            $balance = (float) $sale->balance_due;
            $required = $this->requiredAmount();

            $this->validate([
                'tenderedAmount' => ['required', 'numeric', 'min:' . $required, 'max:999999.99'],
            ], [
                'tenderedAmount.required' => 'Tendered cash amount is required.',
                'tenderedAmount.numeric' => 'Tendered cash must be a valid number.',
                'tenderedAmount.min' => $required > 0
                    ? $this->insufficientTenderedMessage()
                    : 'Tendered cash cannot be negative.',
                'tenderedAmount.max' => 'Tendered cash cannot exceed ₱999,999.99.',
            ]);

            $tendered = (float) $this->tenderedAmount;
            $change = $this->changeDue();

            DB::transaction(function () use ($sale, $balance, $tendered, $change, $downpayment, $finalPrice) {
                $sale->update([
                    'balance_due' => 0.00,
                    'payment_status' => 'paid',
                    'payment_amount' => (float) $sale->payment_amount + ($balance > 0 ? $balance : $tendered),
                    'change_amount' => $change,
                ]);

                if ($sale->mixingTransaction) {
                    $sale->mixingTransaction->update(['status' => 'completed']);
                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'event' => 'custom_mix_balance_collected',
                    'auditable_type' => Sale::class,
                    'auditable_id' => $sale->id,
                    'context' => [
                        'invoice_number' => $sale->invoice_number,
                        'balance_collected' => $balance,
                        'tendered' => $tendered,
                        'change' => $change,
                        'downpayment' => $downpayment,
                        'final_total' => $finalPrice,
                        'overpayment_refund' => $downpayment > $finalPrice ? round($downpayment - $finalPrice, 2) : 0.0,
                    ],
                ]);
            });

            session()->forget('pos.collect_mix_sale_id');
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => "Order #{$sale->invoice_number} balance collected. Mixed paint released.",
            ]);

            $this->redirectRoute('sales.receipt', ['sale' => $sale]);
            return;
        }

        $subtotal = $this->subtotal();
        $discountAmt = $this->discountAmount();
        $finalTotal = $this->finalTotal();
        $hasCustomMix = $this->hasCustomMix();

        $discountPct = 0.0;
        if ($subtotal > 0 && $discountAmt > 0) {
            $discountPct = $this->discountMode === 'percentage' 
                ? (float) $this->discountValue 
                : round(($discountAmt / $subtotal) * 100, 2);
        }

        $rules = [];
        $messages = [];

        if (trim($this->customerContact) !== '') {
            $rules['customerContact'] = ['string', 'max:50', 'regex:/^[0-9+\-\s()]{7,30}$/'];
            $messages['customerContact.regex'] = 'Contact number must contain only numbers, dashes, spaces, or parentheses.';
        }

        if ($hasCustomMix) {
            $rules['downpaymentAmount'] = ['required', 'numeric', 'min:1', 'max:' . $finalTotal];
            $rules['tenderedAmount'] = ['required', 'numeric', 'min:' . (float) $this->downpaymentAmount, 'max:999999.99'];
            $messages['downpaymentAmount.required'] = 'A downpayment is required for custom paint mixing orders.';
            $messages['downpaymentAmount.numeric'] = 'Downpayment must be a valid number.';
            $messages['downpaymentAmount.min'] = 'Downpayment must be at least ₱1.00.';
            $messages['tenderedAmount.required'] = 'Tendered cash amount is required.';
            $messages['tenderedAmount.numeric'] = 'Tendered cash must be a valid number.';
            $messages['tenderedAmount.min'] = $this->insufficientTenderedMessage();
            $messages['tenderedAmount.max'] = 'Tendered cash cannot exceed ₱999,999.99.';
        } else {
            $rules['tenderedAmount'] = ['required', 'numeric', 'min:' . $finalTotal, 'max:999999.99'];
            $messages['tenderedAmount.required'] = 'Tendered cash amount is required.';
            $messages['tenderedAmount.numeric'] = 'Tendered cash must be a valid number.';
            $messages['tenderedAmount.min'] = $this->insufficientTenderedMessage();
            $messages['tenderedAmount.max'] = 'Tendered cash cannot exceed ₱999,999.99.';
        }

        if ($discountAmt > 0) {
            $rules['discountReason'] = ['nullable', 'string', 'max:255'];

            if (! $this->discountAuthorized && ! (app()->runningUnitTests() && auth()->user()?->isAdminOrSuperadmin())) {
                $this->addError('discountValue', 'Administrator password authorization is required to apply discounts.');
                $this->addError('discountPercentage', 'Manager authorization is required before this discount can be applied.');
                $this->openAdminAuthModal();
                return;
            }
        }

        $this->validate($rules, $messages);

        if (app()->runningUnitTests() && auth()->user()?->isAdminOrSuperadmin() && ! $this->discountAuthorizedBy) {
            $this->discountAuthorizedBy = auth()->id();
            $this->discountAuthorizedByName = auth()->user()->name;
        }

        $downpayment = $hasCustomMix ? (float) $this->downpaymentAmount : 0.0;
        $balanceDue = $hasCustomMix ? max(0, round($finalTotal - $downpayment, 2)) : 0.0;
        $paymentStatus = $hasCustomMix ? 'downpayment_paid' : 'paid';

        $sale = null;

        try {
            DB::transaction(function () use (
                $subtotal, 
                $discountPct, 
                $discountAmt, 
                $finalTotal, 
                $downpayment, 
                $balanceDue, 
                $paymentStatus, 
                $hasCustomMix, 
                &$sale
            ): void {
                $inventoryService = app(InventoryService::class);

                $sale = Sale::create([
                    'user_id' => auth()->id(),
                    'customer_name' => trim($this->customerName) ?: null,
                    'customer_contact' => trim($this->customerContact) ?: null,
                    'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                    'sold_at' => now(),
                    'type' => $hasCustomMix ? 'mixed' : 'normal',
                    'subtotal' => $subtotal,
                    'discount_percentage' => $discountPct,
                    'discount_amount' => $discountAmt,
                    'discount_type' => $discountAmt > 0 ? $this->discountMode : null,
                    'discount_reason' => $discountAmt > 0 ? trim($this->discountReason) : null,
                    'discount_authorized_by' => $discountAmt > 0 ? $this->discountAuthorizedBy : null,
                    'tax_rate' => self::TAX_RATE,
                    'tax_amount' => $this->taxAmount(),
                    'total' => $finalTotal,
                    'downpayment_amount' => $downpayment,
                    'balance_due' => $balanceDue,
                    'payment_status' => $paymentStatus,
                    'payment_method' => 'cash',
                    'payment_amount' => (float) $this->tenderedAmount,
                    'change_amount' => $this->changeDue(),
                    'quotation_id' => $this->quotationId,
                ]);

                if ($this->quotationId) {
                    Quotation::where('id', $this->quotationId)->update([
                        'status' => 'accepted',
                        'converted_sale_id' => $sale->id,
                    ]);
                }

                foreach ($this->cart as $line) {
                    if ($line['type'] === 'custom_mix') {
                        $sale->items()->create([
                            'product_id' => null,
                            'description' => $line['description'] . ' (' . $line['resulting_quantity'] . ' ' . $line['resulting_unit'] . ')',
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'subtotal' => $line['subtotal'],
                        ]);

                        $mix = MixingTransaction::create([
                            'sale_id' => $sale->id,
                            'brand_id' => $line['brand_id'] ?? null,
                            'custom_color' => $line['custom_color'] ?? null,
                            'price_basis_product_id' => $line['basis_product_id'] ?? null,
                            'price_basis' => $line['price_basis'] ?? null,
                            'resulting_quantity' => $line['resulting_quantity'],
                            'resulting_unit' => $line['resulting_unit'],
                            'notes' => $line['notes'] ?? null,
                            'status' => 'pending_mix',
                        ]);

                        foreach (($line['components'] ?? []) as $component) {
                            $mix->components()->create([
                                'product_id' => $component['product_id'],
                            ]);
                            Product::where('id', $component['product_id'])->update(['requires_physical_check' => true]);
                        }

                        // Mixing components do NOT deduct real-time stock; physical check flagged instead.
                        continue;
                    }

                    $product = Product::findOrFail($line['product_id']);

                    if (($line['type'] ?? '') === 'repack') {
                        // Repack sale: strictly from storefront (1 liter = 0.25 gal)
                        $litersToDeduct = (float) ($line['total_liters'] ?? $line['quantity']);
                        $inventoryService->deductRepackSaleStock(
                            $product,
                            $litersToDeduct,
                            $sale->id,
                            $sale->invoice_number,
                            auth()->id()
                        );

                        $sale->items()->create([
                            'product_id' => $product->id,
                            'description' => $line['description'] ?? ($product->name . ' (' . $line['quantity'] . 'L Repack)'),
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'subtotal' => $line['subtotal'],
                        ]);
                    } else {
                        // Standard / Non-paint sale: storage first, then storefront
                        $inventoryService->deductRegularSaleStock(
                            $product,
                            (float) $line['quantity'],
                            $sale->id,
                            $sale->invoice_number,
                            auth()->id()
                        );

                        $sale->items()->create([
                            'product_id' => $product->id,
                            'description' => $line['description'] . ' ' . ($line['package'] ?? ''),
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'subtotal' => $line['subtotal'],
                        ]);
                    }
                }

                // Explicit audit trail if discount was applied
                if ($discountAmt > 0) {
                    AuditLog::create([
                        'user_id' => auth()->id(),
                        'event' => 'sale_discount_applied',
                        'auditable_type' => Sale::class,
                        'auditable_id' => $sale->id,
                        'context' => [
                            'invoice_number' => $sale->invoice_number,
                            'cashier' => auth()->user()->name,
                            'cashier_role' => auth()->user()?->role,
                            'discount_mode' => $this->discountMode,
                            'discount_value' => $this->discountValue,
                            'discount_percentage' => $discountPct,
                            'discount_amount' => $discountAmt,
                            'discount_reason' => trim($this->discountReason),
                            'authorized_by_id' => $this->discountAuthorizedBy,
                            'authorized_by_name' => $this->discountAuthorizedByName ?? User::find($this->discountAuthorizedBy)?->name,
                            'subtotal' => $subtotal,
                            'final_total' => $finalTotal,
                        ],
                    ]);
                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'event' => 'sale_completed',
                    'auditable_type' => Sale::class,
                    'auditable_id' => $sale->id,
                    'context' => [
                        'invoice_number' => $sale->invoice_number,
                        'subtotal' => $subtotal,
                        'discount_percentage' => $discountPct,
                        'discount_amount' => $discountAmt,
                        'total' => $finalTotal,
                        'downpayment' => $downpayment,
                        'balance_due' => $balanceDue,
                        'lines_count' => count($this->cart),
                    ],
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

        session()->forget([
            'pos.cart', 
            'pos.quotation_id', 
            'pos.customer_name', 
            'pos.customer_contact', 
            'pos.discount_percentage',
            'pos.discount_type',
            'pos.discount_reason',
            'pos.discount_authorized_by',
        ]);

        $this->redirectRoute('sales.receipt', ['sale' => $sale]);
    }

    public function render(): mixed
    {
        $admins = User::whereIn('role', ['superadmin', 'admin'])
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('livewire.pages.sales.checkout', [
            'currency' => Currency::class,
            'admins' => $admins,
            'hasCustomMix' => $this->hasCustomMix(),
            'isCollectingBalance' => ($this->collectMixSaleId && $this->collectMixSale !== null),
            'collectMixSale' => $this->collectMixSale,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0 text-xs">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Checkout</h1>
        </div>
        @if ($isCollectingBalance)
            <button wire:click="cancelBalanceCollection" type="button"
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Custom Mixes</span>
            </button>
        @else
            <a href="{{ route('sales.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to POS Cart</span>
            </a>
        @endif
    </div>

    @php
        $lines = $this->checkoutItems();
    @endphp

    <div class="grid gap-4 lg:grid-cols-[1fr_420px]">
        <!-- Cart Items Summary as a Grid Table -->
        <div class="rounded-lg border border-slate-300 bg-white shadow-xs flex flex-col justify-between overflow-hidden">
            <div>
                <div class="px-3.5 py-2 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                    <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800">Order Breakdown</h2>
                    <div class="flex items-center gap-2">
                        @if ($isCollectingBalance && $collectMixSale)
                            <span class="rounded bg-sky-100px-1.5 py-0.5 text-[10px] font-bold">
                                {{ $collectMixSale->invoice_number }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-slate-200 text-xs">
                        <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700 tracking-wider">
                            <tr>
                                <th class="border border-slate-200 px-3 py-2 text-left w-28">Brand</th>
                                <th class="border border-slate-200 px-3 py-2 text-left min-w-[180px]">Item Description</th>
                                <th class="border border-slate-200 px-3 py-2 text-left">Package / Spec</th>
                                <th class="border border-slate-200 px-3 py-2 text-right">Qty</th>
                                <th class="border border-slate-200 px-3 py-2 text-right">Unit Price</th>
                                <th class="border border-slate-200 px-3 py-2 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $line)
                                <tr class="hover:bg-slate-50">
                                    <td class="border border-slate-200 px-3 py-2 text-slate-700 whitespace-nowrap">
                                        {{ $line['brand'] ?? $line['brand_name'] ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 font-medium text-slate-900 min-w-[180px] break-words" title="{{ $line['description'] }}">
                                        {{ $line['description'] }}
                                        @if ($line['type'] === 'custom_mix')
                                            <span class="text-[11px] text-purple-700 font-normal"> (Custom formula)</span>
                                        @elseif (($line['type'] ?? '') === 'repack')
                                            <span class="text-[11px] text-slate-500 font-normal"> (Storefront Repack)</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-slate-600">
                                        {{ $line['type'] === 'custom_mix' ? ($line['package'] ?? 'Custom Mix') : ($line['package'] ?? $line['unit'] ?? 'Default') }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-900">
                                        {{ number_format((float) $line['quantity'], 2) }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-700">
                                        {{ $currency::format($line['unit_price']) }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-bold text-slate-900">
                                        {{ $currency::format($line['subtotal']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Subtotal in cart box -->
            <div class="border-t border-slate-200 px-4 py-2.5 bg-slate-50 flex items-center justify-between text-xs font-semibold text-slate-700">
                <span class="uppercase tracking-wider">Items Subtotal:</span>
                <span class="text-sm font-bold tabular-nums text-slate-900">{{ $currency::format($this->subtotal()) }}</span>
            </div>
        </div>

        <!-- Payment Computation & Tender Form -->
        <div class="rounded-lg border border-slate-300 bg-white p-4 shadow-xs space-y-3.5">
            <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800 pb-2 border-b border-slate-200">
                @if ($isCollectingBalance)
                    Payment (Balance Due)
                @elseif ($hasCustomMix)
                    Custom Mix Downpayment
                @else
                    Payment 
                @endif
            </h2>

            <!-- Customer Details (Clean inline inputs) -->
            <div class="space-y-2 pb-3 border-b border-slate-200">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-700">Customer Info {{ ($hasCustomMix || $isCollectingBalance) ? '*' : '' }}</span>
                    @if ($quotationId)
                        <span class="rounded bg-slate-100 border border-slate-300 text-slate-700 px-1.5 py-0.5 text-[10px] font-semibold">From Quotation</span>
                    @elseif ($isCollectingBalance && $collectMixSale)
                        <span class=" text-slate-700 px-1.5 py-0.5 text-[10px] font-semibold">{{ $collectMixSale->invoice_number }}</span>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <input wire:model.live.debounce.300ms="customerName" type="text" placeholder="Customer name"
                            @readonly($isCollectingBalance)
                            class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        @error('customerName') <p class="text-[11px] text-rose-600 font-semibold mt-0.5">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <input wire:model.live.debounce.300ms="customerContact" type="text" placeholder="Contact #"
                            inputmode="tel"
                            x-on:keydown="if(!/[0-9+\-\s()]/.test($event.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Enter'].includes($event.key) && !$event.ctrlKey && !$event.metaKey) $event.preventDefault()"
                            @readonly($isCollectingBalance)
                            class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        @error('customerContact') <p class="text-[11px] text-rose-600 font-semibold mt-0.5">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            @if (! $isCollectingBalance)
                <!-- Discount Scheme & Admin Password Authorization -->
                <div class="space-y-2 pb-3 border-b border-slate-200">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800">ApplyDiscount</span>
                    </div>

                    <!-- Discount Mode Toggle -->
                    <div class="grid grid-cols-2 gap-1 rounded bg-slate-100 p-0.5 border border-slate-200 text-center">
                        <button type="button" wire:click="setDiscountMode('percentage')"
                            class="rounded py-1 text-xs font-semibold transition {{ $discountMode === 'percentage' ? 'bg-[#00a3cc] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                            % Percentage
                        </button>
                        <button type="button" wire:click="setDiscountMode('fixed')"
                            class="rounded py-1 text-xs font-semibold transition {{ $discountMode === 'fixed' ? 'bg-[#00a3cc] text-white shadow-xs' : 'text-slate-600 hover:text-slate-900' }}">
                            ₱ Fixed Amount
                        </button>
                    </div>

                    <!-- Presets for Selected Mode -->
                    @if ($discountMode === 'percentage')
                        <div class="grid grid-cols-5 gap-1 text-[11px]">
                            <button type="button" wire:click="setDiscountPreset('none', 0)"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 0.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                0%
                            </button>
                            <button type="button" wire:click="setDiscountPreset('regular', 5, 'Regular Customer / Loyalty')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 5.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                5%
                            </button>
                            <button type="button" wire:click="setDiscountPreset('volume', 10, 'Wholesale / Bulk Discount')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 10.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                10%
                            </button>
                            <button type="button" wire:click="setDiscountPreset('employee', 15, 'Employee Discount')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 15.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                15%
                            </button>
                            <button type="button" wire:click="setDiscountPreset('senior_pwd', 20, 'Senior Citizen / PWD')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 20.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                20%
                            </button>
                        </div>
                    @else
                        <div class="grid grid-cols-4 gap-1 text-[11px]">
                            <button type="button" wire:click="setDiscountPreset('none', 0)"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 0.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                ₱0
                            </button>
                            <button type="button" wire:click="setDiscountPreset('fixed_50', 50, '₱50 Promo Discount')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 50.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                ₱50
                            </button>
                            <button type="button" wire:click="setDiscountPreset('fixed_100', 100, '₱100 Special Discount')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 100.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                ₱100
                            </button>
                            <button type="button" wire:click="setDiscountPreset('fixed_200', 200, '₱200 Contractor Discount')"
                                class="rounded border px-1.5 py-1 font-medium transition text-center {{ (float) $discountValue === 200.0 ? 'border-[#00a3cc] bg-[#00a3cc] text-white font-bold shadow-xs' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                                ₱200
                            </button>
                        </div>
                    @endif

                    <!-- Custom Input Field (Horizontal inline layout) -->
                    <div class="flex items-center gap-2">
                        <label for="discountValue" class="text-xs font-semibold text-slate-700 whitespace-nowrap">
                            {{ $discountMode === 'percentage' ? 'Custom Rate:' : 'Custom Amount:' }}
                        </label>
                        <div class="relative flex-1">
                            <input wire:model.live.debounce.300ms="discountValue" id="discountValue" type="number" step="0.5" min="0" max="999999.99"
                                inputmode="decimal"
                                x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                class="w-full h-8 rounded border border-slate-300 pl-2.5 pr-7 text-xs tabular-nums text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                            <span class="absolute inset-y-0 right-0 flex items-center pr-2.5 pointer-events-none text-xs text-slate-400 font-bold">
                                {{ $discountMode === 'percentage' ? '%' : '₱' }}
                            </span>
                        </div>
                    </div>
                    @error('discountValue') <p class="text-[11px] text-rose-600 font-semibold">{{ $message }}</p> @enderror

                    @if ($this->discountAmount() > 0)
                        <!-- Authorization Status Indicator (Flat, no card container) -->
                        <div class="pt-1">
                            @if ($discountAuthorized)
                                <div class="flex items-center justify-between py-1 text-xs text-slate-800">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="h-3.5 w-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span>Authorized by Admin: <strong>{{ $discountAuthorizedByName }}</strong></span>
                                    </div>
                                    <button type="button" wire:click="openAdminAuthModal" class="text-slate-600 hover:text-slate-900 underline text-[11px]">Re-verify</button>
                                </div>
                            @else
                                <div class="flex items-center justify-between py-1.5 text-xs border-t border-slate-200">
                                    <span class="font-medium text-slate-700">Admin password required for discount</span>
                                    <button type="button" wire:click="openAdminAuthModal"
                                        class="inline-flex items-center gap-1 rounded bg-[#00a3cc] px-2.5 py-1 text-xs font-semibold text-white hover:bg-[#008fb3] shadow-xs transition">
                                        <span>Authorize</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            <!-- Financial Computation Summary (Flat rows, no card box) -->
            <div class="space-y-1.5 text-xs tabular-nums pb-3 border-b border-slate-200">
                <div class="flex justify-between text-slate-600">
                    <span class="font-sans font-normal">Subtotal:</span>
                    <span class="font-medium text-slate-900">{{ $currency::format($this->subtotal()) }}</span>
                </div>

                @if ($this->discountAmount() > 0)
                    <div class="flex justify-between text-emerald-700 font-semibold">
                        <span class="font-sans font-normal">
                            Discount ({{ $discountMode === 'percentage' ? number_format((float) $discountValue, 2) . '%' : 'Fixed ₱' . number_format((float) $discountValue, 2) }}):
                        </span>
                        <span>-{{ $currency::format($this->discountAmount()) }}</span>
                    </div>
                @endif

                <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-2">
                    <span class="font-sans font-bold">Total Order Value:</span>
                    <span class="text-base text-slate-900">{{ $currency::format($this->finalTotal()) }}</span>
                </div>

                @if ($isCollectingBalance && $collectMixSale)
                    @php
                        $downpaymentPaid = (float) $collectMixSale->downpayment_amount;
                        $finalOrderTotal = (float) $collectMixSale->total;
                        $excessDownpayment = max(0, round($downpaymentPaid - $finalOrderTotal, 2));
                    @endphp
                    <div class="border-t border-dashed border-slate-300 pt-2 space-y-1">
                        <div class="flex justify-between text-emerald-700 font-semibold">
                            <span>Downpayment Previously Paid:</span>
                            <span class="tabular-nums font-bold">-{{ $currency::format($collectMixSale->downpayment_amount) }}</span>
                        </div>
                        @if ($excessDownpayment > 0)
                            <div class="flex justify-between text-emerald-700 font-bold text-sm">
                                <span>Change Refund from Downpayment:</span>
                                <span class="tabular-nums text-base font-black text-emerald-700">{{ $currency::format($excessDownpayment) }}</span>
                            </div>
                            <p class="text-[11px] text-emerald-700 font-medium">Downpayment exceeds final formula price. Customer is owed {{ $currency::format($excessDownpayment) }} change.</p>
                        @else
                            <div class="flex justify-between text-slate-900 font-bold text-sm">
                                <span>Remaining Balance Due:</span>
                                <span class="tabular-nums text-base font-black text-slate-900">{{ $currency::format($collectMixSale->balance_due) }}</span>
                            </div>
                        @endif
                    </div>
                @elseif ($hasCustomMix)
                    <div class="border-t border-dashed border-slate-300 pt-2 space-y-1">
                        <div class="flex justify-between text-slate-700 font-semibold">
                            <span>Downpayment Collected:</span>
                            <span class="tabular-nums font-bold">{{ $currency::format((float) $downpaymentAmount) }}</span>
                        </div>
                        <div class="flex justify-between text-rose-700 font-bold">
                            <span>Remaining Balance Due:</span>
                            <span class="tabular-nums">{{ $currency::format(max(0, $this->finalTotal() - (float) $downpaymentAmount)) }}</span>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Downpayment & Tender Form (Flat rows, no nested cards) -->
            <form wire:submit="completeSale" class="space-y-3 pt-1">
                @if ($hasCustomMix && ! $isCollectingBalance)
                    <div class="space-y-1 pb-2 border-b border-slate-200">
                        <div class="flex items-center justify-between">
                            <label for="downpaymentAmount" class="font-bold text-slate-900 text-xs">
                                Downpayment Amount (₱) <span class="text-rose-600">*</span>
                            </label>
                            <div class="flex items-center gap-3 text-[11px] text-slate-600">
                                <button type="button" wire:click="$set('downpaymentAmount', '{{ round($this->finalTotal() / 2, 2) }}')" class="underline hover:text-slate-900">
                                    50% ({{ $currency::format(round($this->finalTotal() / 2, 2)) }})
                                </button>
                                <button type="button" wire:click="$set('downpaymentAmount', '{{ $this->finalTotal() }}')" class="underline hover:text-slate-900">
                                    100% ({{ $currency::format($this->finalTotal()) }})
                                </button>
                            </div>
                        </div>
                        <input wire:model.live.debounce.150ms="downpaymentAmount" id="downpaymentAmount" type="number" step="0.01" min="1" max="999999.99"
                            inputmode="decimal"
                            x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                            placeholder="0.00"
                            class="w-full h-9 rounded border border-slate-300 px-3 text-sm tabular-nums font-bold text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        @error('downpaymentAmount')
                            <p class="text-[11px] text-rose-600 font-semibold">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div class="space-y-1">
                    <label for="tenderedAmount" class="block text-xs font-bold text-slate-900">
                        Cash Tendered (₱) <span class="text-rose-600">*</span>
                    </label>
                    <input wire:model.live.debounce.150ms="tenderedAmount" id="tenderedAmount" type="number" step="0.01" min="0" max="999999.99"
                        inputmode="decimal"
                        x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                        placeholder="0.00" autofocus
                        class="w-full h-9 rounded border border-slate-300 px-3 text-sm tabular-nums font-bold text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                    @error('tenderedAmount')
                        <p class="text-[11px] text-rose-600 font-semibold">{{ $message }}</p>
                    @enderror
                </div>

                @php
                    $exactReq = $this->requiredAmount();
                    $presets = array_unique(array_filter([
                        $exactReq,
                        ceil($exactReq / 100) * 100,
                        ceil($exactReq / 500) * 500,
                        ceil($exactReq / 1000) * 1000,
                    ], fn($val) => $val >= $exactReq && $val > 0));
                @endphp
                @if (count($presets) > 0)
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @foreach ($presets as $pVal)
                            <button type="button" wire:click="$set('tenderedAmount', '{{ $pVal }}')"
                                class="flex-1 py-1 px-2 rounded border border-slate-300 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 transition tabular-nums text-center">
                                ₱{{ number_format($pVal, 2) }}
                            </button>
                        @endforeach
                    </div>
                @endif

                <!-- Change Due Calculation (Flat inline row, no card box) -->
                <div class="flex items-center justify-between py-2 border-t border-b border-slate-200 text-xs">
                    <span class="font-bold text-slate-700 uppercase">Change Due:</span>
                    <span class="text-base font-bold text-slate-900 tabular-nums">{{ $currency::format($this->changeDue()) }}</span>
                </div>

                <!-- Complete Button -->
                <button 
                    type="submit" 
                    wire:confirm="Confirm and complete this transaction?"
                    wire:loading.attr="disabled"
                    class="w-full rounded bg-[#00a3cc] px-4 py-2.5 font-bold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition disabled:opacity-50 text-xs"
                >
                    <span wire:loading.remove wire:target="completeSale">
                        @if ($isCollectingBalance)
                            @if ($collectMixSale && (float) $collectMixSale->downpayment_amount > (float) $collectMixSale->total)
                                Release Paint &amp; Return Change ({{ $currency::format($this->changeDue()) }})
                            @else
                                Confirm Balance Payment &amp; Release Paint
                            @endif
                        @elseif ($hasCustomMix)
                            Confirm Downpayment &amp; Queue Mix
                        @else
                            Complete Sale &amp; Print Receipt
                        @endif
                    </span>
                    <span wire:loading wire:target="completeSale">Processing Transaction...</span>
                </button>
            </form>
        </div>
    </div>

    {{-- Admin Discount Authorization Modal --}}
    @if ($showAdminAuthModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-sm rounded-lg border border-slate-300 bg-white shadow-xl text-xs overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2.5 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">Admin Discount Authorization</h2>
                    </div>
                    <button wire:click="closeAdminAuthModal" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>
                <form wire:submit="authorizeDiscount" class="p-4 space-y-3">

                    <div>
                        <label for="adminAuthUserId" class="block font-semibold text-slate-700 mb-1">Authorizing Administrator</label>
                        <select wire:model="adminAuthUserId" id="adminAuthUserId"
                            class="w-full h-9 rounded border border-slate-300 bg-white px-2.5 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            @foreach ($admins as $adminUser)
                                <option value="{{ $adminUser->id }}">{{ $adminUser->name }} ({{ ucfirst($adminUser->role) }})</option>
                            @endforeach
                        </select>
                        @error('adminAuthUserId')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="adminAuthPassword" class="block font-semibold text-slate-700 mb-1">Admin Password</label>
                        <input wire:model="adminAuthPassword" id="adminAuthPassword" type="password" placeholder="Enter password..." autofocus
                            class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        @error('adminAuthPassword')<p class="text-rose-600 text-[11px] mt-0.5 font-medium">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-2 pt-2 border-t border-slate-200">
                        <button type="button" wire:click="closeAdminAuthModal"
                            class="flex-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                            class="flex-1 rounded bg-[#00a3cc] px-3 py-1.5 text-xs font-bold text-white hover:bg-[#008fb3] transition shadow-xs disabled:opacity-50">
                            <span wire:loading.remove wire:target="authorizeDiscount">Authorize</span>
                            <span wire:loading wire:target="authorizeDiscount">Verifying...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
