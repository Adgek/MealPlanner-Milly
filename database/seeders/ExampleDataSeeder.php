<?php

namespace Database\Seeders;

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Models\BulkIngredient;
use App\Models\FreezerItem;
use App\Models\KidBackupFood;
use App\Models\KitchenProject;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

/**
 * A small dataset built from the examples in AppPlan.md: Beef Bulgogi, White Boy
 * Tacos, Chili, Quick Pasta, Pupusas and a frozen soup.
 *
 * It exists so the picker, the pages and the Slack posts have something real
 * enough to work against before MM-5 loads the household's own recipes. It is not
 * the household's data and is not meant to be accurate — the amounts are
 * plausible, not measured.
 *
 * Deliberately shaped to exercise the rules rather than just fill tables:
 *
 *   - Bulgogi, tacos and chili all share the ground beef bulk pack, so there is
 *     something for the raw split in MM-11 to pair.
 *   - Pasta and pupusas are 15-minute and tagged for my partner, so her solo
 *     nights have something to draw from.
 *   - Chili has prep that happens the night before, for the upstream nudge in MM-13.
 *   - The soup is in the freezer and has a project that restocks it, which is the
 *     loop MM-12 closes.
 *
 * Idempotent: everything is keyed on its name, so running it twice is a no-op.
 */
class ExampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $groundBeef = BulkIngredient::query()->firstOrCreate(
            ['name' => 'Ground beef'],
            ['fridge_life_days' => 2],
        );

        $tortillas = BulkIngredient::query()->firstOrCreate(
            ['name' => 'Tortillas'],
            // No fridge life of its own: falls back to the Settings default.
            ['fridge_life_days' => null],
        );

        $this->bulgogi($groundBeef);
        $this->tacos($groundBeef, $tortillas);
        $this->chili($groundBeef);
        $this->quickPasta();
        $this->pupusas();
        $this->frozenSoup();
        $this->kidBackupFoods();
    }

    private function bulgogi(BulkIngredient $groundBeef): void
    {
        $recipe = $this->recipe(
            name: 'Beef Bulgogi',
            cookableBy: CookableBy::Both,
            effort: EffortTier::Workhorse30,
            kidOffRamp: 'Pull some plain beef and rice out before the sauce goes on.',
        );

        $recipe->bulkIngredients()->syncWithoutDetaching($groundBeef);

        $this->ingredients($recipe, [
            ['Ground beef', 1, 'lb', 'Meat'],
            ['Soy sauce', 60, 'ml', 'Pantry', true],
            ['Brown sugar', 2, 'tbsp', 'Pantry', true],
            ['Sesame oil', 1, 'tbsp', 'Pantry', true],
            ['Garlic', 3, 'clove', 'Produce'],
            ['Green onion', 1, 'bunch', 'Produce'],
            ['Rice', 2, 'cup', 'Dry goods', true],
        ]);

        $this->prepSteps($recipe, [
            ['Marinate the beef', 2],
        ]);
    }

    private function tacos(BulkIngredient $groundBeef, BulkIngredient $tortillas): void
    {
        $recipe = $this->recipe(
            name: 'White Boy Tacos',
            cookableBy: CookableBy::Both,
            effort: EffortTier::Workhorse30,
            kidOffRamp: 'Deconstructed: plain beef, cheese and a tortilla, nothing touching.',
        );

        $recipe->bulkIngredients()->syncWithoutDetaching([$groundBeef->id, $tortillas->id]);

        $this->ingredients($recipe, [
            ['Ground beef', 1, 'lb', 'Meat'],
            ['Tortillas', 8, 'count', 'Dry goods'],
            ['Cheddar', 200, 'g', 'Dairy'],
            ['Lettuce', 1, 'head', 'Produce'],
            ['Sour cream', 1, 'tub', 'Dairy'],
            ['Taco seasoning', 1, 'packet', 'Pantry', true],
        ]);
    }

    private function chili(BulkIngredient $groundBeef): void
    {
        $recipe = $this->recipe(
            name: 'Chili',
            cookableBy: CookableBy::Me,
            effort: EffortTier::Project60,
            kidOffRamp: 'Keep a bowl back before the chili powder; serve with cheese on top.',
        );

        $recipe->bulkIngredients()->syncWithoutDetaching($groundBeef);

        $this->ingredients($recipe, [
            ['Ground beef', 1, 'lb', 'Meat'],
            ['Dried kidney beans', 400, 'g', 'Dry goods'],
            ['Crushed tomatoes', 2, 'can', 'Pantry', true],
            ['Onion', 2, 'count', 'Produce'],
            ['Chili powder', 2, 'tbsp', 'Pantry', true],
            ['Cumin', 1, 'tbsp', 'Pantry', true],
        ]);

        $this->prepSteps($recipe, [
            // The example from AppPlan.md: soaking beans before Wednesday's chili.
            ['Soak the beans', 24],
            ['Dice the onions', 0],
        ]);
    }

    private function quickPasta(): void
    {
        $recipe = $this->recipe(
            name: 'Quick Pasta',
            cookableBy: CookableBy::Partner,
            effort: EffortTier::Chaos15,
            kidOffRamp: 'Plain buttered noodles set aside before the sauce.',
        );

        $this->ingredients($recipe, [
            ['Pasta', 500, 'g', 'Dry goods', true],
            ['Passata', 1, 'jar', 'Pantry', true],
            ['Parmesan', 100, 'g', 'Dairy'],
            ['Garlic', 2, 'clove', 'Produce'],
            ['Olive oil', 2, 'tbsp', 'Pantry', true],
        ]);
    }

    private function pupusas(): void
    {
        $recipe = $this->recipe(
            name: 'Pupusas',
            cookableBy: CookableBy::Partner,
            effort: EffortTier::Chaos15,
            kidOffRamp: 'Cheese only, no beans, cut into strips.',
        );

        $this->ingredients($recipe, [
            ['Masa harina', 500, 'g', 'Dry goods', true],
            ['Mozzarella', 250, 'g', 'Dairy'],
            ['Refried beans', 1, 'can', 'Pantry', true],
            ['Curtido', 1, 'jar', 'Pantry'],
        ]);
    }

    /**
     * The 80%-prepped freezer meal, plus the project that restocks it.
     */
    private function frozenSoup(): void
    {
        $soup = FreezerItem::query()->firstOrCreate(
            ['name' => 'Frozen minestrone'],
            [
                'portions_left' => 4,
                // Out of the freezer the night before.
                'thaw_lead_time_hours' => 24,
                'kid_off_ramp' => 'Strain a portion so it is mostly pasta and broth.',
            ],
        );

        $project = KitchenProject::query()->firstOrCreate(
            ['name' => 'Big batch minestrone'],
            [
                'every_days' => 21,
                'produces_freezer_item_id' => $soup->id,
                'produces_portions' => 6,
                'last_done_on' => null,
            ],
        );

        $this->ingredients($project, [
            ['Carrots', 500, 'g', 'Produce'],
            ['Celery', 1, 'bunch', 'Produce'],
            ['Cannellini beans', 2, 'can', 'Pantry', true],
            ['Vegetable stock', 2, 'l', 'Pantry', true],
            ['Small pasta', 200, 'g', 'Dry goods', true],
        ]);

        // A second project that stocks the fridge rather than the freezer.
        $hummus = KitchenProject::query()->firstOrCreate(
            ['name' => 'Fresh hummus'],
            [
                'every_days' => 7,
                'produces_freezer_item_id' => null,
                'produces_portions' => null,
                'last_done_on' => null,
            ],
        );

        $this->ingredients($hummus, [
            ['Chickpeas', 2, 'can', 'Pantry', true],
            ['Tahini', 3, 'tbsp', 'Pantry', true],
            ['Lemon', 1, 'count', 'Produce'],
        ]);
    }

    private function kidBackupFoods(): void
    {
        $foods = [
            ['Frozen cheese pizza', 2, 'count'],
            ['Chicken nuggets', 1, 'bag'],
            ['Bread', 1, 'loaf'],
            ['Yogurt tubes', 1, 'box'],
            ['Apple sauce', 1, 'box'],
        ];

        foreach ($foods as [$name, $quantity, $unit]) {
            KidBackupFood::query()->firstOrCreate(
                ['name' => $name],
                ['quantity' => $quantity, 'unit' => $unit],
            );
        }
    }

    private function recipe(
        string $name,
        CookableBy $cookableBy,
        EffortTier $effort,
        string $kidOffRamp,
    ): Recipe {
        return Recipe::query()->firstOrCreate(
            ['name' => $name],
            [
                'cookable_by' => $cookableBy,
                'effort_tier' => $effort,
                'kid_off_ramp' => $kidOffRamp,
            ],
        );
    }

    /**
     * @param  array<int, array{0: string, 1: float|int, 2: string, 3: string, 4?: bool}>  $rows
     */
    private function ingredients(Recipe|KitchenProject $owner, array $rows): void
    {
        foreach ($rows as $row) {
            [$name, $quantity, $unit, $section] = $row;

            $owner->ingredients()->firstOrCreate(
                ['name' => $name],
                [
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'store_section' => $section,
                    'pantry_basic' => $row[4] ?? false,
                ],
            );
        }
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $steps
     */
    private function prepSteps(Recipe $recipe, array $steps): void
    {
        foreach ($steps as $position => [$description, $leadTimeHours]) {
            $recipe->prepSteps()->firstOrCreate(
                ['description' => $description],
                [
                    'lead_time_hours' => $leadTimeHours,
                    'position' => $position,
                ],
            );
        }
    }
}
