<?php

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Livewire\Settings;
use App\Models\CookScheduleNight;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Setting;
use App\Settings\HouseholdSettings;
use Database\Seeders\HouseholdDefaultsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The Settings page (MM-4): the one place the household changes a value that
 * would otherwise be hard-coded.
 *
 * Two things are worth the test time. First, that every setting really saves —
 * a page that silently drops one field is worse than no page, because the
 * household believes the week has changed. Second, that bad input is refused
 * with something a person can act on, and that a refused save changes nothing
 * at all.
 */

/**
 * A whole week, so a test only has to say the part it cares about.
 *
 * @return array<string, array{cook: string, time_budget: string}>
 */
function aWeekOf(CookAssignment $cook, EffortTier $timeBudget): array
{
    $week = [];

    foreach (Weekday::week() as $weekday) {
        $week[$weekday->value] = [
            'cook' => $cook->value,
            'time_budget' => $timeBudget->value,
        ];
    }

    return $week;
}

/**
 * Every field filled in with something other than the starting default, so a
 * setting that fails to save shows up as the default it was left at.
 *
 * @return array<string, mixed>
 */
function aFilledInForm(): array
{
    $schedule = aWeekOf(CookAssignment::Me, EffortTier::Project60);
    $schedule[Weekday::Tuesday->value] = [
        'cook' => CookAssignment::Partner->value,
        'time_budget' => EffortTier::Chaos15->value,
    ];
    $schedule[Weekday::Friday->value] = [
        'cook' => CookAssignment::Nobody->value,
        'time_budget' => EffortTier::Chaos15->value,
    ];

    return [
        'cookNames' => ['me' => 'Adrian', 'partner' => 'Milly'],
        'timeZone' => 'America/Vancouver',
        'schedule' => $schedule,
        'shoppingTimes' => [
            ['weekday' => Weekday::Saturday->value, 'time' => '09:00'],
            ['weekday' => Weekday::Tuesday->value, 'time' => '18:15'],
        ],
        'prepBlock' => [
            'weekday' => Weekday::Sunday->value,
            'start' => '19:00',
            'end' => '20:30',
        ],
        'dinnerTime' => '17:45',
        'planPostTime' => '20:00',
        'dailyDinnerReminderTime' => '14:30',
        'rawSplitFridgeLifeDays' => '3',
        'lowFreezerStashPortions' => '4',
    ];
}

/**
 * The page with a valid form in it, and one thing changed. $overrides is keyed
 * by property path, so a test can break exactly one field.
 *
 * @param  array<string, mixed>  $overrides
 */
function aSettingsForm(array $overrides = []): Testable
{
    $component = Livewire::test(Settings::class)->set(aFilledInForm());

    foreach ($overrides as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

/*
|--------------------------------------------------------------------------
| Showing what the household has now
|--------------------------------------------------------------------------
*/

it('opens with the settings the household is running on', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    HouseholdSettings::set(HouseholdSettings::KEY_DINNER_TIME, '17:15');
    CookScheduleNight::forWeekday(Weekday::Thursday)->update([
        'cook' => CookAssignment::Partner,
        'time_budget' => EffortTier::Chaos15,
    ]);

    Livewire::test(Settings::class)
        ->assertSet('dinnerTime', '17:15')
        ->assertSet('timeZone', 'America/Toronto')
        ->assertSet('planPostTime', '19:00')
        ->assertSet('dailyDinnerReminderTime', '15:00')
        ->assertSet('rawSplitFridgeLifeDays', '2')
        ->assertSet('lowFreezerStashPortions', '2')
        ->assertSet('prepBlock', ['weekday' => 'monday', 'start' => '20:00', 'end' => '21:00'])
        ->assertSet('shoppingTimes', [
            ['weekday' => 'sunday', 'time' => '10:00'],
            ['weekday' => 'wednesday', 'time' => '17:30'],
        ])
        ->assertSet('schedule.thursday', ['cook' => 'partner', 'time_budget' => '15_min_chaos'])
        ->assertSet('schedule.monday', ['cook' => 'together', 'time_budget' => '30_min_workhorse']);
});

it('offers a whole week even on a database that was never seeded', function () {
    expect(Setting::count())->toBe(0)
        ->and(CookScheduleNight::count())->toBe(0);

    Livewire::test(Settings::class)
        ->assertOk()
        ->assertCount('schedule', 7)
        ->assertSet('schedule.sunday', ['cook' => 'together', 'time_budget' => '30_min_workhorse'])
        ->assertSet('timeZone', 'America/Toronto');
});

it('renders the household’s values in the form', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    HouseholdSettings::set(HouseholdSettings::KEY_DINNER_TIME, '17:15');

    $this->get('/settings')
        ->assertOk()
        ->assertSee('The week')
        ->assertSee('Shopping days')
        // The value is rendered server-side: the first paint of a Livewire page
        // is plain HTML, so an unmarked field would show the wrong setting.
        ->assertSee('value="17:15"', escape: false)
        ->assertSee('Save settings');
});

