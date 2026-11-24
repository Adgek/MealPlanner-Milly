# Conventions

Working agreements for this repository. They apply to everyone — people and agents.

## Read these first

1. **`AppPlan.md`** — what the household actually needs and why. The six constraints
   in there (small fridge, fixed shopping days, cooking handoffs, randomization,
   bulk ingredient splits, picky-kid off-ramps) are the point of the app.
2. **`docs/decisions.md`** — the shared decisions every ticket depends on: the stack,
   the defaults, the starting settings. When a ticket makes a decision that other
   tickets need, add it there.

Don't re-litigate a recorded decision in passing. If one turns out to be wrong,
change `docs/decisions.md` in the same commit and say so.

## Household values go in Settings, not in code

Anything the household might want to change is data, not a constant. Who cooks on
a Tuesday, how much time there is, the shopping days and times, the prep block,
dinner time, the reminder times, the fridge life for a raw split, the low-stash
warning — all of it belongs on the Settings page (MM-4), with a starting default.

So: no `if ($day === 'Sunday')` and no `const DINNER_TIME = '18:00'`. Read the
setting. If a value has no home in Settings yet, add one rather than hard-coding it.

The one exception is bootstrap configuration the app needs before it can read a
setting — `HOUSEHOLD_TIMEZONE`, the database connection. That lives in `.env`.

## Planning logic has unit tests

The randomizer, the shopping-window maths, the bulk-ingredient pairing, the grocery
list merge: these are the parts that will be wrong in a way nobody notices until
dinner. Test them as plain units in `tests/Unit`, with no database and no framework
boot, so each rule is pinned down on its own.

Keep that logic in classes that can be constructed directly and called with plain
values. Feature tests in `tests/Feature` cover the pages and the commands; they are
not a substitute for the unit tests underneath.

## The rest

- **No login.** MM-1 chose none, because the app only runs on the home network.
  Don't add auth, and don't expose the app to the internet while that holds.
- **Everything runs in Docker.** `php`, `composer`, `npm`, `artisan`, Pest and Pint
  all run inside the `app` container. See the README for the commands.
- **MySQL 8.4 is the database.** Tests run against it too, in a separate
  `<DB_DATABASE>_test` database — not SQLite — so migrations and queries are
  exercised against the real engine.
- **Eloquent, with factories and seeders.** Not hand-written query functions. Every
  entity has a factory; `HouseholdDefaultsSeeder` installs the starting settings
  and the weekly cook schedule, and `ExampleDataSeeder` the sample recipes.
- **Enum columns are strings cast to PHP enums** (`app/Enums`), not MySQL `ENUM`s,
  so adding a case doesn't need a migration. The stored values are part of the
  data: renaming one invalidates existing rows.
- **Blade for pages, Livewire where a page updates itself.** Livewire components are
  class-based: `app/Livewire/Thing.php` plus `resources/views/livewire/thing.blade.php`.
  Add new pages to `resources/views/components/navigation.blade.php`.
- **Pint decides formatting.** Run `make format` before committing; `make lint` is
  the check. Don't hand-tune style.
- **Secrets live in `.env`.** `.env.example` carries placeholders only, and gains a
  line whenever a new setting appears.
- **Store in UTC, display in the household's zone.** `config('app.timezone')` is
  `UTC` and is not configurable, so every timestamp in MySQL is an absolute
  instant. Apply `HouseholdSettings::timeZone()` when a page shows a time; the
  scheduler already runs on it via `app.schedule_timezone`. Be explicit about time
  zones in any date maths that crosses a day boundary — 7:00 pm in Toronto is
  already tomorrow in UTC.
- **A night is a calendar date, not an instant.** Plan nights, shopping dates and
  meal history use `date` columns and the `immutable_date` cast, so they can't
  slide a day when a zone is applied. Use `HouseholdSettings::at()` to turn a
  stored `"HH:MM"` setting plus a date into a real moment.
