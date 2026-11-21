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
