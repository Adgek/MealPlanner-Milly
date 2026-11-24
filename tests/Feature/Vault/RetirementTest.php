<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Models\FreezerItem;
use App\Models\Ingredient;
use App\Models\KitchenProject;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Models\RecipePrepStep;
use App\Planning\MealPlanner;
use App\Planning\MealPool;
use App\Vault\MealUsage;
use App\Vault\Retirement;

/*
 * Removing a meal from the vault, against the real foreign keys (MM-15).
 *
 * The migrations make deleting a recipe or a stash item quietly destructive:
 * meal_history cascades, and the plan's recipe_id and freezer_item_id null out
 * while plan_nights.holds_freezer_portion goes on claiming a reservation. These
 * tests pin down what the app does instead.
 */

/**
 * Yesterday and tomorrow on the household's clock, as plan nights use them.
 */
function aNightDate(int $daysFromToday): string
{
    return (new MealPlanner)->today()->addDays($daysFromToday)->toDateString();
}

/*
|--------------------------------------------------------------------------
| What retiring keeps
|--------------------------------------------------------------------------
*/

it('keeps every record of having cooked a recipe it retires', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    foreach ([3, 10, 17] as $daysAgo) {
        MealHistoryEntry::factory()->create([
            'recipe_id' => $chili->id,
            'cooked_on' => aNightDate(-$daysAgo),
        ]);
    }

    (new Retirement)->retire($chili);

    // The row survives, so the cascade never fires and the variety weighting
    // still has the only input it has (MM-6).
    expect(Recipe::query()->whereKey($chili->id)->exists())->toBeTrue()
        ->and($chili->fresh()->isRetired())->toBeTrue()
        ->and(MealHistoryEntry::query()->where('recipe_id', $chili->id)->count())->toBe(3);
});

it('leaves a night that has gone by exactly as it was', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $lastNight = PlanNight::factory()->for($plan)->on(aNightDate(-2))->withRecipe($chili)->create();

    (new Retirement)->retire($chili);

    $lastNight->refresh();

    expect($lastNight->recipe_id)->toBe($chili->id)
        ->and($lastNight->selection)->toBe(NightSelection::Recipe)
        ->and($lastNight->recipe->name)->toBe('Chili');
});

it('never leaves a night pointing at a dinner that is not there', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    PlanNight::factory()->for($plan)->on(aNightDate(-2))->withRecipe($chili)->create();
    PlanNight::factory()->for($plan)->on(aNightDate(1))->withRecipe($chili)->create();

    (new Retirement)->retire($chili);

    // The state MM-8, MM-9 and MM-14 would render as a blank dinner.
    $incoherent = PlanNight::query()
        ->where('selection', NightSelection::Recipe->value)
        ->whereNull('recipe_id')
        ->count();

    expect($incoherent)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What retiring changes
|--------------------------------------------------------------------------
*/

it('picks something else for a night that still had it', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $pasta = Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $tomorrow = PlanNight::factory()->for($plan)->on(aNightDate(1))->withRecipe($chili)->create();

    (new Retirement)->retire($chili);

    expect($tomorrow->fresh()->recipe_id)->toBe($pasta->id);
});

it('keeps the meal on a night the household locked', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $friday = PlanNight::factory()->for($plan)->on(aNightDate(2))->withRecipe($chili)->locked()->create();

    (new Retirement)->retire($chili);

    // A lock is the household's answer for that date, and "one last time on
    // Friday" is usually what retiring a recipe mid-week means (MM-6).
    expect($friday->fresh()->recipe_id)->toBe($chili->id)
        ->and($friday->fresh()->is_locked)->toBeTrue();
});

it('keeps the dinner, with a reason, when nothing else fits', function () {
    // An empty vault apart from the recipe being retired.
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    $plan = Plan::factory()->create();
    $tomorrow = PlanNight::factory()->for($plan)->on(aNightDate(1))->withRecipe($chili)->create();

    (new Retirement)->retire($chili);

    // MM-6's own rule: the shop was planned around this meal, so a dinner with
    // an explanation beats an empty night.
    expect($tomorrow->fresh()->recipe_id)->toBe($chili->id)
        ->and($tomorrow->fresh()->pick_note)->toBe('Kept Chili: nothing else fits.');
});

it('hands a held freezer portion back when it retires the stash item', function () {
    $planner = new MealPlanner;

    $soup = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $night = PlanNight::factory()->for($plan)->on(aNightDate(1))->create();

    // Planned through the planner, so the portion is really reserved.
    $planner->chooseMeal($night, $soup);

    expect($soup->fresh()->portions_left)->toBe(1)
        ->and($night->fresh()->holdsFreezerPortion())->toBeTrue();

    (new Retirement)->retire($soup);

    // The portions are still in the freezer; they are just not planned around.
    expect($soup->fresh()->portions_left)->toBe(2)
        ->and($soup->fresh()->isRetired())->toBeTrue()
        ->and($night->fresh()->holdsFreezerPortion())->toBeFalse()
        ->and($night->fresh()->freezer_item_id)->toBeNull();
});

