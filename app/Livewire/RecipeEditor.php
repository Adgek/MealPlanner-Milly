<?php

namespace App\Livewire;

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Livewire\Concerns\EditsIngredients;
use App\Models\BulkIngredient;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One recipe, with every field AppPlan.md's recipe vault lists (MM-15):
 * who can cook it, how much effort it is, which bulk packs it splits, how to
 * adapt it for the kids, what to buy for it, and what has to happen ahead of
 * time.
 *
 * Its own page rather than a row in a list, because it is two repeaters and six
 * fields — on a phone that is a screen, not a line.
 *
 * The form follows MM-4's settings page: validation refuses the whole save, and
 * the writes (the recipe, the bulk tags, the ingredients, the prep steps) go in
 * one transaction, so there is no half-saved recipe to puzzle over later.
 */
#[Title('Recipe')]
class RecipeEditor extends Component
{
    use EditsIngredients;

    /**
     * Which recipe this is, or null while it is being added.
     */
    public ?int $recipeId = null;

    public string $name = '';

    public string $cookableBy = '';

    public string $effortTier = '';

    public string $kidOffRamp = '';

    /**
     * The bulk packs this recipe draws on, as ids. Strings on the way in, which
     * is what a checkbox posts.
     *
     * @var array<int, int|string>
     */
    public array $bulkIngredientIds = [];

    /**
     * Upstream prep, in the order it happens.
     *
     * @var array<int, array{description: string, lead_time_hours: string}>
     */
    public array $prepSteps = [];

    /**
     * Whether this recipe is out of the vault, so the page can say so.
     */
    public bool $retired = false;

    /**
     * How long a prep step may be scheduled ahead of dinner: a week. Long enough
     * for a sourdough starter, short enough that 2400 is a typo rather than a
     * reminder that fires next month.
     */
    private const MAX_LEAD_TIME_HOURS = 168;

    /**
     * Fill the form from the recipe, or offer an empty one.
     *
     * The starting values for a new recipe are the middle of each scale — anyone
     * can cook it, half an hour — so the common case is already right.
     */
    public function mount(?Recipe $recipe = null): void
    {
        if ($recipe === null || ! $recipe->exists) {
            $this->cookableBy = CookableBy::Both->value;
            $this->effortTier = EffortTier::Workhorse30->value;
            $this->ingredients = [$this->blankIngredient()];

            return;
        }

        $recipe->load(['ingredients', 'prepSteps', 'bulkIngredients']);

        $this->recipeId = (int) $recipe->getKey();
        $this->name = $recipe->name;
        $this->cookableBy = $recipe->cookable_by->value;
        $this->effortTier = $recipe->effort_tier->value;
        $this->kidOffRamp = (string) $recipe->kid_off_ramp;
        $this->retired = $recipe->isRetired();
        $this->bulkIngredientIds = $recipe->bulkIngredients->modelKeys();

        $this->fillIngredientsFrom($recipe);

        $this->prepSteps = $recipe->prepSteps
            ->map(fn ($step): array => [
                'description' => $step->description,
                'lead_time_hours' => (string) $step->lead_time_hours,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            // Unique, because the import in MM-5 keys on the name and two
            // "Chili"s on the plan page would be unreadable anyway.
            'name' => ['required', 'string', 'max:120', Rule::unique('recipes', 'name')->ignore($this->recipeId)],

            'cookableBy' => ['required', Rule::in(array_column(CookableBy::cases(), 'value'))],
            'effortTier' => ['required', Rule::in(array_column(EffortTier::cases(), 'value'))],
            'kidOffRamp' => ['nullable', 'string', 'max:1000'],

            'bulkIngredientIds' => ['array'],
            'bulkIngredientIds.*' => [Rule::exists('bulk_ingredients', 'id')],

            ...$this->ingredientRules(),

            'prepSteps' => ['array', 'max:20'],
            'prepSteps.*.description' => ['required', 'string', 'max:255'],
            'prepSteps.*.lead_time_hours' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LEAD_TIME_HOURS],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Give the recipe a name.',
            'name.unique' => 'There is already a recipe with that name. Open that one instead, or rename this.',
            'name.max' => 'Keep the name to 120 characters or fewer.',

            'cookableBy.*' => 'Say who is willing to cook it.',
            'effortTier.*' => 'Say how much cooking it is.',
            'kidOffRamp.max' => 'Keep the kid off-ramp to 1000 characters or fewer.',

            'bulkIngredientIds.*.exists' => 'One of those bulk ingredients no longer exists. Untick it and save again.',

            ...$this->ingredientMessages(),

            'prepSteps.max' => 'That is more prep steps than one recipe needs.',
            'prepSteps.*.description.required' => 'Say what the prep step is, or remove the row.',
            'prepSteps.*.description.max' => 'Keep a prep step to 255 characters or fewer.',
            'prepSteps.*.lead_time_hours.required' => 'Say how many hours before dinner it happens. Use 0 for the night of.',
            'prepSteps.*.lead_time_hours.integer' => 'Give the lead time as a whole number of hours.',
            'prepSteps.*.lead_time_hours.min' => 'A lead time cannot be negative.',
            'prepSteps.*.lead_time_hours.max' => 'A week ahead is as far as a prep reminder goes. Use 168 hours or fewer.',
        ];
    }

