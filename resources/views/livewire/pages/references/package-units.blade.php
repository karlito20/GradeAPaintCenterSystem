<?php
use App\Models\PackageUnit;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;
new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public string $name = '';
    public string $abbreviation = '';
    public ?int $editingId = null;
    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100'], 'abbreviation' => ['nullable', 'string', 'max:20']]);
        $unit = $this->editingId ? PackageUnit::findOrFail($this->editingId) : new PackageUnit();
        $unit->name = trim($this->name);
        $unit->abbreviation = $this->abbreviation ?: null;
        $unit->save();
        $this->reset(['name', 'abbreviation', 'editingId']);
        session()->flash('status', 'Package unit saved.');
    }
    public function edit(int $id): void
    {
        $unit = PackageUnit::findOrFail($id);
        $this->editingId = $id;
        $this->name = $unit->name;
        $this->abbreviation = $unit->abbreviation ?? '';
    }
    public function toggleActive(int $id): void
    {
        $unit = PackageUnit::findOrFail($id);
        $unit->update(['active' => !$unit->active]);
    }
    public function render(): mixed
    {
        return view('livewire.pages.references.package-units', [
            'packageUnits' => PackageUnit::where('name', 'like', '%' . $this->search . '%')
                ->latest()
                ->paginate(15),
        ]);
    }
}; ?>
<div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">Package Units</h1>
        <p class="mt-1 text-sm text-gray-600">Manage the approved packaging units used by products.</p>
    </div>
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    <form wire:submit="save" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-[1fr_180px_auto]">
        <x-text-input wire:model="name" placeholder="Display name" required /><x-text-input wire:model="abbreviation"
            placeholder="Abbreviation" /><x-primary-button>{{ $editingId ? 'Save changes' : 'Add unit' }}</x-primary-button>
    </form>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Abbreviation</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($packageUnits as $unit)
                    <tr>
                        <td class="px-3 py-2">{{ $unit->name }}</td>
                        <td class="px-3 py-2">{{ $unit->abbreviation }}</td>
                        <td class="px-3 py-2"><span
                                class="font-semibold {{ $unit->active ? 'text-green-700' : 'text-gray-500' }}">{{ $unit->active ? 'Active' : 'Deactivated' }}</span>
                        </td>
                        <td class="space-x-3 px-3 py-2"><button wire:click="edit({{ $unit->id }})" type="button"
                                class="text-[#008fb3]">Edit</button><button
                                wire:click="toggleActive({{ $unit->id }})" type="button"
                                class="{{ $unit->active ? 'text-red-600' : 'text-green-700' }}">{{ $unit->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </td>
                </tr>@empty<tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-500">No package units found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $packageUnits->links() }}</div>
    </div>
</div>
