<?php

use Livewire\Volt\Component;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;

new #[Layout('layouts.app')] class extends Component {
    public ?Product $sourceProduct = null;
    public ?Product $targetProduct = null;
    
    public string $searchSource = '';
    public string $searchTarget = '';
    
    public string $sourceProductId = '';
    public string $targetProductId = '';
    public string $location = 'storage';
    public string $quantityToCreate = '';
    public string $notes = '';

    public function selectSourceProduct(int $id)
    {
        $this->sourceProduct = Product::with(['inventories'])->find($id);
        $this->sourceProductId = (string) $id;
        $this->searchSource = '';
    }

    public function selectTargetProduct(int $id)
    {
        $this->targetProduct = Product::with(['inventories'])->find($id);
        $this->targetProductId = (string) $id;
        $this->searchTarget = '';
    }

    public function repack(InventoryService $service)
    {
        $this->validate([
            'sourceProductId' => 'required|exists:products,id',
            'targetProductId' => 'required|exists:products,id|different:sourceProductId',
            'location' => 'required|in:storage,storefront',
            'quantityToCreate' => 'required|integer|min:1|max:99999',
            'notes' => 'nullable|string|max:500'
        ], [
            'sourceProductId.required' => 'Please select a source product.',
            'targetProductId.required' => 'Please select a target product.',
            'targetProductId.different' => 'Target product cannot be the same as the source product.',
            'quantityToCreate.required' => 'Please enter the quantity to create.',
            'quantityToCreate.integer' => 'Quantity must be a whole number.',
            'quantityToCreate.min' => 'Quantity must be at least 1.',
            'quantityToCreate.max' => 'Quantity cannot exceed 99,999.',
        ]);

        $targetPackageSize = (float) $this->targetProduct->package_size;
        $sourcePackageSize = (float) $this->sourceProduct->package_size;

        if ($targetPackageSize <= 0) {
            $this->addError('targetProductId', 'Target product must have a valid package size.');
            return;
        }

        if ($sourcePackageSize <= 0) {
            $this->addError('sourceProductId', 'Source product must have a valid package size.');
            return;
        }

        $qtyToCreate = (int) $this->quantityToCreate;
        $totalVolumeNeeded = $targetPackageSize * $qtyToCreate;
        $sourceUnitsNeeded = ceil($totalVolumeNeeded / $sourcePackageSize);

        $sourceCurrentStock = (float) ($this->sourceProduct->getInventory($this->location)?->quantity ?? 0);
        if ($sourceCurrentStock < $sourceUnitsNeeded) {
            $this->addError('quantityToCreate', "Not enough stock in {$this->location}. Need {$sourceUnitsNeeded} units, have {$sourceCurrentStock}.");
            return;
        }

        DB::transaction(function () use ($service, $sourceUnitsNeeded, $qtyToCreate) {
            // Deduct units from source product
            $sourceInv = $service->updateInventoryBalance($this->sourceProduct->id, $this->location, -$sourceUnitsNeeded);
            $service->logMovement([
                'product_id' => $this->sourceProduct->id,
                'location' => $this->location,
                'user_id' => auth()->id(),
                'type' => 'repack_out',
                'quantity_change' => -$sourceUnitsNeeded,
                'quantity_before' => $sourceInv->quantity + $sourceUnitsNeeded,
                'quantity_after' => $sourceInv->quantity,
                'reference_type' => null,
                'reference_id' => null,
                'reference_text' => 'Repacked to ' . $this->targetProduct->name . ($this->notes ? ' - ' . $this->notes : ''),
            ]);

            // Add units to target product
            $targetInv = $service->updateInventoryBalance($this->targetProduct->id, $this->location, $qtyToCreate);
            $service->logMovement([
                'product_id' => $this->targetProduct->id,
                'location' => $this->location,
                'user_id' => auth()->id(),
                'type' => 'repack_in',
                'quantity_change' => $qtyToCreate,
                'quantity_before' => $targetInv->quantity - $qtyToCreate,
                'quantity_after' => $targetInv->quantity,
                'reference_type' => null,
                'reference_id' => null,
                'reference_text' => 'Repacked from ' . $this->sourceProduct->name . ($this->notes ? ' - ' . $this->notes : ''),
            ]);
        });

        session()->flash('status', 'Repacking completed successfully.');
        $this->redirectRoute('inventory.repack');
    }

    public function render(): mixed
    {
        $sourceResults = collect();
        if ($this->searchSource !== '') {
            $sourceResults = Product::where('active', true)
                ->where(function($q) {
                    $q->where('name', 'like', '%' . $this->searchSource . '%')
                      ->orWhere('sku', 'like', '%' . $this->searchSource . '%');
                })->take(10)->get();
        }

        $targetResults = collect();
        if ($this->searchTarget !== '') {
            $targetResults = Product::where('active', true)
                ->where(function($q) {
                    $q->where('name', 'like', '%' . $this->searchTarget . '%')
                      ->orWhere('sku', 'like', '%' . $this->searchTarget . '%');
                })->take(10)->get();
        }

        return view('livewire.pages.inventory.repack', [
            'sourceResults' => $sourceResults,
            'targetResults' => $targetResults,
        ]);
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-col gap-2 border-b border-slate-300 pb-3">
        <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Inventory Repacking</h1>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-2.5 text-xs text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr]">
        <div class="rounded-lg border border-slate-300 bg-white p-4">
            <h2 class="text-xs font-bold uppercase mb-4">1. Select Source Product (To repack from)</h2>
            <div class="relative mb-4">
                <input wire:model.live.debounce.300ms="searchSource" type="search" placeholder="Search product..." class="w-full text-xs rounded border-slate-300">
                @if ($sourceResults->isNotEmpty())
                    <div class="absolute z-10 w-full bg-white border border-slate-300 mt-1 shadow-lg max-h-48 overflow-y-auto">
                        @foreach($sourceResults as $res)
                            <button wire:click="selectSourceProduct({{ $res->id }})" class="block w-full text-left px-3 py-2 text-xs hover:bg-slate-100">
                                {{ $res->name }} ({{ $res->sku }}) - {{ $res->package_size }}{{ $res->packageUnit?->abbreviation }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($sourceProduct)
                <div class="p-3 bg-cyan-50 border border-cyan-200 rounded mb-4">
                    <p class="font-bold text-xs">{{ $sourceProduct->name }}</p>
                    <p class="text-[10px] mt-1 text-slate-600">Size: {{ $sourceProduct->package_size }} {{ $sourceProduct->packageUnit?->abbreviation }}</p>
                    <p class="text-[10px] text-slate-600">Storage: {{ number_format($sourceProduct->getInventory('storage')?->quantity ?? 0, 2) }} | Storefront: {{ number_format($sourceProduct->getInventory('storefront')?->quantity ?? 0, 2) }}</p>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-4">
            <h2 class="text-xs font-bold uppercase mb-4">2. Select Target Product (To create)</h2>
            <div class="relative mb-4">
                <input wire:model.live.debounce.300ms="searchTarget" type="search" placeholder="Search product..." class="w-full text-xs rounded border-slate-300">
                @if ($targetResults->isNotEmpty())
                    <div class="absolute z-10 w-full bg-white border border-slate-300 mt-1 shadow-lg max-h-48 overflow-y-auto">
                        @foreach($targetResults as $res)
                            <button wire:click="selectTargetProduct({{ $res->id }})" class="block w-full text-left px-3 py-2 text-xs hover:bg-slate-100">
                                {{ $res->name }} ({{ $res->sku }}) - {{ $res->package_size }}{{ $res->packageUnit?->abbreviation }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($targetProduct)
                <div class="p-3 bg-emerald-50 border border-emerald-200 rounded mb-4">
                    <p class="font-bold text-xs">{{ $targetProduct->name }}</p>
                    <p class="text-[10px] mt-1 text-slate-600">Size: {{ $targetProduct->package_size }} {{ $targetProduct->packageUnit?->abbreviation }}</p>
                </div>
            @endif
        </div>
    </div>
    
    @if ($sourceProduct && $targetProduct)
    <div class="rounded-lg border border-slate-300 bg-white p-4 mt-4">
        <h2 class="text-xs font-bold uppercase mb-4">3. Repack Details</h2>
        <form wire:submit="repack" class="space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold mb-1">Location</label>
                    <select wire:model="location" class="w-full rounded border-slate-300 text-xs">
                        <option value="storage">Storage</option>
                        <option value="storefront">Storefront</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold mb-1">Quantity of {{ $targetProduct->name }} to Create <span class="text-rose-500">*</span></label>
                    <input wire:model="quantityToCreate" type="number" step="1" min="1" max="99999"
                        inputmode="numeric"
                        x-on:keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                        class="w-full rounded border-slate-300 text-xs tabular-nums" required>
                    @error('quantityToCreate') <span class="text-rose-600 block mt-1">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block font-bold mb-1">Notes</label>
                <input wire:model="notes" type="text" class="w-full rounded border-slate-300 text-xs">
            </div>

            <div class="pt-2">
                <button type="submit" class="bg-[#00a3cc] text-white px-4 py-2 rounded font-bold hover:bg-[#008fb3]">Complete Repack</button>
            </div>
        </form>
    </div>
    @endif
</div>
