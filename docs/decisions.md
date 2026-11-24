# Decisions

Shared context for every ticket. Read this together with `AppPlan.md`. When a ticket makes a decision that other tickets need, add it here.

## Decided

- **We build a custom app.** We don't use a spreadsheet or a no-code tool.
- **Household values live in Settings, not in code.** This covers who cooks each night, how much time there is, shopping days and times, the prep block, dinner time and reminder times. Each value has a starting default and can be changed on the Settings page (MM-4).
- **The tech stack, hosting, data storage, messaging and dashboard device are decided.** See "Tech decisions" below. MM-1 is done, so app code can start (MM-2).

## Tech decisions (answered by the household in MM-1)

| # | Question | Answer | Why |
|---|---|---|---|
| 1 | Language and framework | **Laravel** (PHP) | The household asked for Laravel; one framework covers the pages, the commands and the timed jobs. |
| 2 | Data storage | **MySQL**, running in the same Docker setup | Asked for by the household; a real server database that the app container talks to over the Docker network. |
| 3 | Hosting | **Local Docker on a machine at home** | Asked for by the household; no cloud bill and no account to manage. The trade-off is that the machine has to stay on, and the app is only reachable at home. |
| 4 | Group chat | **Slack** | Asked for by the household; the bot posts with a token over HTTPS, so it works from home with no inbound connection and no SMS carrier registration. |
| 5 | Kitchen dashboard device | **A spare tablet showing a full-screen web page** | Asked for by the household; hardware they already own, and it shows the same web app as the phones. |
| 6 | Login | **None for now** | Asked for by the household. It only works because the app stays on the private home network, so nothing is exposed to the internet. Revisit this if the app is ever reachable from outside (see "Open, not blocking"). |

### What follows from those answers

- **The app is private-network only.** Publish the web port to the home LAN and **do not port-forward it or put it on a public hostname** while there is no login. Plain HTTP on the LAN is fine; no public certificate is needed.
- **Outbound still works.** Slack posts, and any outside "missed post" check (MM-10), only need outbound HTTPS, which a home machine has.
- **Links in Slack posts only open at home.** The plan-page link (MM-9) points at a LAN address, so it works on home wifi and not on mobile data.
- **The tablet needs no sign-in.** It just opens the dashboard URL full-screen, so MM-14 does not need a "stays logged in" story.

### Derived defaults (MM-2 confirms or changes these)

These are not household answers; they are the small choices Laravel and Docker imply. MM-2 may change them, and should record what it picked.

- **Containers:** Docker Compose with `app` (PHP + web), `db` (MySQL 8.4) and `scheduler`. MySQL data lives in a named volume.
- **Scheduler:** Laravel's own scheduler, in the `scheduler` container, running in the household time zone. This is the scheduler MM-9 and MM-13 should use.
- **Queues:** the database queue driver, so nothing extra has to run.
- **Pages:** Blade, plus Livewire where a page needs to update by itself (the shared grocery checkboxes in MM-8, the self-refreshing dashboard in MM-14).
- **Tests, lint, format:** Pest (or PHPUnit) and Pint, run inside the container.
- **Data access:** Eloquent models with factories and seeders, instead of hand-written query functions.
- **Import (MM-5):** a YAML or JSON file loaded by an `artisan` command.
- **Backups (MM-10):** a nightly `mysqldump` on a schedule, written outside the container and copied off the machine.

**Already on the dev machine** (checked in MM-1): Docker 29.5.3, Docker Compose v5.1.4, PHP 8.4.8, Composer 2.8.9, plus Node and pnpm for Laravel's asset build. MM-2 should not need new tooling.

### What MM-2 actually built

The skeleton is in place. The derived defaults above all held, with the specifics below. See the [README](../README.md) for the commands and `AGENTS.md` for the conventions.

