<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\BulkIngredient;
use App\Models\CookScheduleNight;
use App\Models\FreezerItem;
use App\Models\Ingredient;
use App\Models\KidBackupFood;
use App\Models\KitchenProject;
use App\Models\Recipe;
use App\Models\Setting;
use App\Settings\HouseholdSettings;
use Database\Seeders\ExampleDataSeeder;
use Database\Seeders\HouseholdDefaultsSeeder;

/*
 * The starting settings have to be present on a fresh database — the container
 * entrypoint seeds them straight after migrating — and seeding again on the next
 * boot must not trample a value the household has changed since.
 */

it('installs every starting setting with the documented default', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    expect(Setting::count())->toBe(count(HouseholdSettings::defaults()));

    foreach (HouseholdSettings::defaults() as $key => $value) {
        expect(Setting::read($key))->toEqual($value);
    }
});

it('installs the starting weekly cook schedule', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    $week = CookScheduleNight::week();

    // "Every night: together, 30 min (until the household sets its week)."
    expect($week)->toHaveCount(7)
        ->and($week->keys()->all())->toBe([
            'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        ]);

    foreach ($week as $night) {
        expect($night->cook)->toBe(CookAssignment::Together)
            ->and($night->time_budget)->toBe(EffortTier::Workhorse30);
    }
});

it('does not reset a setting the household has changed', function () {
    $this->seed(HouseholdDefaultsSeeder::class);
    HouseholdSettings::set(HouseholdSettings::KEY_DINNER_TIME, '17:00');

    // The entrypoint runs this on every container boot.
    $this->seed(HouseholdDefaultsSeeder::class);

    expect(HouseholdSettings::dinnerTime())->toBe('17:00')
        ->and(Setting::count())->toBe(count(HouseholdSettings::defaults()));
});

it('does not reset a cook schedule the household has changed', function () {
    $this->seed(HouseholdDefaultsSeeder::class);
    CookScheduleNight::query()->where('weekday', 'tuesday')->update([
        'cook' => CookAssignment::Partner->value,
        'time_budget' => EffortTier::Chaos15->value,
    ]);

    $this->seed(HouseholdDefaultsSeeder::class);

    $tuesday = CookScheduleNight::forWeekday(Weekday::Tuesday);

    expect($tuesday->cook)->toBe(CookAssignment::Partner)
        ->and($tuesday->time_budget)->toBe(EffortTier::Chaos15)
        ->and(CookScheduleNight::count())->toBe(7);
});

it('seeds the example meals from the plan', function () {
    $this->seed(ExampleDataSeeder::class);

    expect(Recipe::pluck('name')->sort()->values()->all())->toBe([
        'Beef Bulgogi', 'Chili', 'Pupusas', 'Quick Pasta', 'White Boy Tacos',
    ]);

    expect(FreezerItem::pluck('name')->all())->toBe(['Frozen minestrone']);
});

it('gives the example recipes everything the picker needs', function () {
    $this->seed(ExampleDataSeeder::class);

    $recipes = Recipe::with(['ingredients', 'prepSteps', 'bulkIngredients'])->get();

    foreach ($recipes as $recipe) {
        expect($recipe->ingredients)->not->toBeEmpty("{$recipe->name} has no ingredients")
            ->and($recipe->kid_off_ramp)->not->toBeEmpty("{$recipe->name} has no kid off-ramp");
    }
});

it('gives my partner something to cook on a solo night', function () {
    $this->seed(ExampleDataSeeder::class);

    // Her solo nights draw on 15-minute recipes tagged partner or both.
    $hers = Recipe::query()
        ->whereIn('cookable_by', [CookableBy::Partner->value, CookableBy::Both->value])
        ->where('effort_tier', EffortTier::Chaos15->value)
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($hers)->toBe(['Pupusas', 'Quick Pasta']);
});

it('seeds three meals that share the ground beef pack', function () {
    $this->seed(ExampleDataSeeder::class);

    $groundBeef = BulkIngredient::firstWhere('name', 'Ground beef');

    // Something for the raw split in MM-11 to actually pair.
    expect($groundBeef->recipes->pluck('name')->sort()->values()->all())
        ->toBe(['Beef Bulgogi', 'Chili', 'White Boy Tacos'])
        ->and($groundBeef->fridgeLifeDays())->toBe(2);
});

it('seeds a bulk ingredient that leans on the Settings default', function () {
    $this->seed(ExampleDataSeeder::class);

    $tortillas = BulkIngredient::firstWhere('name', 'Tortillas');

    expect($tortillas->fridge_life_days)->toBeNull()
        ->and($tortillas->fridgeLifeDays())->toBe(2);
});

it('seeds the beans-soaking prep the night before chili', function () {
    $this->seed(ExampleDataSeeder::class);

    $chili = Recipe::firstWhere('name', 'Chili');
    $soak = $chili->prepSteps->firstWhere('description', 'Soak the beans');

    expect($soak->lead_time_hours)->toBe(24)
        ->and($soak->isAheadOfTheDay())->toBeTrue()
        // Ordered first, ahead of the same-day prep.
        ->and($chili->prepSteps->first()->description)->toBe('Soak the beans');
});

it('seeds a freezer meal with a project that restocks it', function () {
    $this->seed(ExampleDataSeeder::class);

    $soup = FreezerItem::firstWhere('name', 'Frozen minestrone');
    $project = KitchenProject::firstWhere('name', 'Big batch minestrone');

    expect($soup->portions_left)->toBe(4)
        ->and($soup->thaw_lead_time_hours)->toBe(24)
        ->and($project->producesFreezerItem->is($soup))->toBeTrue()
        ->and($project->produces_portions)->toBe(6)
        ->and($project->ingredients)->not->toBeEmpty();
});

it('seeds the kid backup foods', function () {
    $this->seed(ExampleDataSeeder::class);

    expect(KidBackupFood::count())->toBe(5)
        ->and(KidBackupFood::pluck('name')->all())->toContain('Chicken nuggets');
});

it('can be seeded twice without duplicating anything', function () {
    // MM-5 and the entrypoint both re-run seeders; this must be a no-op.
    $this->seed(ExampleDataSeeder::class);
    $counts = [
        'recipes' => Recipe::count(),
        'ingredients' => Ingredient::count(),
        'bulk' => BulkIngredient::count(),
        'freezer' => FreezerItem::count(),
        'projects' => KitchenProject::count(),
        'kid_foods' => KidBackupFood::count(),
    ];

    $this->seed(ExampleDataSeeder::class);

    expect(Recipe::count())->toBe($counts['recipes'])
        ->and(Ingredient::count())->toBe($counts['ingredients'])
        ->and(BulkIngredient::count())->toBe($counts['bulk'])
        ->and(FreezerItem::count())->toBe($counts['freezer'])
        ->and(KitchenProject::count())->toBe($counts['projects'])
        ->and(KidBackupFood::count())->toBe($counts['kid_foods']);
});

it('seeds both the defaults and the examples from the default seeder', function () {
    $this->seed();

    expect(Setting::count())->toBe(count(HouseholdSettings::defaults()))
        ->and(CookScheduleNight::count())->toBe(7)
        ->and(Recipe::count())->toBe(5);
});
