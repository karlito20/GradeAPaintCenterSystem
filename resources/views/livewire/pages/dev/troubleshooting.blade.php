<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?string $actionToConfirm = null;
    public string $confirmationInput = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessTroubleshooting(), 403);
    }

    public function selectAction(string $action): void
    {
        abort_unless(auth()->user()?->canAccessTroubleshooting(), 403);
        $this->actionToConfirm = $action;
        $this->confirmationInput = '';
    }

    public function cancelAction(): void
    {
        $this->actionToConfirm = null;
        $this->confirmationInput = '';
    }

    public function executeAction(): void
    {
        abort_unless(auth()->user()?->canAccessTroubleshooting(), 403);

        if ($this->confirmationInput !== 'RESET') {
            $this->addError('confirmationInput', 'You must type RESET in all caps to confirm.');
            return;
        }

        $action = $this->actionToConfirm;

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            switch ($action) {
                case 'sales_and_mixing':
                    DB::table('mixing_components')->truncate();
                    DB::table('mixing_transactions')->truncate();
                    DB::table('sale_items')->truncate();
                    DB::table('sales')->truncate();
                    $message = 'Sales and custom mix test data cleared successfully.';
                    break;

                case 'stock_in':
                    DB::table('stock_in_items')->truncate();
                    DB::table('stock_ins')->truncate();
                    $message = 'Stock-in records cleared successfully.';
                    break;

                case 'physical_inventory':
                    DB::table('physical_inventory_items')->truncate();
                    DB::table('physical_inventories')->truncate();
                    $message = 'Physical inventory records cleared successfully.';
                    break;

                case 'inventory_movements':
                    DB::table('inventory_movements')->truncate();
                    $message = 'Inventory movements history cleared successfully.';
                    break;

                case 'reset_stock_to_zero':
                    DB::table('inventories')->update(['quantity' => 0]);
                    $message = 'All product inventory quantities reset to 0.';
                    break;

                case 'audit_logs':
                    DB::table('audit_logs')->truncate();
                    $message = 'Audit logs cleared successfully.';
                    break;

                default:
                    throw new \InvalidArgumentException('Unknown troubleshooting action.');
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'developer_table_cleared',
                'auditable_type' => 'System',
                'auditable_id' => null,
                'context' => [
                    'action' => $action,
                    'executed_at' => now()->toIso8601String(),
                ],
            ]);

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Action failed: ' . $e->getMessage(),
            ]);
        } finally {
            $this->actionToConfirm = null;
            $this->confirmationInput = '';
        }
    }

    public function render(): mixed
    {
        $tables = [
            'sales' => DB::table('sales')->count(),
            'sale_items' => DB::table('sale_items')->count(),
            'mixing_transactions' => DB::table('mixing_transactions')->count(),
            'mixing_components' => DB::table('mixing_components')->count(),
            'stock_ins' => DB::table('stock_ins')->count(),
            'stock_in_items' => DB::table('stock_in_items')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'physical_inventories' => DB::table('physical_inventories')->count(),
            'physical_inventory_items' => DB::table('physical_inventory_items')->count(),
            'inventories' => DB::table('inventories')->count(),
            'products' => DB::table('products')->count(),
            'brands' => DB::table('brands')->count(),
            'categories' => DB::table('categories')->count(),
            'package_units' => DB::table('package_units')->count(),
            'users' => DB::table('users')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ];

        return view('livewire.pages.dev.troubleshooting', [
            'tableCounts' => $tables,
        ]);
    }
}; ?>

