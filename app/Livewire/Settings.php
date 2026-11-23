<?php

namespace App\Livewire;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use App\Settings\HouseholdSettings;
use App\Settings\TimeOfDay;
use Closure;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The settings page: every household value that would otherwise be hard-coded,
 * on one page (AGENTS.md, docs/decisions.md).
 *
 * The page edits two stores at once. Most values are rows in `settings`, read
 * and written through HouseholdSettings so each default lives in one place; the
 * weekly cook schedule is seven rows in `cook_schedule_nights`, because MM-3
 * made each night its own record. Saving writes both in one transaction, so a
 * half-applied week is not a state the household can land in.
 *
 * Changing anything here affects the *next* plan. A plan that is already out
 * copied its cook, its time budget and its grocery list when it was made
 * (MM-3), so it only changes when someone re-plans it — which is what lets the
 * household fix next week's schedule while this week's plan is on the fridge.
 */
#[Title('Settings')]
class Settings extends Component
{
    /**
     * What the two cooks are called, keyed by CookAssignment value.
     *
     * @var array<string, string>
     */
    public array $cookNames = [];

    public string $timeZone = '';

    /**
     * The normal week, keyed by weekday value: who cooks and how long they have.
     *
     * @var array<string, array{cook: string, time_budget: string}>
     */
    public array $schedule = [];

    /**
     * The shopping trips, in the order the household keeps them.
     *
     * @var array<int, array{weekday: string, time: string}>
     */
    public array $shoppingTimes = [];

    /**
     * @var array{weekday: string, start: string, end: string}
     */
    public array $prepBlock = [];

    public string $dinnerTime = '';

    public string $planPostTime = '';

    public string $dailyDinnerReminderTime = '';

    /**
     * Held as strings, not ints: a cleared number field arrives as "" and has to
     * fail `required` rather than quietly become 0.
     */
    public string $rawSplitFridgeLifeDays = '';

    public string $lowFreezerStashPortions = '';

    /**
     * Whether the last action saved. Drives the confirmation banner, and is
     * cleared again the moment anything is edited.
     */
    public bool $saved = false;

    /**
     * Fill the form from what the household has now.
     *
     * Every value falls back to its default (HouseholdSettings for the settings
     * rows, CookScheduleNight for a missing night), so the page still works on a
     * database that has not been seeded.
     */
    public function mount(): void
    {
        $this->cookNames = HouseholdSettings::cookNames();
        $this->timeZone = HouseholdSettings::timeZone();

        $week = CookScheduleNight::week();

        foreach (Weekday::week() as $weekday) {
            $night = $week->get($weekday->value);

            $this->schedule[$weekday->value] = [
                'cook' => ($night?->cook ?? CookScheduleNight::DEFAULT_COOK)->value,
                'time_budget' => ($night?->time_budget ?? CookScheduleNight::DEFAULT_TIME_BUDGET)->value,
            ];
        }

        $this->shoppingTimes = array_map(fn (array $window): array => [
            'weekday' => $window['weekday']->value,
            'time' => $window['time'],
        ], HouseholdSettings::shoppingTimes());

        $prepBlock = HouseholdSettings::prepBlock();

        $this->prepBlock = [
            'weekday' => $prepBlock['weekday']->value,
            'start' => $prepBlock['start'],
            'end' => $prepBlock['end'],
        ];

        $this->dinnerTime = HouseholdSettings::dinnerTime();
        $this->planPostTime = HouseholdSettings::planPostTime();
        $this->dailyDinnerReminderTime = HouseholdSettings::dailyDinnerReminderTime();
        $this->rawSplitFridgeLifeDays = (string) HouseholdSettings::rawSplitFridgeLifeDays();
        $this->lowFreezerStashPortions = (string) HouseholdSettings::lowFreezerStashPortions();
    }

