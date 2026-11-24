{{--
    The "what to buy for it" repeater, shared by the recipe editor and the
    kitchen-project editor (MM-15).

    Both bind to an `ingredients` property of the same shape
    (App\Livewire\Concerns\EditsIngredients), so the two screens cannot drift
    apart — which matters, because the grocery list merges what they produce into
    one table.

    One bordered block per ingredient, full-width controls, and the amount and
    unit side by side because they are read as one thing.
--}}
@props([
    'ingredients' => [],
    'sections' => [],
    'empty' => 'No ingredients yet.',
])

<x-form.error field="ingredients" />

{{-- The sections already in use, so a typo cannot split one in two. --}}
<datalist id="store-sections">
    @foreach ($sections as $section)
        <option value="{{ $section }}"></option>
    @endforeach
</datalist>

@forelse ($ingredients as $index => $ingredient)
    <div wire:key="ingredient-{{ $index }}" class="rounded-lg border border-stone-200 p-3">
        <x-form.input
            field="ingredients.{{ $index }}.name"
            label="Ingredient"
            :value="$ingredient['name'] ?? ''"
            autocomplete="off"
            maxlength="120"
        />

        <div class="mt-3 grid grid-cols-2 gap-3">
            <x-form.input
                field="ingredients.{{ $index }}.quantity"
                label="Amount"
                type="number"
                inputmode="decimal"
                step="0.01"
                min="0"
                :value="$ingredient['quantity'] ?? ''"
            />

            <x-form.input
                field="ingredients.{{ $index }}.unit"
                label="Unit"
                :value="$ingredient['unit'] ?? ''"
                autocomplete="off"
                maxlength="40"
                placeholder="lb, g, can"
            />
        </div>

        <div class="mt-3">
            <x-form.input
                field="ingredients.{{ $index }}.store_section"
                label="Store section"
                :value="$ingredient['store_section'] ?? ''"
                autocomplete="off"
                maxlength="60"
                list="store-sections"
                placeholder="Produce, Meat, Dairy…"
            />
        </div>

        <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <x-form.checkbox
                field="ingredients.{{ $index }}.pantry_basic"
                label="Something we probably already have"
                :checked="(bool) ($ingredient['pantry_basic'] ?? false)"
            />

            <x-button tone="danger" wire:click="removeIngredient({{ $index }})" :block="false">
                Remove<span class="sr-only"> ingredient {{ $index + 1 }}</span>
            </x-button>
        </div>
    </div>
@empty
    <x-empty-state>{{ $empty }}</x-empty-state>
@endforelse

<x-button wire:click="addIngredient">Add an ingredient</x-button>