it('leaves a portion eaten on a night that has gone by out of the stash', function () {
    $soup = FreezerItem::factory()->portions(1)->create();

    $plan = Plan::factory()->create();
    PlanNight::factory()->for($plan)->on(aNightDate(-3))->withFreezerItem($soup)->create([
        'holds_freezer_portion' => true,
    ]);

    (new Retirement)->retire($soup);

    // That portion was eaten (MM-6's date fence), so it does not come back.
    expect($soup->fresh()->portions_left)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What the picker sees afterwards
|--------------------------------------------------------------------------
*/

it('takes a retired recipe out of the pool the picker draws from', function () {
    $kept = Recipe::factory()->create();
    Recipe::factory()->retired()->create();

    expect(MealPool::fromVault()->recipes())->toHaveCount(1)
        ->and(MealPool::fromVault()->recipes()[0]->id)->toBe($kept->id);
});

it('takes a retired freezer meal out of the stash the picker draws from', function () {
    $kept = FreezerItem::factory()->portions(3)->create();
    FreezerItem::factory()->portions(3)->retired()->create();

    expect(MealPool::fromVault()->freezerMeals())->toHaveCount(1)
        ->and(MealPool::fromVault()->freezerMeals()[0]->id)->toBe($kept->id);
});

it('never plans a retired recipe, even when it is the only one left', function () {
    theWeekIs();

    Recipe::factory()->retired()->create();

    $plan = (new MealPlanner)->planWindow();

    expect($plan->nights->pluck('selection')->unique()->all())
        ->toBe([NightSelection::NothingFits]);
});

it('brings a retired recipe back into the vault', function () {
    $chili = Recipe::factory()->retired()->create();

    expect(MealPool::fromVault()->recipes())->toHaveCount(0);

    (new Retirement)->bringBack($chili);

    expect($chili->fresh()->isRetired())->toBeFalse()
        ->and(MealPool::fromVault()->recipes())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Deleting for good
|--------------------------------------------------------------------------
*/

it('deletes a recipe nothing points at, and its ingredients and prep steps with it', function () {
    $recipe = Recipe::factory()->withIngredients(3)->withPrepStep()->withBulkIngredient()->create();

    expect((new Retirement)->deleteForGood($recipe))->toBeTrue()
        ->and(Recipe::query()->whereKey($recipe->id)->exists())->toBeFalse()
        // Ingredients are polymorphic, so nothing in MySQL cleans them up: the
        // model event on Recipe has to have fired (MM-3).
        ->and(Ingredient::query()->where('ingredientable_id', $recipe->id)->count())->toBe(0)
        ->and(RecipePrepStep::query()->where('recipe_id', $recipe->id)->count())->toBe(0)
        ->and($recipe->bulkIngredients()->count())->toBe(0);
});

it('refuses to delete a recipe the household has cooked', function () {
    $chili = Recipe::factory()->create();
    MealHistoryEntry::factory()->create(['recipe_id' => $chili->id, 'cooked_on' => aNightDate(-4)]);

    expect((new Retirement)->deleteForGood($chili))->toBeFalse()
        ->and(Recipe::query()->whereKey($chili->id)->exists())->toBeTrue()
        ->and(MealHistoryEntry::query()->where('recipe_id', $chili->id)->count())->toBe(1);
});

it('refuses to delete a recipe a plan still points at', function () {
    $chili = Recipe::factory()->create();
    PlanNight::factory()->for(Plan::factory())->on(aNightDate(1))->withRecipe($chili)->create();

    expect((new Retirement)->deleteForGood($chili))->toBeFalse()
        ->and(Recipe::query()->whereKey($chili->id)->exists())->toBeTrue();
});

it('refuses to delete a stash item a project restocks', function () {
    $soup = FreezerItem::factory()->create();
    KitchenProject::factory()->producing($soup)->create();

    expect((new Retirement)->deleteForGood($soup))->toBeFalse()
        ->and(FreezerItem::query()->whereKey($soup->id)->exists())->toBeTrue();
});

it('deletes a stash item nothing points at', function () {
    $soup = FreezerItem::factory()->create();

    expect((new Retirement)->deleteForGood($soup))->toBeTrue()
        ->and(FreezerItem::query()->whereKey($soup->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What the household is told
|--------------------------------------------------------------------------
*/

it('reads what points at a recipe out of the database', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    MealHistoryEntry::factory()->create(['recipe_id' => $chili->id, 'cooked_on' => aNightDate(-9)]);

    $plan = Plan::factory()->create();
    PlanNight::factory()->for($plan)->on(aNightDate(-2))->withRecipe($chili)->create();
    PlanNight::factory()->for($plan)->on(aNightDate(1))->withRecipe($chili)->create();
    PlanNight::factory()->for($plan)->on(aNightDate(2))->withRecipe($chili)->locked()->create();

    $usage = MealUsage::of($chili);

    expect($usage->name)->toBe('Chili')
        ->and($usage->nights)->toHaveCount(3)
        ->and($usage->timesCooked)->toBe(1)
        ->and($usage->pastNights())->toHaveCount(1)
        ->and($usage->lockedNights())->toHaveCount(1)
        ->and($usage->nightIdsToRepick())->toHaveCount(1)
        ->and($usage->canBeDeletedForGood())->toBeFalse();
});

it('reads the stash item’s portions and the projects that restock it', function () {
    $soup = FreezerItem::factory()->portions(4)->create(['name' => 'Frozen minestrone']);
    KitchenProject::factory()->producing($soup)->create(['name' => 'Big batch minestrone']);

    $usage = MealUsage::of($soup);

    expect($usage->portionsLeft)->toBe(4)
        ->and($usage->restockedBy)->toBe(['Big batch minestrone'])
        ->and($usage->warnings())->toHaveCount(2);
});
