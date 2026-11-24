<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsRows;
use App\Models\BulkIngredient;
use App\Settings\HouseholdSettings;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The bulk packs a recipe can be tagged with, and how long the raw second half
 * keeps (MM-15).
 *
 * A short list of two fields, so it is one form: add a row, type a name, save.
 * Its own page rather than a card on the recipe list, because the recipe list is
 * as long as the vault and this is reached while tagging a recipe.
 *
 * The fridge life is per ingredient and may be left empty, in which case the
 * household's raw-split default from Settings applies (MM-3). Nothing here is a
 * constant: the number in the box is this ingredient's own data, and the
 * fallback is a setting.
 */
#[Title('Bulk ingredients')]
class BulkIngredients extends Component
{
    use EditsRows;

    /**
     * The longest a raw split can be asked to keep, which is the bound the
     * Settings page puts on the same value: past a fortnight it is not a raw
     * split, it is a freezer item.
     */
    private const MAX_FRIDGE_LIFE_DAYS = 14;

    public function mount(): void
    {
        $this->rows = BulkIngredient::query()
            ->orderBy('name')
            ->get()
            ->map(fn (BulkIngredient $ingredient): array => [
                'id' => (int) $ingredient->getKey(),
                'name' => $ingredient->name,
                'fridge_life_days' => $ingredient->fridge_life_days === null
                    ? ''
                    : (string) $ingredient->fridge_life_days,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['rows' => ['array', 'max:60']];

        foreach ($this->rows as $index => $row) {
            $rules["rows.{$index}.name"] = [
                'required',
                'string',
                'max:120',
                Rule::unique('bulk_ingredients', 'name')->ignore($row['id'] ?? null),
                $this->notListedTwice(),
            ];

            $rules["rows.{$index}.fridge_life_days"] = [
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_FRIDGE_LIFE_DAYS,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'rows.*.name.required' => 'Name the bulk ingredient, or remove the row.',
            'rows.*.name.unique' => 'There is already a bulk ingredient with that name.',
            'rows.*.name.max' => 'Keep the name to 120 characters or fewer.',
            'rows.*.fridge_life_days.integer' => 'Give the fridge life as a whole number of days, or leave it empty.',
            'rows.*.fridge_life_days.min' => 'A raw split has to keep for at least a day.',
            'rows.*.fridge_life_days.max' => 'Two weeks in the fridge is not a raw split. Use 14 days or fewer.',
        ];
    }

    /**
     * Save every row, or none of them.
     */
    public function save(): void
    {
        $this->saved = false;
        $this->status = null;

        $this->tidyInput();
        $this->validate();

        DB::transaction(function (): void {
            foreach ($this->rows as $index => $row) {
                $ingredient = BulkIngredient::query()->findOrNew($row['id'] ?? null);

                $ingredient->fill([
                    'name' => $row['name'],
                    'fridge_life_days' => $row['fridge_life_days'] === '' ? null : (int) $row['fridge_life_days'],
                ])->save();

                $this->rows[$index]['id'] = (int) $ingredient->getKey();
            }
        });

        $this->saved = true;
    }

    /**
     * Remove a bulk ingredient.
     *
     * A plain delete, unlike a recipe or a stash item: nothing on a plan points
     * at a bulk ingredient, and the pivot rows go with it in MySQL. What the
     * household loses is the tag on the recipes that split this pack — the
     * recipes themselves are untouched, which is what the confirmation says.
     */
    public function remove(int $id): void
    {
        $ingredient = BulkIngredient::query()->find($id);

        if ($ingredient === null) {
            $this->forgetRow($id);
            $this->status = 'That bulk ingredient is already gone.';

            return;
        }

        $name = $ingredient->name;
        $ingredient->delete();

        $this->forgetRow($id);
        $this->status = $name.' is removed. The recipes that used it are untouched: they just no longer split a pack.';
    }

    /**
     * The recipes tagged with the ingredient in question, for the confirmation.
     *
     * @return array<int, string>
     */
    public function taggedRecipes(): array
    {
        if ($this->removing === null) {
            return [];
        }

        return BulkIngredient::query()
            ->find($this->removing)
            ?->recipes()
            ->orderBy('name')
            ->pluck('name')
            ->all() ?? [];
    }

    /**
     * What removing the ingredient in question would touch, in sentences.
     *
     * Removing a tag is not removing a recipe, and the household should not have
     * to guess which one this button does.
     *
     * @return array<int, string>
     */
    public function removalWarnings(): array
    {
        $recipes = $this->taggedRecipes();

        if ($recipes === []) {
            return ['No recipe is tagged with it.'];
        }

        if (count($recipes) === 1) {
            return [$recipes[0].' is tagged with it. That recipe keeps every ingredient and every other '
                .'field — only the tag goes, so the planner stops pairing it to split a pack.'];
        }

        return [implode(', ', $recipes).' are tagged with it. Those recipes keep every ingredient and '
            .'every other field — only the tag goes, so the planner stops pairing them to split a pack.'];
    }

    /**
     * What a row with an empty fridge life falls back to.
     */
    public function defaultFridgeLifeDays(): int
    {
        return HouseholdSettings::rawSplitFridgeLifeDays();
    }

    public function render(): View
    {
        return view('livewire.bulk-ingredients');
    }

    /**
     * @return array{id: null, name: string, fridge_life_days: string}
     */
    protected function blankRow(): array
    {
        return ['id' => null, 'name' => '', 'fridge_life_days' => ''];
    }

    private function tidyInput(): void
    {
        foreach ($this->rows as $index => $row) {
            $this->rows[$index]['name'] = trim((string) ($row['name'] ?? ''));
            $this->rows[$index]['fridge_life_days'] = trim((string) ($row['fridge_life_days'] ?? ''));
        }
    }

    /**
     * Two rows of the same name would both pass the `unique` rule — neither is in
     * the database yet — and then collide on the index halfway through saving.
     */
    private function notListedTwice(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $name = mb_strtolower(trim((string) $value));
            $index = (int) explode('.', $attribute)[1];

            foreach ($this->rows as $other => $row) {
                if ($other !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $name) {
                    $fail('That name is on the list twice.');

                    return;
                }
            }
        };
    }
}