| Choice | What MM-2 picked | Why |
|---|---|---|
| Versions | Laravel 13 on PHP 8.4, Livewire 4, Pest 4, Pint 1 | Current stable; PHP 8.4.8 is what the machine has. |
| `app` container | `php:8.4-apache` (Apache + mod_php), with Node 22 added | One process serves the pages, so "PHP + web" is a single service. Node is in the image so the asset build also runs in the container and the host needs only Docker. |
| `scheduler` container | The same image, running `php artisan schedule:work` | Nothing to keep in sync between two images. It waits for `app` to be healthy, so installs and migrations happen exactly once. |
| Published ports | **Only** the web port (`APP_PORT`, default 8000) | MySQL stays on the Docker network, as decided. Nothing else needs publishing, so the stack can't collide with another project's ports. |
| Asset tooling | **npm**, not pnpm | npm ships with Node, so the image needs nothing extra and there is one lockfile (`package-lock.json`) that the container and the host agree on. |
| Hot reloading | None. `npm run watch` (`vite build --watch`) instead | No Vite dev server means no second published port and no HMR setup; save a file, refresh the page. |
| `node_modules` | A named volume, not the bind mount | Vite's native binaries differ between macOS and the Linux container; each keeps its own copy. Everything else is bind-mounted, so edits apply immediately. |
| First boot | The container entrypoint creates `.env`, installs, generates `APP_KEY`, builds assets and migrates | Makes `docker compose up` on a fresh clone genuinely one command. It is idempotent, so restarts are cheap. |
| Test database | Real **MySQL 8.4**, in a companion `<DB_DATABASE>_test` database (the `mysql_testing` connection) | Not SQLite: migrations and planning queries get tested against the engine that actually runs them. `tests/TestCase.php` refuses any database whose name doesn't end in `_test`, so a test run can't touch household data. |
| Livewire components | Class-based (`app/Livewire/*.php` + `resources/views/livewire/*.blade.php`) | Livewire 4 defaults to single-file components with a `⚡` in the filename. A plain class is easier to unit-test and keeps filenames ASCII. Set in `config/livewire.php`. |
| Tables created | Laravel's own only: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | The session, cache and queue drivers are all database-backed, so these have to exist. **No `users` table and no auth scaffolding** — MM-1 chose no login. No household tables: MM-3 adds those. |
| Slack settings | `SLACK_BOT_USER_OAUTH_TOKEN` and `SLACK_BOT_USER_DEFAULT_CHANNEL` in `.env`, read by `config/services.php` | Laravel's own Slack notification channel already expects these names, so MM-9 can use it without rewiring. Placeholders only in `.env.example`. |
| Time zone | `APP_TIMEZONE` in `.env` sets Laravel's zone and both containers' clocks | One knob. `config/app.php` shipped with `'UTC'` hard-coded; MM-2 made it read the env var. |
| Scripts | A `Makefile` of thin wrappers, plus the same checks as `composer test` / `lint` / `format` / `build` inside the container | The README lists the plain `docker compose` command beside each target, so nothing depends on `make`. |

### Data model (decided in MM-3)

The tables, the models and the starting settings exist. Other tickets read and write
through Eloquent models in `app/Models`, and every entity has a factory.

- **Times are stored in UTC; the household time zone is applied on the way out.**
  `config('app.timezone')` is fixed at `UTC` and is deliberately *not* read from the
  environment, so every timestamp in MySQL is an absolute instant.

  This **changes what MM-2 recorded.** MM-2 set `APP_TIMEZONE=America/Toronto`, which
  made Eloquent write local wall-clock times into timestamp columns — two rows an
  hour apart can compare equal across a DST change, and the stored value means
  nothing without knowing the zone that wrote it. The household's time zone is now
  the `time_zone` **setting** instead, which is also where AGENTS.md says household
  values belong.

  What this means for later tickets:
  - **Displaying a time** applies `HouseholdSettings::timeZone()`. A page that prints
    a raw timestamp shows UTC and will be wrong by the offset.
  - **The scheduler** already fires on the household clock: Laravel reads
    `app.schedule_timezone`, `config/app.php` seeds it from `HOUSEHOLD_TIMEZONE` in
    `.env`, and `AppServiceProvider` replaces that with the `time_zone` setting. So
    `->dailyAt('15:00')` in MM-13 means 3:00 pm in the kitchen, with no extra work.
  - **A night is a calendar date, not an instant.** Plan nights, shopping dates and
    meal history are `date` columns, so they cannot slide a day when a zone is
    applied. `HouseholdSettings::at('18:00', $date)` turns a stored time plus a date
    into a real moment.

- **Settings are one row per value, with the value as JSON.** A setting can be a
  string, a number or a structure (the two shopping windows) without a migration per
  shape. Read through `App\Settings\HouseholdSettings`, which holds each setting's
  default in one place and returns typed values. Note that MySQL normalises JSON
  object key order, so don't depend on it.

- **The weekly cook schedule is seven rows,** in `cook_schedule_nights`, not a
  setting — each night is read and edited on its own. A plan night *copies* its cook
  and time from the schedule rather than joining to it, so editing the normal week
  never rewrites a plan that is already out. The one-off override for a single date
  lives on the plan night.

