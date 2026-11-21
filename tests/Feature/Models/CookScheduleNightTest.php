<?php

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The weekly cook schedule: the household's normal week, held in Settings.
 */

it('creates a schedule night and reads it back', function () {
    CookScheduleNight::create([
        'weekday' => Weekday::Tuesday,
        'cook' => CookAssignment::Partner,
        'time_budget' => EffortTier::Chaos15,
    ]);

    $night = CookScheduleNight::forWeekday(Weekday::Tuesday);

    expect($night->weekday)->toBe(Weekday::Tuesday)
        ->and($night->cook)->toBe(CookAssignment::Partner)
        ->and($night->time_budget)->toBe(EffortTier::Chaos15);
});

it('keeps the weekday, the cook and the time as enums', function () {
    $night = CookScheduleNight::factory()->on(Weekday::Friday)->create();

    $night->refresh();

    expect($night->weekday)->toBeInstanceOf(Weekday::class)
        ->and($night->cook)->toBeInstanceOf(CookAssignment::class)
        ->and($night->time_budget)->toBeInstanceOf(EffortTier::class);
});

it('holds one row per weekday', function () {
    CookScheduleNight::factory()->on(Weekday::Monday)->create();

    expect(fn () => CookScheduleNight::factory()->on(Weekday::Monday)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reads the week back Monday first', function () {
    // Inserted in a jumble; the week has to come back in reading order.
    foreach ([Weekday::Saturday, Weekday::Monday, Weekday::Thursday] as $weekday) {
        CookScheduleNight::factory()->on($weekday)->create();
    }

    expect(CookScheduleNight::week()->keys()->all())->toBe(['monday', 'thursday', 'saturday']);
});

it('returns nothing for a weekday with no schedule', function () {
    expect(CookScheduleNight::forWeekday(Weekday::Sunday))->toBeNull();
});

it('records a night nobody cooks', function () {
    $night = CookScheduleNight::factory()
        ->on(Weekday::Friday)
        ->cookedBy(CookAssignment::Nobody)
        ->create();

    expect($night->refresh()->cook->cooks())->toBeFalse();
});
