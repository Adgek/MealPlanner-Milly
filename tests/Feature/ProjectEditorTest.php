<?php

use App\Livewire\ProjectEditor;
use App\Models\FreezerItem;
use App\Models\KitchenProject;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The kitchen-project editor (MM-15).
 *
 * Two things here are easy to get quietly wrong. A yield with nothing to put it
 * in is a number nothing reads, so MM-12 would top up the freezer by nothing;
 * and "done today" has to mean today in the kitchen, not on the server, or an
 * evening in Toronto is refused as being in the future.
 */

/**
 * @return array<string, mixed>
 */
function aFilledInProject(): array
{
    return [
        'name' => 'Big batch minestrone',
        'everyDays' => '21',
        'producesPortions' => '6',
        'lastDoneOn' => '',
        'ingredients' => [
            ['name' => 'Carrots', 'quantity' => '500', 'unit' => 'g', 'store_section' => 'Produce', 'pantry_basic' => false],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aProjectForm(array $overrides = [], ?KitchenProject $project = null): Testable
{
    $component = $project === null
        ? Livewire::test(ProjectEditor::class)
        : Livewire::test(ProjectEditor::class, ['project' => $project]);

    $component->set(aFilledInProject());

    foreach ($overrides as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

it('opens a new project on a weekly cadence', function () {
    Livewire::test(ProjectEditor::class)
        ->assertOk()
        ->assertSet('projectId', null)
        ->assertSet('everyDays', '7')
        ->assertSet('producesFreezerItemId', '')
        ->assertCount('ingredients', 1)
        ->assertSee('Add a kitchen project');
});

it('opens with the project the household has', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    $project = KitchenProject::factory()->producing($soup, 6)->withIngredients(2)->create([
        'name' => 'Big batch minestrone',
        'every_days' => 21,
        'last_done_on' => '2026-01-04',
    ]);

    Livewire::test(ProjectEditor::class, ['project' => $project])
        ->assertOk()
        ->assertSet('projectId', $project->id)
        ->assertSet('name', 'Big batch minestrone')
        ->assertSet('everyDays', '21')
        ->assertSet('producesFreezerItemId', (string) $soup->id)
        ->assertSet('producesPortions', '6')
        ->assertSet('lastDoneOn', '2026-01-04')
        ->assertCount('ingredients', 2);
});

it('offers the stash to top up, and the option of stocking the fridge instead', function () {
    FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    FreezerItem::factory()->retired()->create(['name' => 'Frozen lasagne']);

    Livewire::test(ProjectEditor::class)
        ->assertSee('Nothing — it stocks the fridge')
        ->assertSee('Frozen minestrone')
        // A retired item is not offered: it is out of the stash.
        ->assertDontSee('Frozen lasagne');
});

it('saves a project that fills the freezer', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);

    aProjectForm(['producesFreezerItemId' => (string) $soup->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('kitchen'));

    $project = KitchenProject::query()->firstWhere('name', 'Big batch minestrone');

    expect($project->every_days)->toBe(21)
        ->and($project->produces_freezer_item_id)->toBe($soup->id)
        ->and($project->produces_portions)->toBe(6)
        ->and($project->last_done_on)->toBeNull()
        ->and($project->ingredients->pluck('name')->all())->toBe(['Carrots'])
        ->and($project->ingredients->first()->quantity)->toBe(500.0);
});

it('saves a project that stocks the fridge, with no stray yield', function () {
    aProjectForm(['producesFreezerItemId' => '', 'producesPortions' => '6'])
        ->call('save')
        ->assertHasNoErrors();

    $project = KitchenProject::query()->firstWhere('name', 'Big batch minestrone');

    // Portions with nothing to put them in would be a number nothing reads.
    expect($project->produces_freezer_item_id)->toBeNull()
        ->and($project->produces_portions)->toBeNull();
});

it('makes a project with no last-done date due now', function () {
    aProjectForm(['lastDoneOn' => ''])->call('save')->assertHasNoErrors();

    $project = KitchenProject::query()->firstWhere('name', 'Big batch minestrone');

    expect($project->last_done_on)->toBeNull()
        ->and($project->dueOn()->toDateString())->toBe(Carbon::today()->toDateString());
});

it('records when it was last done, and when that makes it due again', function () {
    aProjectForm(['lastDoneOn' => '2026-01-04', 'everyDays' => '21'])
        ->call('save')
        ->assertHasNoErrors();

    $project = KitchenProject::query()->firstWhere('name', 'Big batch minestrone');

    expect($project->last_done_on->toDateString())->toBe('2026-01-04')
        ->and($project->dueOn()->toDateString())->toBe('2026-01-25');
});

it('rewrites the ingredients of a project it edits', function () {
    $project = KitchenProject::factory()->withIngredients(3)->create(['name' => 'Fresh hummus']);

    Livewire::test(ProjectEditor::class, ['project' => $project])
        ->set('ingredients', [
            ['name' => 'Chickpeas', 'quantity' => '2', 'unit' => 'can', 'store_section' => 'Pantry', 'pantry_basic' => true],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh()->ingredients->pluck('name')->all())->toBe(['Chickpeas'])
        ->and($project->fresh()->ingredients->first()->pantry_basic)->toBeTrue();
});

it('says what it saved on the page it goes back to', function () {
    aProjectForm()->call('save')->assertRedirect(route('kitchen'));

    $this->get(route('kitchen'))->assertOk()->assertSee('Big batch minestrone is saved.');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a project with no name', function () {
    aProjectForm(['name' => ' '])
        ->call('save')
        ->assertHasErrors('name')
        ->assertSee('Give the project a name.');

    expect(KitchenProject::query()->count())->toBe(0);
});

it('refuses a name another project already has', function () {
    KitchenProject::factory()->create(['name' => 'Big batch minestrone']);

    aProjectForm()
        ->call('save')
        ->assertHasErrors('name')
        ->assertSee('There is already a kitchen project with that name.');

    expect(KitchenProject::query()->count())->toBe(1);
});

it('refuses a cadence that is not a sane number of days', function (string $value, string $message) {
    aProjectForm(['everyDays' => $value])
        ->call('save')
        ->assertHasErrors('everyDays')
        ->assertSee($message);
})->with([
    'empty' => ['', 'Say how often it comes round, in days.'],
    'zero' => ['0', 'A project has to come round at least a day apart.'],
    'words' => ['weekly', 'Give the cadence as a whole number of days.'],
    'a decade' => ['4000', 'A year is as far ahead as a project is scheduled.'],
]);

it('refuses a yield that would top the freezer up by nothing', function () {
    $soup = FreezerItem::factory()->create();

    aProjectForm(['producesFreezerItemId' => (string) $soup->id, 'producesPortions' => ''])
        ->call('save')
        ->assertHasErrors('producesPortions')
        ->assertSee('Say how many portions one run makes.');
});

it('refuses a yield of nothing at all', function () {
    $soup = FreezerItem::factory()->create();

    aProjectForm(['producesFreezerItemId' => (string) $soup->id, 'producesPortions' => '0'])
        ->call('save')
        ->assertHasErrors('producesPortions')
        ->assertSee('A run that makes no portions is not stocking the freezer.');
});

it('refuses a freezer item that no longer exists', function () {
    aProjectForm(['producesFreezerItemId' => '999', 'producesPortions' => '4'])
        ->call('save')
        ->assertHasErrors('producesFreezerItemId')
        ->assertSee('That freezer item no longer exists.');
});

it('refuses a project done in the future', function () {
    $tomorrow = (new MealPlanner)->today()->addDay()->toDateString();

    aProjectForm(['lastDoneOn' => $tomorrow])
        ->call('save')
        ->assertHasErrors('lastDoneOn')
        ->assertSee('A project cannot have been done in the future.');
});

it('accepts tonight in the kitchen, when the server already says tomorrow', function () {
    // 8pm Toronto on 11 January is already the 12th in UTC. "Today" has to be
    // the household's date, or the household could not record this evening.
    Carbon::setTestNow(Carbon::parse('2026-01-12 01:00:00', 'UTC'));
    HouseholdSettings::set(HouseholdSettings::KEY_TIME_ZONE, 'America/Toronto');

    aProjectForm(['lastDoneOn' => '2026-01-11'])->call('save')->assertHasNoErrors();

    expect(KitchenProject::query()->firstWhere('name', 'Big batch minestrone')->last_done_on->toDateString())
        ->toBe('2026-01-11');

    Carbon::setTestNow();
});

it('refuses a bad ingredient amount, like the recipe editor does', function () {
    aProjectForm(['ingredients.0.quantity' => 'a handful'])
        ->call('save')
        ->assertHasErrors('ingredients.0.quantity')
        ->assertSee('Give the amount as a number');
});

it('writes nothing at all when one field is wrong', function () {
    $project = KitchenProject::factory()->withIngredients(2)->create([
        'name' => 'Fresh hummus',
        'every_days' => 7,
    ]);

    aProjectForm(['everyDays' => 'weekly'], project: $project)
        ->call('save')
        ->assertHasErrors('everyDays');

    expect($project->fresh()->name)->toBe('Fresh hummus')
        ->and($project->fresh()->every_days)->toBe(7)
        ->and($project->fresh()->ingredients)->toHaveCount(2);
});

it('keeps the ingredients of a recipe and a project apart in the same table', function () {
    // One polymorphic table, two parents (MM-3). An editor writing the wrong
    // morph type would put a project's shopping on a recipe.
    $recipe = Recipe::factory()->withIngredients(2)->create();

    aProjectForm()->call('save')->assertHasNoErrors();

    $project = KitchenProject::query()->firstWhere('name', 'Big batch minestrone');

    expect($project->ingredients)->toHaveCount(1)
        ->and($recipe->fresh()->ingredients)->toHaveCount(2)
        ->and($project->ingredients->first()->ingredientable->is($project))->toBeTrue();
});