- **Enum columns are strings cast to PHP enums** (`app/Enums`), not MySQL `ENUM`s, so
  adding a case doesn't need a migration. `EffortTier` deliberately serves both sides
  of the picker's match: on a recipe it is the effort demanded, on a night it is the
  time available.

- **Ingredients are one polymorphic table** shared by recipes and kitchen projects,
  because the grocery list combines both and merging them is then one query. The
  trade-off: polymorphic relations carry no foreign key, so the two parent models
  delete their ingredients in a model event rather than relying on MySQL to cascade.

- **Store sections are free text,** not an enum: they depend on the household's store,
  which makes them data rather than code.

- **The grocery checklist is stored, not recomputed.** Both phones have to see the
  same ticks, and a re-roll must not rewrite the list someone is standing in the shop
  with.

- **The starting settings are seeded on every boot, and only ever added.**
  `HouseholdDefaultsSeeder` runs from the container entrypoint after `migrate` and
  uses `firstOrCreate`, so a value the household has changed survives a restart.
  `ExampleDataSeeder` holds the sample recipes from `AppPlan.md`; it is for
  development, and is not the household's data (MM-5 loads that).

### What MM-4 added (the Settings page)

Every value above is now editable at `/settings`, as one Livewire form
(`app/Livewire/Settings.php`). Other tickets keep reading through
`HouseholdSettings` and `CookScheduleNight` and need no awareness of the page.

- **The two cooks have names.** `cook_names` is a new setting: one structure keyed
  by `CookAssignment` value, starting as the placeholders "Me" and "Partner".
  Anything that shows a cook to a person goes through
  `HouseholdSettings::cookLabel()`, which returns the household's name for `me` and
  `partner` and the enum's own label for `together` and `nobody`, because those two
  are not people. Renaming a cook in Settings therefore renames them on the plan
  (MM-8), in the Slack posts (MM-9) and on the dashboard (MM-14).

- **The page writes both stores in one transaction** — the `settings` rows and the
  seven `cook_schedule_nights` rows — so a half-applied week is not a state the
  household can land in. A save that fails validation writes nothing at all.

- **Times are normalised to `"HH:MM"` before they are stored** (`App\Settings\TimeOfDay`).
  A typed "9:00" or a browser's "17:30:00" would each be read differently by
  `HouseholdSettings::at()` and by the scheduler, so there is one stored shape and
  one place that enforces it.

- **The prep block stays a single weekly block,** as MM-3 shaped it: one weekday
  plus a start and an end, validated to end after it starts. A household that wants
  two prep blocks in a week needs the setting to become a list, like
  `shopping_times` — a data-shape change other tickets read, so it belongs in its
  own ticket rather than in the page.

- **What validation refuses:** no shopping day at all (every plan window is derived
  from a shop), the same shopping day listed twice, a time that is not a time, a
  prep block that ends before it starts, a time zone PHP does not know, an unnamed
  cook, and a raw-split fridge life outside 1–14 days. A low-stash warning of `0`
  is allowed, and means "never warn".

- **Changing a setting only affects the next plan.** A plan copies its cook and its
  time budget when it is made (above), so this needed no new mechanism — but it is
  the promise the page makes in writing, and `SettingsPageTest` pins it down.

### What MM-6 added (the meal picker)

The picker is in `app/Planning`. Every rule is a class that can be constructed with
plain values and called with no database and no framework boot, so the parts that
will be "wrong in a way nobody notices until dinner" are unit-tested on their own
(AGENTS.md). `MealPlanner` is the one door other tickets use; the classes behind it
(`ShoppingCalendar`, `WeeklyCookSchedule`, `NightBrief`, `NightPicker`,
`WindowPicker`, `MealPool`, `SeededRandom`) are where the rules actually live.

- **`MealPlanner` is the API the rest of the app calls.** MM-7, MM-8, MM-9, MM-11,
  MM-12 and MM-14 should go through it rather than re-deriving any of this:
  `nextWindow()`, `currentWindow()`, `windowFor()`, `planFor()`, `planWindow()`,
  `previewWindow()`, `picksFor()`, `rerollNight()`, `lockNight()`, `unlockNight()`,
  `chooseMeal()`, `setOverride()`, `clearOverride()` and
  `recordPastNightsAsCooked()`. A `Pick` is what a page or a message renders: the
  night, what was chosen, and why — `describe()` for the dinner,
  `explain(HouseholdSettings::cookLabel(...))` for the words underneath it.

