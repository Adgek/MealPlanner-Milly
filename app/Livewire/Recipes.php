<?php

namespace App\Livewire;

use App\Models\Recipe;
use App\Vault\MealUsage;
use App\Vault\Retirement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The recipe vault (MM-15): what is in it, and the way in and out.
 *
 * Editing a recipe happens on its own page (RecipeEditor), because a recipe is
 * every field in AppPlan.md's list plus two repeaters. This page is the list,
 * and the one place removing a recipe is possible — which is the part that needs
 * care, not the list.
 *
 * Removing is never a plain delete. App\Vault\Retirement retires the recipe and
 * repairs the plan; App\Vault\MealUsage says, before the household presses
 * anything, exactly what still points at it. See docs/decisions.md.
 */
#[Title('Recipes')]
class Recipes extends Component
{
    /**
     * The recipe the household is being asked about, if any. An id rather than a
     * model, so nothing is carried between requests but the question.
     */
    public ?int $removing = null;

    /**
     * What the last action did, in a sentence. Replaced by each action, and
     * seeded from the flash a redirect from the editor leaves behind.
     */
    public ?string $status = null;

    public function mount(): void
    {
        $this->status = session('status');
    }

    /**
     * Open the question: what removing this recipe would touch.
     */
    public function confirmRemove(int $recipe): void
    {
        $this->removing = $recipe;
        $this->status = null;
    }

    public function cancelRemove(): void
    {
        $this->removing = null;
    }

    /**
     * Take it out of the vault, and tell the household what that did to the plan.
     */
    public function retire(int $recipe): void
    {
        $model = Recipe::query()->find($recipe);

        if ($model === null) {
            $this->done('That recipe is already gone.');

            return;
        }

        $this->done((new Retirement)->retire($model)->whatRemovingDid());
    }

    public function bringBack(int $recipe): void
    {
        $model = Recipe::query()->find($recipe);

        if ($model === null) {
            $this->done('That recipe is already gone.');

            return;
        }

        (new Retirement)->bringBack($model);

        $this->done($model->name.' is back in the vault, and the picker can choose it again.');
    }

    /**
     * Delete the row itself, which is only possible while nothing points at it.
     *
     * Checked here as well as in the panel: a page left open while the plan moved
     * on would otherwise offer a button that quietly destroys history.
     */
    public function deleteForGood(int $recipe): void
    {
        $model = Recipe::query()->find($recipe);

        if ($model === null) {
            $this->done('That recipe is already gone.');

            return;
        }

        $name = $model->name;
        $why = MealUsage::of($model)->whyItCannotBeDeleted();

        if (! (new Retirement)->deleteForGood($model)) {
            // Nothing is written: the household asked for the destructive one,
            // and quietly doing the safe one instead would be a different answer
            // to the question they asked.
            $this->done(sprintf(
                '%s was kept. %s Take it out of the vault instead.',
                $name,
                (string) $why,
            ));

            return;
        }

        $this->done($name.' is deleted.');
    }

    /**
     * What still points at the recipe in question.
     *
     * Computed, so the panel can ask it more than once in a render without
     * repeating the queries.
     */
    #[Computed]
    public function usage(): ?MealUsage
    {
        $recipe = $this->removing === null ? null : Recipe::query()->find($this->removing);

        return $recipe === null ? null : MealUsage::of($recipe);
    }

    public function render(): View
    {
        return view('livewire.recipes', [
            'vault' => $this->recipes(retired: false),
            'retired' => $this->recipes(retired: true),
        ]);
    }

    /**
     * The vault, or what has been taken out of it, in reading order.
     *
     * @return Collection<int, Recipe>
     */
    private function recipes(bool $retired): Collection
    {
        return Recipe::query()
            ->when($retired, fn ($query) => $query->retired(), fn ($query) => $query->inTheVault())
            ->with('bulkIngredients')
            ->withCount(['ingredients', 'prepSteps'])
            ->orderBy('name')
            ->get();
    }

    private function done(string $status): void
    {
        $this->status = $status;
        $this->removing = null;
        unset($this->usage);
    }
}
