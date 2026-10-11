<?php

use App\Models\Product;
use App\Services\InventoryService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.app')] class extends Component {
    public ?Product $product = null;
    public string $search = '';
    
    // Form fields
    public string $productId = '';
    public string $quantity = '';
    public string $fromLocation = 'storage';
    public string $toLocation = 'storefront';
    public string $notes = '';

    public function selectProduct(int $id)
    {
        $this->product = Product::with('inventories')->find($id);
        $this->productId = (string) $id;
        $this->search = '';
    }

    public function transfer(InventoryService $service)
    {
        $this->validate([
            'productId' => 'required|exists:products,id',
            'fromLocation' => 'required|in:storage,storefront',
            'toLocation' => 'required|in:storage,storefront|different:fromLocation',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string'
        ]);

        // Validate enough stock
        $currentStock = (float) ($this->product->getInventory($this->fromLocation)?->quantity ?? 0);
        if ((float) $this->quantity > $currentStock) {
            $this->addError('quantity', 'Not enough stock in ' . ucfirst($this->fromLocation));
            return;
        }

        $service->transferStock(
            $this->product,
            $this->fromLocation,
            $this->toLocation,
            (float) $this->quantity,
            null,
            auth()->id(),
            $this->notes
        );

        session()->flash('status', 'Transfer completed successfully.');
        $this->redirectRoute('inventory.transfers');
    }

    public function render(): mixed
    {
        $searchResults = collect();
        if ($this->search !== '') {
            $searchResults = Product::where('active', true)
                ->where(function($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('sku', 'like', '%' . $this->search . '%');
                })->take(10)->get();
        }

        return view('livewire.pages.inventory.transfer', [
            'searchResults' => $searchResults
        ]);
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-col gap-2 border-b border-slate-300 pb-3">
        <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Inventory Transfers</h1>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-2.5 text-xs text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[380px_minmax(0,1fr)]">
        <div class="rounded-lg border border-slate-300 bg-white p-4">
            <h2 class="text-xs font-bold uppercase mb-4">1. Select Product</h2>
            <div class="relative">
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search product..." class="w-full text-xs rounded border-slate-300">
                @if ($searchResults->isNotEmpty())
                    <div class="absolute z-10 w-full bg-white border border-slate-300 mt-1 shadow-lg max-h-48 overflow-y-auto">
                        @foreach($searchResults as $res)
                            <button wire:click="selectProduct({{ $res->id }})" class="block w-full text-left px-3 py-2 text-xs hover:bg-slate-100">
                                {{ $res->name }} ({{ $res->sku }})
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($product)
                <div class="mt-4 p-3 bg-cyan-50 border border-cyan-200 rounded">
                    <p class="font-bold text-xs">{{ $product->name }}</p>
                    <div class="text-[10px] mt-1 text-slate-600">
                        Storage: {{ number_format($product->getInventory('storage')?->quantity ?? 0, 2) }}<br>
                        Storefront: {{ number_format($product->getInventory('storefront')?->quantity ?? 0, 2) }}
                    </div>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-4">
            <h2 class="text-xs font-bold uppercase mb-4">2. Transfer Details</h2>
            @if ($product)
                <form wire:submit="transfer" class="space-y-4 text-xs">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block font-bold mb-1">From Location</label>
                            <select wire:model="fromLocation" class="w-full rounded border-slate-300 text-xs">
                                <option value="storage">Storage</option>
                                <option value="storefront">Storefront</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold mb-1">To Location</label>
                            <select wire:model="toLocation" class="w-full rounded border-slate-300 text-xs">
                                <option value="storage">Storage</option>
                                <option value="storefront">Storefront</option>
                            </select>
                            @error('toLocation') <span class="text-rose-600">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold mb-1">
                            Quantity to Transfer <span class="text-rose-500">*</span>
                        </label>
                        <input wire:model="quantity" type="number" step="0.01" class="w-full rounded border-slate-300 text-xs" required>
                        @error('quantity') <span class="text-rose-600">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-bold mb-1">Notes</label>
                        <input wire:model="notes" type="text" class="w-full rounded border-slate-300 text-xs">
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="bg-[#00a3cc] text-white px-4 py-2 rounded font-bold hover:bg-[#008fb3]">Complete Transfer</button>
                    </div>
                </form>
            @else
                <p class="text-slate-500 text-xs">Select a product first.</p>
            @endif
        </div>
    </div>
</div>
