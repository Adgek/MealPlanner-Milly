<?php

namespace App\Livewire\Concerns;

use App\Models\Ingredient;
use App\Models\KitchenProject;
use App\Models\Recipe;

/**
 * The "what to buy for it" repeater, shared by the recipe editor and the
 * kitchen-project editor (MM-15).
 *
 * `ingredients` is one polymorphic table for both parents, because the grocery
 * list merges the window's meals with the projects that are due and merging them
 * is then one query (MM-3). The two editors share this for the same reason: an
 * amount typed on a project has to end up in exactly the shape an amount typed
 * on a recipe does, or the merge in MM-7 silently stops matching them up.
 */
trait EditsIngredients
{
    /**
     * Amounts are held as strings: a cleared number field arrives as "" and has
     * to stay empty rather than quietly becoming 0.
     *
     * @var array<int, array{name: string, quantity: string, unit: string, store_section: string, pantry_basic: bool}>
     */
    public array $ingredients = [];

    public function addIngredient(): void
    {
        $this->ingredients[] = $this->blankIngredient();
    }

    /**
     * Drop an ingredient row. It leaves the recipe or project when the form is
     * saved, so walking away undoes it.
     */
    public function removeIngredient(int $index): void
    {
        unset($this->ingredients[$index]);

        $this->ingredients = array_values($this->ingredients);

        $this->resetValidation();
    }

    /**
     * The sections already in use, for the datalist.
     *
     * Store sections are free text by decision — they depend on the household's
     * store (MM-3) — so the app suggests rather than constrains. Suggesting
     * matters: "Dairy" and "dairy " would be two sections on the grocery list.
     *
     * @return array<int, string>
     */
    public function storeSections(): array
    {
        return Ingredient::query()
            ->whereNotNull('store_section')
            ->where('store_section', '!=', '')
            ->distinct()
            ->orderBy('store_section')
            ->pluck('store_section')
            ->all();
    }

    protected function fillIngredientsFrom(Recipe|KitchenProject $owner): void
    {
        $this->ingredients = $owner->ingredients
            ->map(fn (Ingredient $ingredient): array => [
                'name' => $ingredient->name,
                'quantity' => $ingredient->quantity === null ? '' : $this->tidyAmount($ingredient->quantity),
                'unit' => (string) $ingredient->unit,
                'store_section' => (string) $ingredient->store_section,
                'pantry_basic' => $ingredient->pantry_basic,
            ])
            ->all();
    }

    /**
     * Rewrite the rows.
     *
     * Deleted and written again rather than matched up one by one: nothing points
     * at an ingredient row by id — MM-7 copies the amounts into
     * `plan_grocery_items` when it builds the list — so there is no identity to
     * preserve, and a rewrite cannot leave a list half updated.
     */
    protected function saveIngredientsFor(Recipe|KitchenProject $owner): void
    {
        $owner->ingredients()->delete();

        foreach ($this->ingredients as $ingredient) {
            $owner->ingredients()->create([
                'name' => $ingredient['name'],
                'quantity' => $ingredient['quantity'] === '' ? null : (float) $ingredient['quantity'],
                'unit' => $ingredient['unit'] === '' ? null : $ingredient['unit'],
                'store_section' => $ingredient['store_section'] === '' ? null : $ingredient['store_section'],
                'pantry_basic' => (bool) $ingredient['pantry_basic'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function ingredientRules(): array
    {
        return [
            'ingredients' => ['array', 'max:80'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            // Two decimal places, because that is what the column stores: a third
            // would be rounded away without anybody being told.
            'ingredients.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:40'],
            'ingredients.*.store_section' => ['nullable', 'string', 'max:60'],
            'ingredients.*.pantry_basic' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function ingredientMessages(): array
    {
        return [
            'ingredients.max' => 'That is more ingredients than one list needs.',
            'ingredients.*.name.required' => 'Name the ingredient, or remove the row.',
            'ingredients.*.name.max' => 'Keep an ingredient name to 120 characters or fewer.',
            'ingredients.*.quantity.numeric' => 'Give the amount as a number, like 1 or 0.5. The unit goes in its own box.',
            'ingredients.*.quantity.decimal' => 'Amounts are stored to two decimal places. Round it first.',
            'ingredients.*.quantity.min' => 'An amount cannot be negative.',
            'ingredients.*.quantity.max' => 'That is a bigger amount than the list can hold.',
            'ingredients.*.unit.max' => 'Keep the unit short, like lb, g or can.',
            'ingredients.*.store_section.max' => 'Keep the store section to 60 characters or fewer.',
        ];
    }

    /**
     * Trim every box, and drop the rows nobody typed in.
     *
     * A row where every box is empty is not a validation error, it is a row that
     * was added and thought better of — including the blank one a new recipe
     * starts with.
     */
    protected function tidyIngredients(): void
    {
        $this->ingredients = array_values(array_filter(
            array_map(fn (array $row): array => [
                'name' => trim((string) ($row['name'] ?? '')),
                'quantity' => trim((string) ($row['quantity'] ?? '')),
                'unit' => trim((string) ($row['unit'] ?? '')),
                'store_section' => trim((string) ($row['store_section'] ?? '')),
                'pantry_basic' => (bool) ($row['pantry_basic'] ?? false),
            ], $this->ingredients),
            fn (array $row): bool => $row['name'] !== ''
                || $row['quantity'] !== ''
                || $row['unit'] !== ''
                || $row['store_section'] !== '',
        ));
    }

    /**
     * @return array{name: string, quantity: string, unit: string, store_section: string, pantry_basic: bool}
     */
    protected function blankIngredient(): array
    {
        return [
            'name' => '',
            'quantity' => '',
            'unit' => '',
            'store_section' => '',
            'pantry_basic' => false,
        ];
    }

    /**
     * "1.00" reads as "1" in a form, and "0.50" as "0.5".
     */
    protected function tidyAmount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