- **A window is a span of calendar dates, and the shop's time is not part of it.**
  `ShoppingCalendar` derives "the next day through the next shopping day,
  inclusive" from the household's shopping **days** only. With the defaults, a
  Sunday shop covers Mon–Wed and a Wednesday shop covers Thu–Sun. A shop never
  covers the night of the shop before it, and any date produces a sensible window,
  so an unplanned mid-week shop still works. MM-9 needs the shopping *time* to know
  when to post; nothing about which nights a shop feeds depends on it.

  There is **no weekday name anywhere in the picker.** Change the setting to Tuesday
  and Friday and the windows follow.

- **The shopping days come back in weekday order, Monday first (MM-16).**
  `HouseholdSettings::shoppingTimes()` sorts by `Weekday::isoNumber()`, the same
  order `CookScheduleNight::week()` comes back in, so the app has one notion of
  week order. The order is settled on **read**, not on save: the Settings page
  appends a new trip where the household added it, and the rows already stored are
  unsorted — the starting defaults themselves are stored Sunday-then-Wednesday —
  so reading in order fixes what is in the database today and the stored shape,
  a list of `['weekday' => ..., 'time' => ...]`, needed no migration. Readers can
  rely on it rather than re-sorting: MM-6 derives each window from this list, MM-9
  and MM-13 "the evening before the shop". `ShoppingCalendar` still sorts the ISO
  day numbers it is constructed with, because a caller can hand it any list.

- **One night has one dinner, across every plan there is.** Changing the shopping
  days moves where the windows fall, so the plan already out can still claim
  nights that now belong to the window being planned. `planWindow()` takes those
  nights off the older plan — the shop that will buy the food wins — narrowing that
  plan's stored window to what it has left and deleting it outright if nothing is
  left. A night that has already gone by stays with the plan that fed it.

  **The night itself moves; it is not thrown away and built again.** The lock, the
  one-off override, the dinner and the freezer portion it holds belong to the
  night, not to the shop that paid for it — a lock survives a re-plan, so it
  survives the shopping days moving under it. The only night given up is one the
  new plan already has a row for, and then it is the duplicate that goes, with its
  portion handed back to the stash.

  MM-9's daily reminder and MM-14's dashboard can therefore read
  `plan_nights.date` and expect one row. Before this they could get two dinners
  for one night with no way to choose, and `recordPastNightsAsCooked()` wrote a
  history row for each.

- **Three more settings, not constants.** `variety_lookback_days` (21),
  `partner_solo_effort_cap` (15-min chaos) and
  `freezer_meals_on_chaos_nights_only` (true). `PlanningRules::fromSettings()`
  reads them, and the picker takes them as plain values so a rule can be tested at
  a setting the household hasn't chosen. The Settings page (MM-4) can expose them
  whenever the household wants to turn a dial; nothing breaks until it does.

- **A solo night narrows the time available, never widens it.** A 60-minute
  Saturday that my partner is on her own for is still a 15-minute night, so she
  gets her quick meals or a freezer portion. That is `partner_solo_effort_cap`
  doing the narrowing, not a rule about Saturdays.

- **The freezer stash is counted, and a reservation says so itself.** A planned
  freezer meal holds one portion for as long as it is planned; a re-roll or a
  hand-picked replacement releases it. The holding is recorded on the night
  (`plan_nights.holds_freezer_portion`), not inferred from "the selection is a
  freezer item", and that flag is what a release is conditional on. So releasing a
  night twice, releasing a row a factory or an import wrote, or releasing a night
  that never reserved cannot move the count. `MealPlanner` is the only thing that
  takes a portion out; `PlanNight::releaseFreezerPortion()` the only thing that
  puts one back.

  `MealPool` is the ledger *within one roll* — it is what stops two nights of the
  same window spending the last portion — but it is in memory and lives only as
  long as the call. The durable count is `freezer_items.portions_left` plus the
  flag on the night.

  **Deleting a plan releases what its nights were holding**, in a `deleting` event
  on `Plan`. It has to be the parent: `plan_nights.plan_id` cascades in MySQL, so
  no `deleting` event on `PlanNight` can ever fire. A night that has gone by is
  not released, there or anywhere — `PlanNight::hasPassed()` is the one definition
  of the fence, and a portion on a night that has passed was eaten.

