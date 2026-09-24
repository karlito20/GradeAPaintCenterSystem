<?php
use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;
new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $search = '';
    public string $name = '';
    public ?int $editingId = null;
    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100']]);
        $category = $this->editingId ? Category::findOrFail($this->editingId) : new Category();
        $category->name = trim($this->name);
        $category->save();
        $this->reset(['name', 'editingId']);
        session()->flash('status', 'Category saved.');
    }
    public function edit(int $id): void
    {
        $category = Category::findOrFail($id);
        $this->editingId = $id;
        $this->name = $category->name;
    }
    public function toggleActive(int $id): void
    {
        $category = Category::findOrFail($id);
        $category->update(['active' => !$category->active]);
    }
    public function render(): mixed
    {
        return view('livewire.pages.references.categories', [
            'categories' => Category::where('name', 'like', '%' . $this->search . '%')
                ->latest()
                ->paginate(15),
        ]);
    }
}; ?>
<div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold">Categories</h1>
        <p class="mt-1 text-sm text-gray-600">Manage product category reference data.</p>
    </div>
    @if (session('status'))
        <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    <form wire:submit="save" class="flex gap-3 rounded-lg bg-white p-4 shadow-sm">
        <x-text-input wire:model="name" placeholder="Category name" class="flex-1"
            required /><x-primary-button>{{ $editingId ? 'Save changes' : 'Add category' }}</x-primary-button>
    </form>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($categories as $category)
                    <tr>
                        <td class="px-3 py-2">{{ $category->name }}</td>
                        <td class="px-3 py-2"><span
                                class="font-semibold {{ $category->active ? 'text-green-700' : 'text-gray-500' }}">{{ $category->active ? 'Active' : 'Deactivated' }}</span>
                        </td>
                        <td class="space-x-3 px-3 py-2"><button wire:click="edit({{ $category->id }})" type="button"
                                class="text-[#008fb3]">Edit</button><button
                                wire:click="toggleActive({{ $category->id }})" type="button"
                                class="{{ $category->active ? 'text-red-600' : 'text-green-700' }}">{{ $category->active ? 'Deactivate' : 'Reactivate' }}</button>
                        </td>
                </tr>@empty<tr>
                        <td colspan="3" class="px-6 py-8 text-center text-gray-500">No categories found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $categories->links() }}</div>
    </div>
</div>
