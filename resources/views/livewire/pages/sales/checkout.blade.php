<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public array $cart = [];
    public string $discountType = 'none';
    public string $discountPercentage = '0';
    public string $discountReason = '';
    public bool $discountAuthorized = false;
    public ?int $discountAuthorizedBy = null;
    public ?string $discountAuthorizedByName = null;
    public string $tenderedAmount = '';
    public ?int $quotationId = null;
    public string $customerName = '';
    public string $customerContact = '';

    // Manager Authorization Modal
    public bool $showManagerAuthModal = false;
    public ?int $managerAuthUserId = null;
    public string $managerAuthPassword = '';

    public function mount(): void
    {
        if (empty($this->cart)) {
            $this->cart = session('pos.cart', []);
        }

        $this->quotationId = session('pos.quotation_id');
        $this->customerName = (string) session('pos.customer_name', '');
        $this->customerContact = (string) session('pos.customer_contact', '');

        if (session()->has('pos.discount_percentage')) {
            $this->discountPercentage = (string) session('pos.discount_percentage');
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

        // Auto-authorize if current cashier is manager or above
        if (auth()->user()?->isManagerOrAbove()) {
            $this->discountAuthorized = true;
            $this->discountAuthorizedBy = auth()->id();
            $this->discountAuthorizedByName = auth()->user()->name;
        }

        if (empty($this->cart) && !app()->runningUnitTests()) {
            $this->redirectRoute('sales.index');
        }
    }

    public function setDiscountPreset(string $type): void
    {
        $this->discountType = $type;

        switch ($type) {
            case 'none':
                $this->discountPercentage = '0';
                $this->discountReason = '';
                if (auth()->user()?->role === 'mixer') {
                    $this->discountAuthorized = false;
                    $this->discountAuthorizedBy = null;
                    $this->discountAuthorizedByName = null;
                }
                break;
            case 'regular':
                $this->discountPercentage = '5';
                $this->discountReason = 'Regular Customer / Loyalty Discount';
                break;
            case 'volume':
                $this->discountPercentage = '10';
                $this->discountReason = 'Wholesale / Bulk Purchase Discount';
                break;
            case 'employee':
                $this->discountPercentage = '15';
                $this->discountReason = 'Employee Discount';
                break;
            case 'senior_pwd':
                $this->discountPercentage = '20';
                $this->discountReason = 'Senior Citizen / PWD Discount';
                break;
            case 'custom':
                if ((float) $this->discountPercentage <= 0) {
                    $this->discountPercentage = '5';
                }
                if (empty($this->discountReason)) {
                    $this->discountReason = 'Special Manager Approved Discount';
                }
                break;
        }

        if (auth()->user()?->role === 'mixer' && (float) $this->discountPercentage > 0 && ! $this->discountAuthorized) {
            $this->openManagerAuthModal();
        }
    }

    public function updatedDiscountPercentage(): void
    {
        $pct = (float) $this->discountPercentage;
        if ($pct < 0) {
            $this->discountPercentage = '0';
        }
        if ($pct > 100) {
            $this->discountPercentage = '100';
        }

        if ((float) $this->discountPercentage > 0) {
            if ($this->discountType === 'none') {
                $this->discountType = 'custom';
            }
            if (empty($this->discountReason)) {
                $this->discountReason = 'Customer Discount';
            }
            if (auth()->user()?->role === 'mixer' && ! $this->discountAuthorized) {
                $this->openManagerAuthModal();
            }
        } else {
            $this->discountType = 'none';
        }
    }

    public function openManagerAuthModal(): void
    {
        $this->showManagerAuthModal = true;
        $this->managerAuthPassword = '';
        if (! $this->managerAuthUserId) {
            $this->managerAuthUserId = User::whereIn('role', ['superadmin', 'admin', 'manager'])
                ->where('active', true)
                ->value('id');
        }
    }

    public function closeManagerAuthModal(): void
    {
        $this->showManagerAuthModal = false;
        $this->managerAuthPassword = '';
        $this->resetValidation(['managerAuthUserId', 'managerAuthPassword']);
        if (auth()->user()?->role === 'mixer' && ! $this->discountAuthorized) {
            $this->discountPercentage = '0';
            $this->discountType = 'none';
            $this->discountReason = '';
        }
    }

    public function authorizeDiscount(): void
    {
        $this->validate([
            'managerAuthUserId' => ['required', 'exists:users,id'],
            'managerAuthPassword' => ['required', 'string'],
        ], [
            'managerAuthUserId.required' => 'Select an authorizing manager.',
            'managerAuthPassword.required' => 'Manager password is required.',
        ]);

        $manager = User::whereIn('role', ['superadmin', 'admin', 'manager'])
            ->where('active', true)
            ->find($this->managerAuthUserId);

        if (! $manager || ! Hash::check($this->managerAuthPassword, $manager->password)) {
            $this->addError('managerAuthPassword', 'Invalid manager password or unauthorized user.');
            return;
        }

        $this->discountAuthorized = true;
        $this->discountAuthorizedBy = $manager->id;
        $this->discountAuthorizedByName = $manager->name;
        $this->showManagerAuthModal = false;
        $this->managerAuthPassword = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Discount authorized by {$manager->name} (" . ucfirst($manager->role) . ").",
        ]);
    }

    public function subtotal(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }

    public function discountAmount(): float
    {
        $pct = max(0, min(100, (float) $this->discountPercentage));
        return round($this->subtotal() * ($pct / 100), 2);
    }

    public const TAX_RATE = 12.0;

    public function taxAmount(): float
    {
        $afterDiscount = $this->subtotal() - $this->discountAmount();
        return round($afterDiscount * (self::TAX_RATE / 100), 2);
    }

    public function finalTotal(): float
    {
        return max(0, round($this->subtotal() - $this->discountAmount() + $this->taxAmount(), 2));
    }

    public function changeDue(): float
    {
        $tendered = (float) $this->tenderedAmount;
        $total = $this->finalTotal();
        return max(0, round($tendered - $total, 2));
    }

    public function completeSale(): void
    {
        $subtotal = $this->subtotal();
        $discountPct = (float) $this->discountPercentage;
        $discountAmt = $this->discountAmount();
        $finalTotal = $this->finalTotal();

        $rules = [
            'discountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'tenderedAmount' => ['required', 'numeric', 'min:' . $finalTotal],
        ];

        $messages = [
            'tenderedAmount.min' => 'Tendered cash must be at least the total due (' . Currency::format($finalTotal) . ').',
        ];

        if ($discountPct > 0 || $discountAmt > 0) {
            $rules['discountReason'] = ['required', 'string', 'min:3', 'max:255'];
            $messages['discountReason.required'] = 'A discount reason is required when a discount is applied.';
            $messages['discountReason.min'] = 'Please provide a clear discount reason (at least 3 characters).';

            if (auth()->user()?->role === 'mixer' && ! $this->discountAuthorized) {
                $this->addError('discountPercentage', 'Manager authorization is required before this discount can be applied.');
                $this->openManagerAuthModal();
                return;
            }
        }

        $this->validate($rules, $messages);

        if (auth()->user()?->isManagerOrAbove() && ! $this->discountAuthorizedBy) {
            $this->discountAuthorizedBy = auth()->id();
            $this->discountAuthorizedByName = auth()->user()->name;
        }

        $sale = null;

        try {
            DB::transaction(function () use ($subtotal, $discountPct, $discountAmt, $finalTotal, &$sale): void {
                $hasCustomMix = collect($this->cart)->contains('type', 'custom_mix');

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
                    'discount_type' => $discountPct > 0 ? $this->discountType : null,
                    'discount_reason' => $discountPct > 0 ? trim($this->discountReason) : null,
                    'discount_authorized_by' => $discountPct > 0 ? $this->discountAuthorizedBy : null,
                    'tax_rate' => self::TAX_RATE,
                    'tax_amount' => $this->taxAmount(),
                    'total' => $finalTotal,
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
                            'price_basis_product_id' => $line['basis_product_id'] ?? null,
                            'resulting_quantity' => $line['resulting_quantity'],
                            'resulting_unit' => $line['resulting_unit'],
                            'notes' => $line['notes'] ?: null,
                        ]);

                        foreach ($line['components'] as $component) {
                            $mix->components()->create([
                                'product_id' => $component['product_id'],
                                'estimated_quantity' => $component['estimated_quantity'],
                                'estimated_quantity_unit' => $component['estimated_quantity_unit'],
                            ]);
                        }

                        // Mixing component estimates do not deduct official stock in real time.
                        continue;
                    }

                    // Normal packaged product sale
                    $product = Product::findOrFail($line['product_id']);
                    $inventory = Inventory::where('product_id', $product->id)
                        ->lockForUpdate()
                        ->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);

                    $before = (float) $inventory->quantity;
                    if ($before < (float) $line['quantity']) {
                        throw ValidationException::withMessages([
                            'tenderedAmount' => "Insufficient stock for {$product->name}. Available: {$before}.",
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
                        'reference_text' => 'Invoice #' . $sale->invoice_number,
                    ]);
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
                            'cashier_role' => auth()->user()->role,
                            'discount_type' => $this->discountType,
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
                        'discount_type' => $this->discountType,
                        'discount_reason' => $this->discountReason,
                        'tax_rate' => self::TAX_RATE,
                        'tax_amount' => $this->taxAmount(),
                        'total' => $finalTotal,
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
        $managers = User::whereIn('role', ['superadmin', 'admin', 'manager'])
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('livewire.pages.sales.checkout', [
            'currency' => Currency::class,
            'managers' => $managers,
        ]);
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-4 px-3 py-4 text-xs sm:px-6 lg:px-8 w-full min-w-0">
    <!-- Header -->
    <div class="flex items-center justify-between pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-lg font-bold uppercase tracking-wider text-slate-900">Checkout</h1>
        </div>
        <a href="{{ route('sales.index') }}" wire:navigate
            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-sm transition">
            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to POS Cart</span>
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-[1fr_360px]">
        <!-- Cart Items Summary as a Grid Table -->
        <div class="rounded border border-slate-300 bg-white shadow-sm overflow-hidden flex flex-col justify-between">
            <div>
                <div class="px-3.5 py-2 border-b border-slate-300 bg-slate-100 flex items-center justify-between">
                    <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800">Order Breakdown ({{ count($cart) }} item(s))</h2>
                    <span class="rounded border border-slate-300 text-slate-600 bg-transparent px-1.5 py-0.5 text-[10px] font-bold ">CASH ONLY</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700 tracking-wider">
                            <tr>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[180px]">Item Description</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left">Package / Spec</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right">Qty</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right">Unit Price</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cart as $line)
                                <tr class="hover:bg-slate-50">
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                        {{ $line['brand'] ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 min-w-[180px] break-words" title="{{ $line['description'] }}">
                                        {{ $line['description'] }}
                                        @if ($line['type'] === 'custom_mix')
                                            <span class="block text-[10px] text-purple-700 font-semibold">Custom Mixed Paint Formula</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">
                                        {{ $line['type'] === 'custom_mix' ? ($line['package'] ?? 'Custom Mix') : ($line['package'] ?? $line['unit'] ?? 'Default') }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-900">
                                        {{ number_format((float) $line['quantity'], 2) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700">
                                        {{ $currency::format($line['unit_price']) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900">
                                        {{ $currency::format($line['subtotal']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Subtotal in cart box -->
            <div class="border-t border-slate-300 px-4 py-2.5 bg-slate-100 flex items-center justify-between text-xs font-semibold text-slate-700">
                <span class="uppercase tracking-wider">Items Subtotal:</span>
                <span class="text-sm font-bold tabular-nums text-slate-900">{{ $currency::format($this->subtotal()) }}</span>
            </div>
        </div>

        <!-- Payment Computation & Tender Form -->
        <div class="rounded border border-slate-300 bg-white p-4 shadow-sm space-y-3.5">
            <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800 pb-2 border-b border-slate-200">Payment Tender</h2>

            <!-- Customer Details (Optional or from Quote) -->
            <div class="space-y-2 border-b border-slate-200 pb-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-700">Customer Info (Optional)</span>
                    @if ($quotationId)
                        <span class="rounded bg-sky-50 border border-sky-200 text-sky-800 px-1.5 py-0.5 text-[10px] font-semibold">From Quotation</span>
                    @endif
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <input wire:model.live.debounce.300ms="customerName" type="text" placeholder="Customer name"
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                    </div>
                    <div>
                        <input wire:model.live.debounce.300ms="customerContact" type="text" placeholder="Contact #"
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                    </div>
                </div>
            </div>

            <!-- Discount Schemes & Authorization -->
            <div class="space-y-2 border-b border-slate-200 pb-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-slate-800">
                        Discount Scheme
                    </label>
                    <span class="text-[10px] text-slate-400 font-medium">Standardized Policy</span>
                </div>

                <!-- Scheme Preset Buttons -->
                <div class="grid grid-cols-3 gap-1.5 text-[11px]">
                    <button type="button" wire:click="setDiscountPreset('none')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'none' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        None (0%)
                    </button>
                    <button type="button" wire:click="setDiscountPreset('regular')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'regular' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Regular (5%)
                    </button>
                    <button type="button" wire:click="setDiscountPreset('volume')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'volume' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Wholesale (10%)
                    </button>
                    <button type="button" wire:click="setDiscountPreset('employee')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'employee' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Employee (15%)
                    </button>
                    <button type="button" wire:click="setDiscountPreset('senior_pwd')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'senior_pwd' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Senior/PWD (20%)
                    </button>
                    <button type="button" wire:click="setDiscountPreset('custom')"
                        class="rounded border px-2 py-1.5 font-medium transition text-center {{ $discountType === 'custom' ? 'border-[#008fb3] bg-cyan-50 text-[#008fb3] font-bold' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">
                        Custom %
                    </button>
                </div>

                @if ($discountType === 'custom')
                    <div class="pt-1">
                        <label for="discountPercentage" class="block text-[11px] font-semibold text-slate-700">Custom Discount Rate (Max 100%)</label>
                        <div class="relative mt-0.5">
                            <input wire:model.live.debounce.300ms="discountPercentage" id="discountPercentage" type="number" step="0.5" min="0" max="100"
                                class="w-full rounded border-slate-300 pr-8 text-xs tabular-nums focus:border-slate-500 focus:ring-0" />
                            <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-xs text-slate-400 font-bold">%</span>
                        </div>
                    </div>
                @endif

                @if ((float) $discountPercentage > 0)
                    <!-- Mandatory Reason / Note -->
                    <div class="pt-1 space-y-1">
                        <label for="discountReason" class="block text-[11px] font-bold text-slate-700">
                            Discount Reason / Customer ID Note <span class="text-rose-600">*</span>
                        </label>
                        <input wire:model.live.debounce.300ms="discountReason" id="discountReason" type="text"
                            placeholder="e.g. Employee name/ID, Senior Citizen ID #, loyal contractor..."
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                        @error('discountReason')
                            <p class="text-[11px] text-rose-600 font-medium">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Authorization Status Indicator -->
                    <div class="pt-1">
                        @if (auth()->user()?->role === 'mixer')
                            @if ($discountAuthorized)
                                <div class="flex items-center justify-between rounded bg-emerald-50 border border-emerald-200 px-2.5 py-1.5 text-[11px] text-emerald-800">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="h-3.5 w-3.5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span>Authorized by <strong>{{ $discountAuthorizedByName }}</strong></span>
                                    </div>
                                    <button type="button" wire:click="openManagerAuthModal" class="text-emerald-700 hover:text-emerald-900 underline text-[10px]">Re-verify</button>
                                </div>
                            @else
                                <div class="rounded bg-amber-50 border border-amber-300 p-2.5 space-y-1.5 text-[11px]">
                                    <div class="flex items-start gap-1.5 text-amber-900 font-semibold">
                                        <svg class="h-4 w-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span>Manager Approval Required</span>
                                    </div>
                                    <p class="text-amber-800 text-[10px] leading-relaxed">
                                        Mixers must have an on-duty manager or supervisor enter their password to apply discounts.
                                    </p>
                                    <button type="button" wire:click="openManagerAuthModal"
                                        class="inline-flex items-center gap-1 rounded bg-amber-600 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-amber-700 shadow-xs transition">
                                        <span>Enter Manager Password</span>
                                    </button>
                                </div>
                            @endif
                        @else
                            <div class="flex items-center gap-1.5 text-[11px] text-slate-600 bg-slate-50 border border-slate-200 rounded px-2.5 py-1">
                                <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                <span>Approved by Cashier: <strong>{{ auth()->user()->name }}</strong> ({{ ucfirst(auth()->user()->role) }})</span>
                            </div>
                        @endif
                    </div>
                @endif

                @error('discountPercentage')
                    <p class="text-[11px] text-rose-600 font-semibold">{{ $message }}</p>
                @enderror
            </div>

            <!-- Financial Computation Summary -->
            <div class="rounded bg-slate-50 p-3 space-y-1.5 text-xs border border-slate-200 tabular-nums">
                <div class="flex justify-between text-slate-600">
                    <span class="font-sans font-normal">Subtotal:</span>
                    <span class="font-medium text-slate-900">{{ $currency::format($this->subtotal()) }}</span>
                </div>

                @if ($this->discountAmount() > 0)
                    <div class="flex justify-between text-emerald-700 font-semibold">
                        <span class="font-sans font-normal">Discount ({{ number_format((float) $discountPercentage, 2) }}%):</span>
                        <span>-{{ $currency::format($this->discountAmount()) }}</span>
                    </div>
                @endif

                <div class="flex justify-between text-slate-600">
                    <span class="font-sans font-normal">VAT ({{ number_format(self::TAX_RATE, 0) }}%):</span>
                    <span class="font-medium text-slate-900">+{{ $currency::format($this->taxAmount()) }}</span>
                </div>

                <div class="flex justify-between text-sm font-bold text-slate-900 border-t border-slate-200 pt-2">
                    <span class="font-sans font-bold">Total Due:</span>
                    <span class="text-base text-slate-900">{{ $currency::format($this->finalTotal()) }}</span>
                </div>
            </div>

            <!-- Tendered Cash Input -->
            <form wire:submit="completeSale" class="space-y-3 pt-1">
                <div class="space-y-1">
                    <label for="tenderedAmount" class="block text-xs font-bold text-slate-900">
                        Cash Tendered (₱) <span class="text-rose-600">*</span>
                    </label>
                    <input wire:model.live.debounce.150ms="tenderedAmount" id="tenderedAmount" type="number" step="0.01" min="0"
                        placeholder="0.00" autofocus
                        class="w-full rounded border-slate-300 text-sm tabular-nums font-bold text-slate-900 focus:border-slate-500 focus:ring-0" />
                    @error('tenderedAmount')
                        <p class="text-[11px] text-rose-600 font-semibold">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Change Due Calculation -->
                <div class="flex items-center justify-between rounded bg-emerald-50/50 border border-emerald-300 px-3 py-2 text-xs">
                    <span class="font-bold text-emerald-900 uppercase">Change Due:</span>
                    <span class="text-base font-bold text-emerald-800 tabular-nums">{{ $currency::format($this->changeDue()) }}</span>
                </div>

                <!-- Complete Button -->
                <button 
                    type="submit" 
                    wire:confirm="Confirm and complete this sale transaction?"
                    wire:loading.attr="disabled"
                    class="w-full rounded bg-[#00a3cc] px-4 py-2.5 font-bold uppercase tracking-wider text-white shadow-sm hover:bg-[#008fb3] transition disabled:opacity-50 text-xs"
                >
                    <span wire:loading.remove wire:target="completeSale">Complete Sale &amp; Print Receipt</span>
                    <span wire:loading wire:target="completeSale">Processing Transaction...</span>
                </button>
            </form>
        </div>
    </div>

    {{-- Manager Discount Authorization Modal --}}
    @if ($showManagerAuthModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-sm rounded border border-slate-300 bg-white shadow-xl text-xs overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-2.5 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="h-6 w-6 rounded bg-amber-100 text-amber-700 flex items-center justify-center font-bold text-xs">
                            🔒
                        </div>
                        <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-900">Manager Discount Approval</h2>
                    </div>
                    <button wire:click="closeManagerAuthModal" type="button" class="text-xl font-bold text-slate-400 hover:text-slate-700">&times;</button>
                </div>
                <form wire:submit="authorizeDiscount" class="p-4 space-y-3">
                    <p class="text-[11px] text-slate-600">
                        Please have an on-duty manager or administrator authorize this 
                        <strong class="text-slate-900">{{ number_format((float) $discountPercentage, 2) }}%</strong> discount.
                    </p>

                    <div>
                        <label for="managerAuthUserId" class="block font-semibold text-slate-700 mb-1">Authorizing Manager</label>
                        <select wire:model="managerAuthUserId" id="managerAuthUserId"
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0">
                            @foreach ($managers as $mgr)
                                <option value="{{ $mgr->id }}">{{ $mgr->name }} ({{ ucfirst($mgr->role) }})</option>
                            @endforeach
                        </select>
                        @error('managerAuthUserId')<p class="text-rose-600 text-[11px] mt-0.5">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="managerAuthPassword" class="block font-semibold text-slate-700 mb-1">Manager Password</label>
                        <input wire:model="managerAuthPassword" id="managerAuthPassword" type="password" placeholder="Enter password..." autofocus
                            class="w-full rounded border-slate-300 text-xs focus:border-slate-500 focus:ring-0" />
                        @error('managerAuthPassword')<p class="text-rose-600 text-[11px] mt-0.5 font-medium">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-2 pt-2 border-t border-slate-200">
                        <button type="button" wire:click="closeManagerAuthModal"
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