- **"Nothing fits" is a stored outcome, not a failure.** `NightSelection` gained a
  `NothingFits` case, and the rest of the plan still goes ahead. Nobody nights are
  not a failure either: they get no recipe on purpose.

  **A night nothing fitted is tried again on the next plain plan run**
  (`PlanNight::needsPicking()`). The reason it carries is advice — "add a quick
  recipe she likes", "restock the freezer" — so taking the advice has to be
  enough; before this only `--replan` filled the night, and that re-rolled every
  other unlocked night with it. The dead end is not a decision worth keeping,
  because what made it a dead end is exactly what the household has just changed.
  Nights that did fit are untouched, and a locked dead end stays as it is.

  `PlanNight::isPlanned()` still counts "nothing fits" as planned: it is the
  question "has the picker had a go", which is how a page tells "the rules ruled
  everything out" from "nobody has planned this yet".

  **The reason is stored as counts and worded on the way out.**
  `plan_nights.pick_reason` holds what the picker rejected and why
  (`App\Planning\NothingFitsReason`); `Pick::explain($cookLabel)` turns it into a
  sentence. It has to work that way round, because wording the reason means naming
  the cook, and the household's name for a cook is a setting they can change
  (MM-4) — a sentence written at pick time would still read "Partner" after she
  had been renamed, in a row nothing could repair. Keeping the cook as an enum
  also keeps `NightPicker` out of the database, which is what lets every rule be
  unit-tested.

  So **anything showing a reason to a person passes `HouseholdSettings::cookLabel(...)`**
  to `Pick::explain()` — MM-8's plan page, MM-9's post, `milly:plan`.
  `plan_nights.pick_note` is the planner's own words about the night ("Kept Chili:
  nothing else fits."), has no cook's name in it, and `explain()` returns it with
  the reason.

- **A re-plan keeps locked nights.** `planWindow(..., replan: true)` rolls every
  night that isn't locked and leaves the locked ones exactly as they are, which is
  what makes the plan page's lock worth having. A lock outranks the picking rules
  too: an override that narrows a locked night — my 60-minute Saturday becoming my
  partner's solo night — keeps the dinner the household asked for.

  **Unless the override leaves nobody cooking.** Then the meal goes and the
  freezer portion comes back, lock and all: a lock says "keep this dinner" and
  `nobody` says there isn't one, and the household typed the second one just now.
  The alternative was refusing the override, which would mean unlocking a night
  before being allowed to say you are eating out, and would leave MM-8, MM-9 and
  MM-14 rendering "Nobody cooking" against a dinner while a portion stayed held
  for a meal nobody would eat. The lock itself stands: a settled night off is
  still settled.

- **A night that has gone by is history, and the planner will not touch it.**
  "Until you shop, you can re-roll, lock or swap any night" — and only until then.
  The rule is `CalendarDate::hasPassed()`, asked of a row as
  `PlanNight::hasPassed()`, and it holds on every path:

  - `rerollNight()` and `chooseMeal()` refuse (`PlanningException::pastNight`);
  - `planWindow()` never rolls one, even with `replan: true` or a
    `--shopping-date` pointing at a finished window;
  - its cook and time are never refreshed from the week as it is now — the plan is
    the record of who actually cooked, and the dinner was chosen for that cook;
  - it is never invented. A window that starts in the past (which is what
    `currentWindow()` returns) would otherwise mint a second row for a night
    another plan is already the record of, and nothing could ever fill it;
  - it is never pruned when the shopping days shorten the window, and never
    claimed by another plan. A shorter window does not un-happen a night that was
    cooked, and the plan is the only record that it was;
  - its freezer portion is never handed back, wherever the release comes from.

  Tonight is not the past: the fence falls at midnight on the household's clock,
  so they can still change their mind at 8pm in Toronto where the server already
  says tomorrow.

  Two things depended on this and had nothing enforcing it. Re-rolling a past
  freezer night put a portion back in the stash that was eaten days ago. And
  re-planning a finished window gave its nights new dinners, after which
  `recordPastNightsAsCooked()` recorded both the meal the household ate and the
  one the re-plan invented — three dinners becoming six recipes suppressed for the
  whole lookback.

- **The seed is an argument, not a stored column.** The same seed gives the same
  plan, which is how the tests pin the rules down. Plans deliberately don't
  remember a seed: a re-roll the household asks for should be a genuinely fresh
  roll, not a repeat.

- **Variety is a weighted draw, not a ban.** Every eligible meal gets at least one
  ticket, plus one more for each day since it was last cooked, capped at
  `variety_lookback_days`; a meal never cooked gets the maximum. So a vault the
  household cooked straight through yesterday still produces dinner. **MM-11's
  bulk-buy pairing is a preference and has to work with this**, by leaning on the
  draw rather than overriding it.

  "No meal twice in one window" is the hard rule; being cooked recently is only a
  thumb on the scale.