it('calls the cooks by the names the household typed', function () {
    Livewire::test(Settings::class)
        ->set('cookNames', ['me' => 'Adrian', 'partner' => 'Milly'])
        // The week's pickers read back the names before anything is saved.
        ->assertSee('Adrian')
        ->assertSee('Milly')
        ->assertSee('Together');
});

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

it('saves every setting on the page', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    aSettingsForm()
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSee('Saved.');

    expect(HouseholdSettings::cookNames())->toBe(['me' => 'Adrian', 'partner' => 'Milly'])
        ->and(HouseholdSettings::timeZone())->toBe('America/Vancouver')
        ->and(HouseholdSettings::dinnerTime())->toBe('17:45')
        ->and(HouseholdSettings::planPostTime())->toBe('20:00')
        ->and(HouseholdSettings::dailyDinnerReminderTime())->toBe('14:30')
        ->and(HouseholdSettings::rawSplitFridgeLifeDays())->toBe(3)
        ->and(HouseholdSettings::lowFreezerStashPortions())->toBe(4)
        ->and(HouseholdSettings::prepBlock())->toBe([
            'weekday' => Weekday::Sunday,
            'start' => '19:00',
            'end' => '20:30',
        ]);

    $windows = HouseholdSettings::shoppingTimes();

    expect($windows)->toHaveCount(2)
        ->and($windows[0])->toBe(['weekday' => Weekday::Saturday, 'time' => '09:00'])
        ->and($windows[1])->toBe(['weekday' => Weekday::Tuesday, 'time' => '18:15']);
});

it('saves the week as the seven rows the plan copies from', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    aSettingsForm()->call('save')->assertHasNoErrors();

    $week = CookScheduleNight::week();

    expect($week)->toHaveCount(7)
        ->and($week['tuesday']->cook)->toBe(CookAssignment::Partner)
        ->and($week['tuesday']->time_budget)->toBe(EffortTier::Chaos15)
        ->and($week['friday']->cook)->toBe(CookAssignment::Nobody)
        ->and($week['monday']->cook)->toBe(CookAssignment::Me)
        ->and($week['monday']->time_budget)->toBe(EffortTier::Project60);
});

it('keeps the numbers as numbers, not as the strings a form posts', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    aSettingsForm()->call('save')->assertHasNoErrors();

    expect(Setting::read(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS))->toBe(3)
        ->and(Setting::read(HouseholdSettings::KEY_LOW_FREEZER_STASH_PORTIONS))->toBe(4);
});

it('stores a typed time in the format the rest of the app reads', function () {
    // "9:00" and "17:30:00" are both things a browser or a person produces;
    // HouseholdSettings::at() and the scheduler only understand "HH:MM".
    aSettingsForm([
        'dinnerTime' => '9:00',
        'planPostTime' => '17:30:00',
        'shoppingTimes.0.time' => ' 8:05 ',
    ])->call('save')->assertHasNoErrors();

    expect(HouseholdSettings::dinnerTime())->toBe('09:00')
        ->and(HouseholdSettings::planPostTime())->toBe('17:30')
        ->and(HouseholdSettings::shoppingTimes()[0]['time'])->toBe('08:05');
});

it('trims the cooks’ names', function () {
    aSettingsForm(['cookNames' => ['me' => '  Adrian ', 'partner' => ' Milly']])
        ->call('save')
        ->assertHasNoErrors();

    expect(HouseholdSettings::cookNames())->toBe(['me' => 'Adrian', 'partner' => 'Milly']);
});

it('lets the household turn the freezer warning off with a zero', function () {
    aSettingsForm(['lowFreezerStashPortions' => '0'])
        ->call('save')
        ->assertHasNoErrors();

    expect(HouseholdSettings::lowFreezerStashPortions())->toBe(0);
});

it('shows what was saved when the page is opened again', function () {
    aSettingsForm()->call('save')->assertHasNoErrors();

    Livewire::test(Settings::class)
        ->assertSet('dinnerTime', '17:45')
        ->assertSet('cookNames', ['me' => 'Adrian', 'partner' => 'Milly'])
        ->assertSet('schedule.tuesday', ['cook' => 'partner', 'time_budget' => '15_min_chaos']);
});