    /**
     * Any edit retires the "Saved" banner, so it always refers to what is in the
     * database rather than to what is on screen.
     */
    public function updated(string $property): void
    {
        $this->saved = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'cookNames.'.CookAssignment::Me->value => ['required', 'string', 'max:40'],
            'cookNames.'.CookAssignment::Partner->value => ['required', 'string', 'max:40'],

            'timeZone' => ['required', 'string', 'timezone'],

            // Exactly the seven weekdays, no more and no fewer: the schedule is
            // a whole week or it is broken.
            'schedule' => ['required', 'array:'.implode(',', $this->valuesOf(Weekday::class)), 'size:7'],
            'schedule.*.cook' => ['required', Rule::in($this->valuesOf(CookAssignment::class))],
            'schedule.*.time_budget' => ['required', Rule::in($this->valuesOf(EffortTier::class))],

            // At least one shopping day, because every window in the plan is
            // derived from a shop. At most seven, because a day can only be
            // listed once.
            'shoppingTimes' => ['required', 'array', 'min:1', 'max:7'],
            'shoppingTimes.*.weekday' => ['required', 'distinct', Rule::in($this->valuesOf(Weekday::class))],
            'shoppingTimes.*.time' => $this->timeRules(),

            'prepBlock.weekday' => ['required', Rule::in($this->valuesOf(Weekday::class))],
            'prepBlock.start' => $this->timeRules(),
            'prepBlock.end' => [...$this->timeRules(), $this->endsAfterItStarts()],

            'dinnerTime' => $this->timeRules(),
            'planPostTime' => $this->timeRules(),
            'dailyDinnerReminderTime' => $this->timeRules(),

            // A raw split that has to keep for a fortnight is not a raw split.
            'rawSplitFridgeLifeDays' => ['required', 'integer', 'min:1', 'max:14'],
            // Zero is allowed: it means "never warn me about the freezer".
            'lowFreezerStashPortions' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * Messages the household can act on, in the household's own words. The
     * defaults ("The shopping times.0.time field must match the format H:i")
     * name the array path, which means nothing to someone holding a phone.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $atLeastOneShoppingDay = 'Keep at least one shopping day: every plan is built around a shop.';
        $notATime = 'Use a 24-hour time, like 17:30.';

        return [
            'cookNames.*.required' => 'Both cooks need a name.',
            'cookNames.*.max' => 'Keep a cook’s name to 40 characters or fewer.',

            'timeZone.required' => 'Pick the household’s time zone.',
            'timeZone.timezone' => 'That is not a time zone the app knows. Pick one from the list.',

            'schedule.*' => 'The week needs all seven nights.',
            'schedule.*.cook.*' => 'Pick who cooks on every night of the week.',
            'schedule.*.time_budget.*' => 'Pick how much time there is on every night of the week.',

            'shoppingTimes.required' => $atLeastOneShoppingDay,
            'shoppingTimes.min' => $atLeastOneShoppingDay,
            'shoppingTimes.max' => 'There are only seven days in a week.',
            'shoppingTimes.*.weekday.required' => 'Pick a day for every shopping trip.',
            'shoppingTimes.*.weekday.distinct' => 'Each shopping day can only be listed once.',
            'shoppingTimes.*.weekday.in' => 'Pick a day for every shopping trip.',
            'shoppingTimes.*.time.required' => 'Give every shopping trip a time.',
            'shoppingTimes.*.time.date_format' => $notATime,

            'prepBlock.weekday.*' => 'Pick the day of the prep block.',
            'prepBlock.start.required' => 'Give the prep block a start time.',
            'prepBlock.end.required' => 'Give the prep block an end time.',
            'prepBlock.*.date_format' => $notATime,

            'dinnerTime.required' => 'Give dinner a time.',
            'planPostTime.required' => 'Give the plan post a time.',
            'dailyDinnerReminderTime.required' => 'Give the daily reminder a time.',
            'dinnerTime.date_format' => $notATime,
            'planPostTime.date_format' => $notATime,
            'dailyDinnerReminderTime.date_format' => $notATime,

            'rawSplitFridgeLifeDays.required' => 'Say how long a raw split keeps, in days.',
            'rawSplitFridgeLifeDays.integer' => 'Give the fridge life as a whole number of days.',
            'rawSplitFridgeLifeDays.min' => 'A raw split has to keep for at least a day.',
            'rawSplitFridgeLifeDays.max' => 'Two weeks in the fridge is not a raw split. Use 14 days or fewer.',

            'lowFreezerStashPortions.required' => 'Say how few portions counts as low.',
            'lowFreezerStashPortions.integer' => 'Give the warning level as a whole number of portions.',
            'lowFreezerStashPortions.min' => 'The warning level cannot be negative.',
            'lowFreezerStashPortions.max' => 'That is more portions than the freezer holds. Use 99 or fewer.',
        ];
    }