- **Meal history is written by `recordPastNightsAsCooked()`, not by planning.**
  Planning a night is not evidence anybody cooked it, so history is recorded once
  the night has passed — which is what the variety weighting reads.

  Its argument is an **instant**, read as the date the household is living in, the
  same as every other "now" the planner takes (`nextWindow()`,
  `currentWindow()`). Only a date-shaped argument — `windowFor()`'s shopping date,
  `HouseholdSettings::at()`'s date — is read without converting. The difference is
  a whole day: 8pm in Toronto is already tomorrow in UTC, so MM-13's scheduled
  catch-up passing `now()` after 7pm would otherwise record tonight's dinner as
  cooked before anybody ate it.

- **`php artisan milly:plan`** prints the next window from the current data, with
  `--seed`, `--replan`, `--dry-run` and `--shopping-date`. It is the front door
  until MM-8 and MM-9 exist, and after that it is still how you see what the rules
  did and why a night came out empty. Anything it shows a person goes through
  `HouseholdSettings::cookLabel()`.

- **Still out:** bulk-buy pairing (MM-11) and kitchen projects (MM-12) both change
  the picker, and MM-11's ticket says not to run the two at once.

### What MM-7 added (the grocery list)

The list is in `app/Grocery`. As with the picker, the rules are plain classes over
plain values — `MergeKey`, `IngredientNeed`, `GroceryLine`, `GroceryMerge`,
`GroceryBasket`, `GrocerySectionOrder`, `GroceryListText` — unit-tested with no
database and no framework boot, because a quietly wrong amount is only found out
at the till (AGENTS.md). `GroceryListBuilder` is the one door that touches the
database.

- **`GroceryListBuilder` is the API the rest of the app calls.** MM-8, MM-9,
  MM-11, MM-12 and MM-14 should go through it: **`currentFor()` to show the list
  to somebody**, `textFor()` for the same list as plain text, `listFor()` for the
  stored list exactly as stored, `isStale()` to ask whether it has fallen behind,
  `rebuild()` to make it again, `previewFor()` for what the window asks for
  without storing anything. A `GroceryList` is what a page or a message renders —
  store sections in order, each holding `GroceryLine`s, with the pantry basics as
  a separate section at the end. `GroceryLine::toArray()` is the structured form;
  `GroceryListText::post()` the plain-text one.

- **A list can fall behind without the plan changing.** The planner makes it again
  whenever a dinner changes, but editing a recipe's ingredients or the kid
  off-ramp inventory touches no plan at all — and MM-15 is adding screens for
  exactly that. So the read a page does is `currentFor()`, which makes the list
  again when `isStale()` says the window asks for something else, keeping every
  tick that still means something and stamping the plan so the
  after-the-shop warning still fires. `isStale()` compares what there is to buy —
  not the ticks — and writes nothing, for a caller that would rather warn than
  rewrite. `listFor()` is still there for "the list exactly as it is stored", and
  a window whose last night has gone by is never stale, because nothing can change
  it any more.

- **Two needs merge when the name and the unit match, and never otherwise.** The
  name is compared with its case and spacing ignored, so "Ground beef", "ground
  beef" and "Ground  beef" are one thing. The unit has to match exactly after the
  same normalising, and **nothing converts between units**: "1 lb" and "500 g"
  stay two lines. A pound is not 500 g, and a conversion table would be the app
  inventing household data — two lines on a list somebody reads anyway is the
  cheaper mistake. **MM-11's split notes and MM-12's project ingredients join the
  same list and inherit this key** rather than defining their own.

  What happens when two needs do merge:

  - **amounts add up** — "1 lb + 1 lb ground beef" is "2 lb", which is the whole
    point (AppPlan.md);
  - **a need with no amount still merges, and adds nothing to the number.** Both
    the amount and the unit are optional on an ingredient, for the things nobody
    measures. A line nobody measured shows **no amount at all** rather than a
    number the app made up — and not the bare unit either, because "Ground beef —
    lb" is not a thing to write on a list. A household that wants "a splash of
    soy" on the list says so in the ingredient's name, where it is a name and not
    arithmetic. The trade-off is a line that mixes a measured and an unmeasured
    need understates, so the tick rule below watches for it;
  - **the first spelling and the first real store section win,** so the earliest
    night of the window decides how a line reads. Two recipes that disagree about
    the aisle still make one line: merging the amount matters more than the aisle,
    and the recipe is the thing to fix;
  - **a line is a pantry basic only if every need says so.** If one meal treats it
    as something to buy, it belongs on the list proper — being wrong in the other
    direction hides a real ingredient in the short section nobody shops from.