it('retires the saved banner as soon as something is edited again', function () {
    aSettingsForm()
        ->call('save')
        ->assertSet('saved', true)
        ->set('dinnerTime', '18:30')
        ->assertSet('saved', false);
});

/*
|--------------------------------------------------------------------------
| The shopping days, added and removed
|--------------------------------------------------------------------------
*/

it('adds a shopping day on the first free weekday', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    Livewire::test(Settings::class)
        // Starts on Sunday and Wednesday.
        ->call('addShoppingTime')
        ->assertCount('shoppingTimes', 3)
        ->assertSet('shoppingTimes.2.weekday', 'monday')
        ->call('save')
        ->assertHasNoErrors();

    expect(HouseholdSettings::shoppingTimes())->toHaveCount(3);
});

it('never offers more shopping days than there are days', function () {
    $component = Livewire::test(Settings::class);

    foreach (range(1, 10) as $ignored) {
        $component->call('addShoppingTime');
    }

    $component->assertCount('shoppingTimes', 7)->call('save')->assertHasNoErrors();
});

it('removes a shopping day', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    Livewire::test(Settings::class)
        ->call('removeShoppingTime', 0)
        ->assertCount('shoppingTimes', 1)
        // The rows close up, so the remaining one is row zero.
        ->assertSet('shoppingTimes.0.weekday', 'wednesday')
        ->call('save')
        ->assertHasNoErrors();

    $windows = HouseholdSettings::shoppingTimes();

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['weekday'])->toBe(Weekday::Wednesday);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a week with no shopping day in it', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    Livewire::test(Settings::class)
        ->call('removeShoppingTime', 0)
        ->call('removeShoppingTime', 0)
        ->assertCount('shoppingTimes', 0)
        ->call('save')
        ->assertHasErrors('shoppingTimes')
        ->assertSee('Keep at least one shopping day');

    // Nothing was written: the household still shops twice a week.
    expect(HouseholdSettings::shoppingTimes())->toHaveCount(2);
});

it('refuses the same shopping day twice', function () {
    aSettingsForm([
        'shoppingTimes' => [
            ['weekday' => Weekday::Sunday->value, 'time' => '10:00'],
            ['weekday' => Weekday::Sunday->value, 'time' => '17:30'],
        ],
    ])
        ->call('save')
        ->assertHasErrors('shoppingTimes.1.weekday')
        ->assertSee('Each shopping day can only be listed once.');
});

it('refuses a shopping trip with no time on it', function () {
    aSettingsForm(['shoppingTimes.1.time' => ''])
        ->call('save')
        ->assertHasErrors('shoppingTimes.1.time')
        ->assertSee('Give every shopping trip a time.');
});

it('refuses a shopping day that is not a day', function () {
    aSettingsForm(['shoppingTimes.0.weekday' => 'funday'])
        ->call('save')
        ->assertHasErrors('shoppingTimes.0.weekday')
        ->assertSee('Pick a day for every shopping trip.');
});

it('refuses a time that is not a time', function (string $field) {
    aSettingsForm([$field => '25:00'])
        ->call('save')
        ->assertHasErrors($field)
        ->assertSee('Use a 24-hour time, like 17:30.');
})->with([
    'dinner' => 'dinnerTime',
    'plan post' => 'planPostTime',
    'daily reminder' => 'dailyDinnerReminderTime',
    'prep block start' => 'prepBlock.start',
    'prep block end' => 'prepBlock.end',
    'a shopping trip' => 'shoppingTimes.0.time',
]);

it('refuses a time that was left empty', function (string $field, string $message) {
    aSettingsForm([$field => ''])
        ->call('save')
        ->assertHasErrors($field)
        ->assertSee($message);
})->with([
    ['dinnerTime', 'Give dinner a time.'],
    ['planPostTime', 'Give the plan post a time.'],
    ['dailyDinnerReminderTime', 'Give the daily reminder a time.'],
    ['prepBlock.start', 'Give the prep block a start time.'],
    ['prepBlock.end', 'Give the prep block an end time.'],
]);

it('refuses a prep block that ends before it starts', function (string $start, string $end) {
    aSettingsForm(['prepBlock.start' => $start, 'prepBlock.end' => $end])
        ->call('save')
        ->assertHasErrors('prepBlock.end')
        ->assertSee('The prep block has to end after it starts.');
})->with([
    'ends earlier' => ['21:00', '20:00'],
    // A block with no length in it would plan no prep at all.
    'ends when it starts' => ['20:00', '20:00'],
]);

it('refuses a cook with no name', function () {
    aSettingsForm(['cookNames' => ['me' => '', 'partner' => 'Milly']])
        ->call('save')
        ->assertHasErrors('cookNames.me')
        ->assertSee('Both cooks need a name.');
});