    /**
     * Save every setting, or none of them.
     *
     * Times are tidied before they are validated, so "9:00" is stored as the
     * "09:00" the rest of the app expects, and anything that is not a time at
     * all stays as it was typed and fails validation with it on screen.
     */
    public function save(): void
    {
        $this->saved = false;

        $this->tidyInput();
        $this->validate();

        DB::transaction(function (): void {
            HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, [
                CookAssignment::Me->value => $this->cookNames[CookAssignment::Me->value],
                CookAssignment::Partner->value => $this->cookNames[CookAssignment::Partner->value],
            ]);

            HouseholdSettings::set(HouseholdSettings::KEY_TIME_ZONE, $this->timeZone);

            HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, array_values(array_map(
                fn (array $window): array => [
                    'weekday' => $window['weekday'],
                    'time' => $window['time'],
                ],
                $this->shoppingTimes,
            )));

            HouseholdSettings::set(HouseholdSettings::KEY_PREP_BLOCK, [
                'weekday' => $this->prepBlock['weekday'],
                'start' => $this->prepBlock['start'],
                'end' => $this->prepBlock['end'],
            ]);

            HouseholdSettings::set(HouseholdSettings::KEY_DINNER_TIME, $this->dinnerTime);
            HouseholdSettings::set(HouseholdSettings::KEY_PLAN_POST_TIME, $this->planPostTime);
            HouseholdSettings::set(HouseholdSettings::KEY_DAILY_DINNER_REMINDER_TIME, $this->dailyDinnerReminderTime);
            HouseholdSettings::set(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS, (int) $this->rawSplitFridgeLifeDays);
            HouseholdSettings::set(HouseholdSettings::KEY_LOW_FREEZER_STASH_PORTIONS, (int) $this->lowFreezerStashPortions);

            $this->saveTheWeek();
        });

        $this->saved = true;
    }

    /**
     * Add a shopping trip, on the first day that is not already a shopping day.
     */
    public function addShoppingTime(): void
    {
        $taken = array_column($this->shoppingTimes, 'weekday');

        $free = collect(Weekday::week())
            ->first(fn (Weekday $weekday): bool => ! in_array($weekday->value, $taken, true));

        if ($free === null) {
            return;
        }

        $this->saved = false;

        $this->shoppingTimes[] = [
            'weekday' => $free->value,
            'time' => $this->aShoppingTime(),
        ];
    }

    /**
     * Drop a shopping trip. Dropping the last one is allowed — the household
     * finds out it has to keep one when it tries to save, rather than wrestling
     * with a button that will not click.
     */
    public function removeShoppingTime(int $index): void
    {
        unset($this->shoppingTimes[$index]);

        $this->shoppingTimes = array_values($this->shoppingTimes);
        $this->saved = false;

        $this->resetValidation();
    }

    /**
     * The nights of the week, for the cook-schedule grid.
     *
     * @return array<int, Weekday>
     */
    public function nights(): array
    {
        return Weekday::week();
    }

    /**
     * Who can be on for a night, labelled with the names in the form — so the
     * picker says "Milly" the moment the household types it, without saving.
     *
     * @return array<string, string>
     */
    public function cookOptions(): array
    {
        $fallback = HouseholdSettings::defaults()[HouseholdSettings::KEY_COOK_NAMES];

        return [
            CookAssignment::Me->value => $this->cookName(CookAssignment::Me, $fallback),
            CookAssignment::Partner->value => $this->cookName(CookAssignment::Partner, $fallback),
            CookAssignment::Together->value => CookAssignment::Together->label(),
            CookAssignment::Nobody->value => CookAssignment::Nobody->label(),
        ];
    }

    /**
     * How much time a night can have. The same tiers a recipe is tagged with,
     * which is what lets the picker (MM-6) match the two.
     *
     * @return array<string, string>
     */
    public function timeBudgetOptions(): array
    {
        return $this->labelled(EffortTier::cases());
    }

    /**
     * @return array<string, string>
     */
    public function weekdayOptions(): array
    {
        return $this->labelled(Weekday::week());
    }

    /**
     * Every time zone PHP knows, grouped by region so the phone's picker is
     * navigable.
     *
     * @return array<string, array<string, string>>
     */
    public function timeZoneOptions(): array
    {
        $groups = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            [$region, $place] = array_pad(explode('/', $identifier, 2), 2, null);

            $group = $place === null ? 'Other' : $region;

            $groups[$group][$identifier] = str_replace('_', ' ', $place ?? $identifier);
        }

        ksort($groups);

        return $groups;
    }

    /**
     * The shopping trips as one line — "Sunday 10:00 am · Wednesday 5:30 pm" —
     * so the household can read back what it has without decoding time inputs.
     */
    public function shoppingSummary(): string
    {
        $trips = [];

        foreach ($this->shoppingTimes as $window) {
            $weekday = Weekday::tryFrom($window['weekday'] ?? '');

            if ($weekday === null) {
                continue;
            }

            $trips[] = $weekday->label().' '.TimeOfDay::label($window['time'] ?? '');
        }

        return implode(' · ', $trips);
    }

    public function render(): View
    {
        return view('livewire.settings');
    }

    /**
     * Write the seven nights of the normal week.
     *
     * Only the real weekdays are written, and each one is matched on its weekday
     * column, so the table keeps exactly seven rows however the form arrives.
     */
    private function saveTheWeek(): void
    {
        foreach (Weekday::week() as $weekday) {
            $night = $this->schedule[$weekday->value] ?? null;

            if ($night === null) {
                continue;
            }

            CookScheduleNight::query()->updateOrCreate(
                ['weekday' => $weekday->value],
                [
                    'cook' => $night['cook'],
                    'time_budget' => $night['time_budget'],
                ],
            );
        }
    }

    /**
     * Trim the names and put every time into "HH:MM" before validation runs.
     */
    private function tidyInput(): void
    {
        foreach ([CookAssignment::Me, CookAssignment::Partner] as $cook) {
            $this->cookNames[$cook->value] = trim((string) ($this->cookNames[$cook->value] ?? ''));
        }

        $this->timeZone = trim($this->timeZone);

        $this->dinnerTime = $this->tidyTime($this->dinnerTime);
        $this->planPostTime = $this->tidyTime($this->planPostTime);
        $this->dailyDinnerReminderTime = $this->tidyTime($this->dailyDinnerReminderTime);

        $this->prepBlock['start'] = $this->tidyTime($this->prepBlock['start'] ?? '');
        $this->prepBlock['end'] = $this->tidyTime($this->prepBlock['end'] ?? '');

        foreach ($this->shoppingTimes as $index => $window) {
            $this->shoppingTimes[$index]['time'] = $this->tidyTime($window['time'] ?? '');
        }

        $this->rawSplitFridgeLifeDays = trim($this->rawSplitFridgeLifeDays);
        $this->lowFreezerStashPortions = trim($this->lowFreezerStashPortions);
    }

    /**
     * "9:00" becomes "09:00"; anything that is not a time is handed back as it
     * was typed, for the validator to reject and the field to show.
     */
    private function tidyTime(?string $value): string
    {
        return TimeOfDay::normalize($value) ?? trim((string) $value);
    }

    /**
     * @return array<int, string>
     */
    private function timeRules(): array
    {
        return ['required', 'date_format:'.TimeOfDay::FORMAT];
    }

    /**
     * A prep block that ends before it starts would silently plan no prep at
     * all, so it is a validation error rather than an empty block.
     */
    private function endsAfterItStarts(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $start = TimeOfDay::minutes($this->prepBlock['start'] ?? null);
            $end = TimeOfDay::minutes(is_string($value) ? $value : null);

            if ($start !== null && $end !== null && $end <= $start) {
                $fail('The prep block has to end after it starts.');
            }
        };
    }

    /**
     * A sensible time for a new shopping trip: the one the household already
     * shops at, or the starting default.
     */
    private function aShoppingTime(): string
    {
        $last = end($this->shoppingTimes);

        if (is_array($last) && TimeOfDay::isValid($last['time'] ?? null)) {
            return (string) $last['time'];
        }

        return HouseholdSettings::defaults()[HouseholdSettings::KEY_SHOPPING_TIMES][0]['time'];
    }

    /**
     * @param  array{me: string, partner: string}  $fallback
     */
    private function cookName(CookAssignment $cook, array $fallback): string
    {
        return trim($this->cookNames[$cook->value] ?? '') ?: $fallback[$cook->value];
    }

    /**
     * Turn a list of labelled enum cases into select options.
     *
     * @param  array<int, Weekday|EffortTier>  $cases
     * @return array<string, string>
     */
    private function labelled(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Every value of a backed enum, for an `in` rule.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return array<int, string>
     */
    private function valuesOf(string $enum): array
    {
        return array_column($enum::cases(), 'value');
    }
}