- **A night with no recipe buys nothing.** A freezer meal was cooked and frozen
  weeks ago and the portion it holds is the record of that; a night off is
  leftovers, travel or eating out; and a night **nothing fitted** has no meal
  either — it is a real stored state (MM-6), and the household is being asked to
  add a recipe, not to shop for a dinner that does not exist.

- **The kid backup foods go on every list,** whatever is cooking, because that is
  what the off-ramp is (AppPlan.md, section 3). They are added last, so one a
  recipe measures the same way merges into that recipe's line and keeps its real
  section. Usually it can't: the off-ramp is stocked in packs — a bag of nuggets,
  a loaf of bread — and no recipe measures tortillas in bags, while
  `kid_backup_foods.quantity` and `.unit` are both nullable anyway. So the honest
  answer is **two lines that are read together**: every line for one ingredient
  takes the first real store section any of them gave, so a bag of tortillas sits
  next to the eight the tacos want instead of two sections away, where the
  household buys both and ticks one. The same rule covers a pound of beef beside
  500 g of beef, which is the same situation without a kid in it. A kid food
  nothing else wants groups under **`Kid backup`**, since the inventory has no
  store section of its own. The pantry basics are the **`Check you have`**
  section, and anything with no section at all lands in **`Other`**, last of the
  shopping.

- **An aisle is one section however it was typed.** Sections are free text a
  person writes on a recipe, so "Produce" and "produce" are one trip round the
  store and get one heading, with the first spelling the one the household reads —
  the same normalising a line's own name gets. Two headings a few lines apart is
  how the lettuce gets crossed off and the onion under the second one never seen.

- **The order of the sections is a household value, not a sort in code.**
  `store_section_order` is a new setting: the section names in the order they walk
  their store. It starts **empty**, because nobody can guess a store's layout, and
  an empty list means alphabetical — which at least puts the same section in the
  same place every week. Sections they haven't named follow the ones they have.
  Like MM-6's three settings, the Settings page (MM-4) can expose it whenever the
  household wants to turn the dial; nothing breaks until it does.

- **The list is stored, and making it again preserves the ticks that still mean
  something.** MM-3 decided the checklist is stored rather than recomputed — both
  phones see the same ticks, and a re-roll must not rewrite the list somebody is
  standing in the shop with. That sits awkwardly beside "make the list again
  whenever the plan changes", so a rebuild **reconciles** line by line instead of
  replacing:

  - a line that is **still needed keeps its row and its tick**, even when the
    meals behind it changed. "We have this" does not stop being true because
    Tuesday's dinner did;
  - unless the plan now **asks for more of it** — then the tick goes, because "we
    have enough" was true of the old amount. Where one of the two amounts is
    missing, what settles it is whether another dinner has started wanting the
    line: an amount appearing for the same single meal is a recipe getting more
    specific, but an amount appearing alongside a **second meal** is a bigger shop
    hiding behind a tick. That case is the intersection of two rules that are each
    fine alone — an unmeasured need adds nothing to the total, and an amount is
    only compared with another amount — and it would have read "1 lb", crossed
    off, for two dinners' worth of beef;
  - a line **nothing wants any more is taken off**, ticked or not. It is not part
    of this shop;
  - a line **nothing had asked for before is added, unticked**, so the thing the
    re-roll means you still have to buy is the thing that stands out.

  A line's identity through all of that is its merge key, so changing a recipe's
  unit is a new line and a new tick — correctly, because "we have 1 lb" says
  nothing about 500 g.

- **`plans.grocery_list_changed_at` is stamped only when a line really moved,** so
  it means "the list changed then" and not "somebody looked at it then". With
  `Plan::shoppingMoment()` — the plan's shopping date at the household's time for
  that weekday — it answers `Plan::groceryListChangedAfterShopping()`, which is
  **the warning MM-8 owes the fridge**: the list someone carried round the shop is
  no longer the list the plan asks for. It says false when the household no longer
  shops that weekday, because "we can't tell" is not "it changed". Each row's own
  `created_at` says when that line appeared, for a page that wants to point at the
  ones added since.

- **A window whose last night has gone by is left exactly as it was shopped.** The
  planner will not change a night that has passed either, so there is nothing for
  the list to be out of step with, and rewriting it would only damage the record of
  what was actually bought. `Plan::hasPassed()` is that fence, next to
  `PlanNight::hasPassed()`.