<div class="space-y-5 w-full min-w-0 text-sm">
    <div class="flex items-center justify-between pb-2 border-b border-gray-200">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded bg-purple-100 px-2 py-0.5 text-xs font-bold text-purple-800">DEV ONLY</span>
                <h1 class="text-xl font-bold text-gray-900">Developer Troubleshooting &amp; Test Data Reset</h1>
            </div>
        </div>
    </div>

    <!-- Table Counts Overview -->
    <div class="rounded-md border border-gray-200 bg-white p-4 shadow-sm">
        <h2 class="text-xs font-normal uppercase tracking-wider text-gray-500 mb-3">Database Tables &amp; Record Counts</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
            @foreach ($tableCounts as $tableName => $count)
                <div class="rounded border border-gray-100 bg-gray-50 p-2 flex flex-col justify-between">
                    <p class="text-[11px] font-normal text-gray-500 truncate" title="{{ $tableName }}">{{ $tableName }}</p>
                    <p class="text-base font-light tabular-nums text-gray-900 text-right">{{ number_format($count) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Actions Grid -->
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <!-- Clear Sales -->
        <div class="rounded-md border border-red-200 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-red-900">Clear Sales &amp; Custom Mix</h3>
                    <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">Transactional</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Truncates `sales`, `sale_items`, `mixing_transactions`, and `mixing_components`. Preserves products and inventory baselines.</p>
            </div>
            <button wire:click="selectAction('sales_and_mixing')" type="button"
                class="mt-4 rounded border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition">
                Reset Sales Data
            </button>
        </div>

        <!-- Clear Stock In -->
        <div class="rounded-md border border-amber-200 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-amber-900">Clear Stock-In Records</h3>
                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">Transactional</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Truncates `stock_ins` and `stock_in_items`. Does not alter current stock quantities automatically.</p>
            </div>
            <button wire:click="selectAction('stock_in')" type="button"
                class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition">
                Reset Stock-In Data
            </button>
        </div>

        <!-- Clear Physical Inventory -->
        <div class="rounded-md border border-amber-200 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-amber-900">Clear Physical Inventory</h3>
                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">Transactional</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Truncates `physical_inventories` and `physical_inventory_items` history.</p>
            </div>
            <button wire:click="selectAction('physical_inventory')" type="button"
                class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition">
                Reset Physical Counts
            </button>
        </div>

        <!-- Clear Inventory Movements -->
        <div class="rounded-md border border-orange-200 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-orange-900">Clear Movement Logs</h3>
                    <span class="rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-700">Audit Trail</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Truncates `inventory_movements` log table. Useful after clearing test transactions.</p>
            </div>
            <button wire:click="selectAction('inventory_movements')" type="button"
                class="mt-4 rounded border border-orange-300 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800 hover:bg-orange-100 transition">
                Reset Movement Logs
            </button>
        </div>

        <!-- Reset All Stock to 0 -->
        <div class="rounded-md border border-purple-200 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-purple-900">Reset Stock to 0</h3>
                    <span class="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-bold text-purple-700">Inventory</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Sets `quantity = 0` on every record in `inventories`. Retains all products and SKUs intact.</p>
            </div>
            <button wire:click="selectAction('reset_stock_to_zero')" type="button"
                class="mt-4 rounded border border-purple-300 bg-purple-50 px-3 py-1.5 text-xs font-semibold text-purple-800 hover:bg-purple-100 transition">
                Zero All Stock Quantities
            </button>
        </div>

        <!-- Clear Audit Logs -->
        <div class="rounded-md border border-gray-300 bg-white p-4 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-gray-900">Clear Audit Logs</h3>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-700">Logging</span>
                </div>
                <p class="mt-1 text-xs text-gray-600">Truncates `audit_logs`. Immediately records a new audit event for this reset action.</p>
            </div>
            <button wire:click="selectAction('audit_logs')" type="button"
                class="mt-4 rounded border border-gray-300 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                Reset Audit Trail
            </button>
        </div>
    </div>

    <!-- Strong Confirmation Modal -->
    @if ($actionToConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl border border-red-300">
                <div class="flex items-center gap-3 text-red-600">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Developer Confirmation</h3>
                        <p class="text-xs text-red-600 font-semibold">Action: {{ strtoupper(str_replace('_', ' ', $actionToConfirm)) }}</p>
                    </div>
                </div>

                <div class="mt-4 space-y-3 text-xs text-gray-700">
                    <p>This action is irreversible and directly modifies or truncates live database tables.</p>
                    <p class="font-medium">To confirm, type <strong class="text-red-700 font-mono">RESET</strong> in the field below:</p>

                    <div>
                        <input wire:model="confirmationInput" type="text" placeholder="Type RESET"
                            class="w-full rounded border-gray-300 font-mono text-center text-sm font-bold tracking-widest focus:border-red-500 focus:ring-red-500" />
                        @error('confirmationInput')
                            <p class="mt-1 text-red-600 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button wire:click="cancelAction" type="button" class="rounded-md border border-gray-300 bg-white px-3.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="executeAction" wire:loading.attr="disabled" type="button" class="rounded-md bg-red-600 px-4 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="executeAction">Execute Reset</span>
                        <span wire:loading wire:target="executeAction">Processing...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