it('refuses a name too long to read on a plan', function () {
    aSettingsForm(['cookNames.partner' => str_repeat('a', 41)])
        ->call('save')
        ->assertHasErrors('cookNames.partner')
        ->assertSee('Keep a cook’s name to 40 characters or fewer.');
});

it('refuses a time zone the app does not know', function () {
    aSettingsForm(['timeZone' => 'Mars/Olympus_Mons'])
        ->call('save')
        ->assertHasErrors('timeZone')
        ->assertSee('That is not a time zone the app knows.');
});

it('refuses an empty time zone', function () {
    aSettingsForm(['timeZone' => ''])
        ->call('save')
        ->assertHasErrors('timeZone')
        ->assertSee('Pick the household’s time zone.');
});

it('refuses a night with nobody and no time on it', function (string $property, string $message) {
    aSettingsForm([$property => 'whenever'])
        ->call('save')
        ->assertHasErrors($property)
        ->assertSee($message);
})->with([
    ['schedule.monday.cook', 'Pick who cooks on every night of the week.'],
    ['schedule.monday.time_budget', 'Pick how much time there is on every night of the week.'],
]);

it('refuses a raw-split fridge life that is not a sane number of days', function (string $value, string $message) {
    aSettingsForm(['rawSplitFridgeLifeDays' => $value])
        ->call('save')
        ->assertHasErrors('rawSplitFridgeLifeDays')
        ->assertSee($message);
})->with([
    'empty' => ['', 'Say how long a raw split keeps, in days.'],
    'zero' => ['0', 'A raw split has to keep for at least a day.'],
    'a fortnight' => ['15', 'Two weeks in the fridge is not a raw split.'],
    'words' => ['two', 'Give the fridge life as a whole number of days.'],
    'half a day' => ['2.5', 'Give the fridge life as a whole number of days.'],
]);

it('refuses a negative freezer warning', function () {
    aSettingsForm(['lowFreezerStashPortions' => '-1'])
        ->call('save')
        ->assertHasErrors('lowFreezerStashPortions')
        ->assertSee('The warning level cannot be negative.');
});

it('says plainly that nothing was saved', function () {
    aSettingsForm(['dinnerTime' => ''])
        ->call('save')
        ->assertSet('saved', false)
        ->assertSee('Nothing was saved.');
});

it('writes no setting at all when one field is wrong', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    // Everything else on the form is valid and different from the defaults.
    aSettingsForm(['dinnerTime' => 'teatime'])
        ->call('save')
        ->assertHasErrors('dinnerTime');

    expect(HouseholdSettings::dinnerTime())->toBe('18:00')
        ->and(HouseholdSettings::timeZone())->toBe('America/Toronto')
        ->and(HouseholdSettings::cookNames())->toBe(['me' => 'Me', 'partner' => 'Partner'])
        ->and(HouseholdSettings::rawSplitFridgeLifeDays())->toBe(2)
        ->and(HouseholdSettings::shoppingTimes()[0]['weekday'])->toBe(Weekday::Sunday)
        ->and(CookScheduleNight::forWeekday(Weekday::Monday)->cook)->toBe(CookAssignment::Together);
});

/*
|--------------------------------------------------------------------------
| What a change does, and does not, reach
|--------------------------------------------------------------------------
*/

it('leaves the plan that is already out alone', function () {
    $this->seed(HouseholdDefaultsSeeder::class);

    $plan = Plan::factory()->create();
    $night = PlanNight::factory()->for($plan)->create([
        'cook' => CookAssignment::Together,
        'time_budget' => EffortTier::Workhorse30,
    ]);

    // The household rewrites its normal week: every night is now mine, 60 minutes.
    aSettingsForm()->call('save')->assertHasNoErrors();

    // The plan on the fridge copied its values when it was made (MM-3), so it
    // only changes when someone re-plans it.
    expect($night->fresh()->cook)->toBe(CookAssignment::Together)
        ->and($night->fresh()->time_budget)->toBe(EffortTier::Workhorse30);
});

it('changes the time zone the scheduler reads, and not the one the app stores in', function () {
    aSettingsForm(['timeZone' => 'Europe/Berlin'])->call('save')->assertHasNoErrors();

    // AppServiceProvider points app.schedule_timezone at this setting on every
    // console boot, so the reminders move with it (docs/decisions.md). What must
    // not move is the zone the app computes and stores in: that stays UTC, or
    // every timestamp already in MySQL would mean something else.
    expect(HouseholdSettings::timeZone())->toBe('Europe/Berlin')
        ->and(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});
