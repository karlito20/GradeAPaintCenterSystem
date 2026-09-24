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
    public string $tenderedAmount = '';

    public function mount(): void
    {
        $this->cart = session('pos.cart', []);
        if ($this->cart === []) {
            $this->redirectRoute('sales.index');
        }
    }

    public function total(): float
    {
        return round(collect($this->cart)->sum('subtotal'), 2);
    }
    public function change(): float
    {
        return max(0, (float) $this->tenderedAmount - $this->total());
    }

    public function completeSale(): void
    {
        $total = $this->total();
        $this->validate(['tenderedAmount' => ['required', 'numeric', 'min:' . $total]]);
        $sale = null;
        try {
            DB::transaction(function () use ($total, &$sale): void {
                $sale = Sale::create(['user_id' => auth()->id(), 'invoice_number' => 'SALE-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)), 'sold_at' => now(), 'type' => collect($this->cart)->contains('type', 'custom_mix') ? 'mixed' : 'normal', 'subtotal' => $total, 'total' => $total, 'payment_method' => 'cash', 'payment_amount' => $this->tenderedAmount, 'change_amount' => $this->change()]);
                foreach ($this->cart as $line) {
                    if ($line['type'] === 'custom_mix') {
                        $sale->items()->create(['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'subtotal' => $line['subtotal']]);
                        $mix = MixingTransaction::create(['sale_id' => $sale->id, 'price_basis_product_id' => $line['basis_product_id'], 'resulting_quantity' => $line['resulting_quantity'], 'resulting_unit' => $line['resulting_unit'], 'notes' => $line['notes'] ?: null]);
                        foreach ($line['components'] as $component) {
                            $mix->components()->create(['product_id' => $component['product_id'], 'estimated_quantity' => $component['estimated_quantity'], 'estimated_quantity_unit' => $component['estimated_quantity_unit']]);
                        }
                        continue;
                    }
                    $product = Product::findOrFail($line['product_id']);
                    $inventory = Inventory::where('product_id', $product->id)
                        ->lockForUpdate()
                        ->firstOrCreate(['product_id' => $product->id], ['quantity' => 0]);
                    $before = (float) $inventory->quantity;
                    if ($before < $line['quantity']) {
                        throw ValidationException::withMessages(['tenderedAmount' => "Insufficient stock for {$product->name}."]);
                    }
                    $inventory->decrement('quantity', $line['quantity']);
                    $sale->items()->create(['product_id' => $product->id, 'description' => $line['description'] . ' ' . $line['package'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'subtotal' => $line['subtotal']]);
                    InventoryMovement::create(['product_id' => $product->id, 'user_id' => auth()->id(), 'type' => 'sale', 'quantity_change' => -$line['quantity'], 'quantity_before' => $before, 'quantity_after' => $before - $line['quantity'], 'reference_type' => Sale::class, 'reference_id' => $sale->id]);
                }
                AuditLog::create(['user_id' => auth()->id(), 'event' => 'sale_completed', 'auditable_type' => Sale::class, 'auditable_id' => $sale->id, 'context' => ['total' => $total, 'lines' => count($this->cart)]]);
            });
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
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
        return view('livewire.pages.sales.checkout', ['currency' => Currency::class]);
    }
}; ?>
<div class="mx-auto max-w-3xl space-y-4 px-4 py-5 text-sm sm:px-6 lg:px-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Payment</h1>
            <p class="mt-1 text-gray-600">Cash-only checkout</p>
        </div><a href="{{ route('sales.index') }}" class="text-[#008fb3]" wire:navigate>Back to POS</a>
    </div>
    <div class="rounded-lg bg-white shadow-sm">
        <div class="divide-y">
            @foreach ($cart as $line)
                <div class="flex justify-between px-4 py-3"><span>{{ $line['description'] }} × {{ $line['quantity'] }}
                        <small
                            class="text-gray-500">{{ $line['package'] ?: 'Custom Mix' }}</small></span><span>{{ $currency::format($line['subtotal']) }}</span>
                </div>
            @endforeach
        </div>
        <div class="flex justify-between border-t px-4 py-4 text-lg font-bold">
            <span>Total</span><span>{{ $currency::format($this->total()) }}</span>
        </div>
    </div>
    <form wire:submit="completeSale" class="space-y-4 rounded-lg bg-white p-4 shadow-sm"><x-input-label
            for="tenderedAmount" value="Tendered Amount" /><x-text-input wire:model.live="tenderedAmount"
            id="tenderedAmount" type="number" step="0.01" class="block w-full" autofocus />
        @error('tenderedAmount')
            <p class="text-red-600">{{ $message }}</p>
        @enderror
        <div class="flex justify-between border-t pt-3"><span>Change
                Due</span><strong>{{ $currency::format($this->change()) }}</strong></div><button type="submit"
            class="w-full rounded-md bg-[#00a3cc] px-4 py-3 font-bold uppercase tracking-wide text-white shadow-sm">Complete
            Sale</button>
    </form>
</div>