- **`MealPlanner` keeps the list in step, so no caller has to remember.**
  `planWindow()`, `rerollNight()`, `chooseMeal()`, `setOverride()` and
  `clearOverride()` each make the list again inside their own transaction —
  including for a plan that just gave nights up to the window being planned, which
  is now buying for one night fewer. A rebuild is idempotent, so a change that left
  the meals alone (a note-only override, a lock) writes nothing and stamps nothing.
  Model events on `PlanNight` would have been the tempting way to do this and are
  the wrong one: the planner saves a night twice while rolling it — released, then
  filled — and a rebuild in the gap would delete a line and add it back unticked,
  throwing away exactly the ticks this is meant to protect.

- **Two columns were added.** `plan_grocery_items.meals` (JSON) holds which dinners
  want a line, in night order: "show which meals use each item" is part of the
  stored list rather than a view over the plan, so a ticked line cannot be
  re-labelled by a re-roll and MM-9 can render a line of text without joining the
  plan back in. `plans.grocery_list_changed_at` is above.

- **`php artisan milly:groceries`** prints a window's list — grouped, ticks shown,
  with `--shopping-date`, `--rebuild` and `--text` (exactly what MM-9 will post).
  It is the front door until MM-8 exists, and it is how you see what the merge did.
  Anything it shows a person goes through `HouseholdSettings::cookLabel()`.

- **Deliberately not here:** pantry tracking (the household crosses items off by
  hand — AppPlan.md is explicit), bulk-buy split notes (MM-11), and a due kitchen
  project's ingredients (MM-12). The ingredients table is polymorphic precisely so
  MM-12 can add a project's needs to the same merge; MM-7 reads the recipe side
  only, because deciding when a project is due is MM-12's job.

### Open, not blocking

- **Reaching the app away from home.** Not decided. Today the answer is "you can't". If the household wants the plan page or Slack links to work on mobile data, the options are something like Tailscale (and then a login, because the app would be reachable from more places) or leaving it home-only. MM-10 can raise this again.
- **Which machine at home,** and keeping it awake and on a wired or stable connection. MM-10 needs the host before it deploys.

## Proposed defaults (the household can change these)

- **Weekly cook schedule:** for each night, Settings say who cooks (me, partner, together or nobody) and how much time there is (15, 30 or 60+ min). A single date can be changed on the plan page (MM-8), for example for travel, leftovers or eating out.
- **Shopping windows:** a shopping day covers every night from the next day through the next shopping day. With the defaults, Sunday covers Mon–Wed and Wednesday covers Thu–Sun.
- **Plan review:** the plan arrives the evening before shopping. Until you shop, you can re-roll, lock or swap any night.
- **Partner solo nights:** only her 15-min recipes (tagged partner or both), or a freezer meal.
- **Raw split:** pair meals that share a bulk ingredient. The second meal must fall within that ingredient's fridge life, counted from the shopping day. If it can't, the plan says "freeze the second half".
- **Variety:** no recipe twice in one window. Favor recipes that haven't been cooked recently.
- **Freezer stash:** this is the only thing we count.
  - A finished project adds portions.
  - A planned freezer meal uses one portion.
  - A thaw reminder goes out the night before.
- **No pantry tracking:** the grocery list shows everything the window needs, and the household crosses items off at the fridge. Pantry basics go in a short "check you have" section.
- **Ingredients have amounts and units,** so the list can merge them: "1 lb + 1 lb ground beef" becomes "2 lb".

## Starting settings (rough; change them in Settings)

| Setting | Default |
|---|---|
| Cook names | "Me" and "Partner" (placeholders until the household types its own) |
| Time zone | America/Toronto |
| Shopping | Sunday 10:00 am, Wednesday 5:30 pm |
| Prep block | Monday 8:00–9:00 pm |
| Dinner time | 6:00 pm |
| Who cooks | Every night: together, 30 min (until the household sets its week) |
| Plan post | 7:00 pm the evening before each shopping day |
| Daily dinner reminder | 3:00 pm |
| Raw-split fridge life | 2 days |
| Low freezer stash warning | 2 portions (used by MM-12) |

## Parking lot (ideas, not tickets yet)

- Buttons in the chat: "we ate out", "cooked it", and re-roll a night.
- "Did the kids eat it?" feedback, to improve the kid off-ramps.
- Import recipes from links.
- Cooking stats, for example "we haven't made chili in 6 weeks".
