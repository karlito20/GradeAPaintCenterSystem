<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MixingTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public array $cart = [];
    public string $discountPercentage = '0';
    public string $tenderedAmount = '';

    public function mount(): void
    {
        if (empty($this->cart)) {
            $this->cart = session('pos.cart', []);
        }

        if (empty($this->cart) && !app()->runningUnitTests()) {
            $this->redirectRoute('sales.index');
        }
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

    public function finalTotal(): float
    {
        return max(0, round($this->subtotal() - $this->discountAmount(), 2));
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

        $this->validate([
            'discountPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'tenderedAmount' => ['required', 'numeric', 'min:' . $finalTotal],
        ], [
            'tenderedAmount.min' => 'Tendered cash must be at least the total due (' . Currency::format($finalTotal) . ').',
        ]);

        $sale = null;

        try {
            DB::transaction(function () use ($subtotal, $discountPct, $discountAmt, $finalTotal, &$sale): void {
                $hasCustomMix = collect($this->cart)->contains('type', 'custom_mix');

                $sale = Sale::create([
                    'user_id' => auth()->id(),
                    'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                    'sold_at' => now(),
                    'type' => $hasCustomMix ? 'mixed' : 'normal',
                    'subtotal' => $subtotal,
                    'discount_percentage' => $discountPct,
                    'discount_amount' => $discountAmt,
                    'total' => $finalTotal,
                    'payment_method' => 'cash',
                    'payment_amount' => (float) $this->tenderedAmount,
                    'change_amount' => $this->changeDue(),
                ]);

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

        session()->forget('pos.cart');
        $this->redirectRoute('sales.receipt', ['sale' => $sale]);
    }

    public function render(): mixed
    {
        return view('livewire.pages.sales.checkout', [
            'currency' => Currency::class,
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
                        <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                            <tr>
                                <th class="border border-slate-300 px-3 py-2 text-left w-28">Brand</th>
                                <th class="border border-slate-300 px-3 py-2 text-left min-w-[180px]">Item Description</th>
                                <th class="border border-slate-300 px-3 py-2 text-left">Package / Spec</th>
                                <th class="border border-slate-300 px-3 py-2 text-right">Qty</th>
                                <th class="border border-slate-300 px-3 py-2 text-right">Unit Price</th>
                                <th class="border border-slate-300 px-3 py-2 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cart as $line)
                                <tr class="hover:bg-slate-50">
                                    <td class="border border-slate-200 px-3 py-2 text-slate-700 whitespace-nowrap">
                                        {{ $line['brand'] ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 font-medium text-slate-900 min-w-[180px] break-words" title="{{ $line['description'] }}">
                                        {{ $line['description'] }}
                                        @if ($line['type'] === 'custom_mix')
                                            <span class="block text-[10px] text-purple-700 font-semibold">Custom Mixed Paint Formula</span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-slate-600">
                                        {{ $line['type'] === 'custom_mix' ? ($line['package'] ?? 'Custom Mix') : ($line['package'] ?? $line['unit'] ?? 'Default') }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-2 text-right tabular-nums text-slate-900">
                                        {{ number_format((float) $line['quantity'], 3) }}
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
            <div class="border-t border-slate-300 px-4 py-2.5 bg-slate-100 flex items-center justify-between text-xs font-semibold text-slate-700">
                <span class="uppercase tracking-wider">Items Subtotal:</span>
                <span class="text-sm font-bold tabular-nums text-slate-900">{{ $currency::format($this->subtotal()) }}</span>
            </div>
        </div>

        <!-- Payment Computation & Tender Form -->
        <div class="rounded border border-slate-300 bg-white p-4 shadow-sm space-y-3.5">
            <h2 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800 pb-2 border-b border-slate-200">Payment Tender</h2>

            <!-- Discount Input -->
            <div class="space-y-1">
                <label for="discountPercentage" class="block text-xs font-semibold text-slate-700">
                    Discount Percentage (0–100%)
                </label>
                <div class="relative">
                    <input wire:model.live.debounce.300ms="discountPercentage" id="discountPercentage" type="number" step="0.01" min="0" max="100"
                        placeholder="0"
                        class="w-full rounded border-slate-300 pr-8 text-xs tabular-nums focus:border-slate-500 focus:ring-0" />
                    <span class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-xs text-slate-400 font-bold">%</span>
                </div>
                @error('discountPercentage')
                    <p class="text-[11px] text-rose-600">{{ $message }}</p>
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
</div>