    /**
     * Save the recipe, its tags, its ingredients and its prep steps, or none of
     * them.
     */
    public function save(): void
    {
        $this->tidyInput();
        $this->validate();

        $recipe = DB::transaction(function (): Recipe {
            $recipe = Recipe::query()->findOrNew($this->recipeId);

            $recipe->fill([
                'name' => $this->name,
                'cookable_by' => $this->cookableBy,
                'effort_tier' => $this->effortTier,
                'kid_off_ramp' => $this->kidOffRamp === '' ? null : $this->kidOffRamp,
            ])->save();

            $recipe->bulkIngredients()->sync(array_map('intval', $this->bulkIngredientIds));

            $this->saveIngredientsFor($recipe);
            $this->savePrepSteps($recipe);

            return $recipe;
        });

        session()->flash('status', $recipe->name.' is saved.');

        $this->redirect(route('recipes'), navigate: true);
    }

    public function addPrepStep(): void
    {
        $this->prepSteps[] = ['description' => '', 'lead_time_hours' => '0'];
    }

    public function removePrepStep(int $index): void
    {
        unset($this->prepSteps[$index]);

        $this->prepSteps = array_values($this->prepSteps);

        $this->resetValidation();
    }

    /**
     * @return array<string, string>
     */
    public function cookableByOptions(): array
    {
        $options = [];

        foreach (CookableBy::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function effortTierOptions(): array
    {
        $options = [];

        foreach (EffortTier::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.recipe-editor', [
            'bulkIngredients' => BulkIngredient::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Rewrite the prep steps, numbering them by the order they are shown in.
     *
     * Prep is a sequence ("soak the beans", then "dice the onions"), and the
     * order on screen is the only order the household has expressed.
     */
    private function savePrepSteps(Recipe $recipe): void
    {
        $recipe->prepSteps()->delete();

        foreach (array_values($this->prepSteps) as $position => $step) {
            $recipe->prepSteps()->create([
                'description' => $step['description'],
                'lead_time_hours' => (int) $step['lead_time_hours'],
                'position' => $position,
            ]);
        }
    }

    /**
     * Trim everything, and drop the rows nobody typed in.
     *
     * A row where every box is empty is not a validation error, it is a row the
     * household added and then thought better of — including the blank one a new
     * recipe starts with.
     */
    private function tidyInput(): void
    {
        $this->name = trim($this->name);
        $this->kidOffRamp = trim($this->kidOffRamp);

        $this->tidyIngredients();

        $this->prepSteps = array_values(array_filter(
            array_map(fn (array $row): array => [
                'description' => trim((string) ($row['description'] ?? '')),
                'lead_time_hours' => trim((string) ($row['lead_time_hours'] ?? '')),
            ], $this->prepSteps),
            fn (array $row): bool => $row['description'] !== '' || ! in_array($row['lead_time_hours'], ['', '0'], true),
        ));
    }
}
