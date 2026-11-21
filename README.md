# MealPlanner Milly

A kitchen logistics and handoff system for one household: it plans the meals for
each shopping window, says who is cooking and what the kids will eat instead, and
posts the grocery list and the reminders to Slack.

Read **[`AppPlan.md`](AppPlan.md)** for the problem it solves and
**[`docs/decisions.md`](docs/decisions.md)** for the decisions every part of it
rests on. Conventions for changing the code are in **[`AGENTS.md`](AGENTS.md)**.

> **This app has no login.** It is meant to run on one machine at home and be
> reachable only on the home network. Don't port-forward it or put it on a public
> hostname. See `docs/decisions.md`.

## What's here so far

The working skeleton: the app boots, the database connects, the shell renders, and
the checks pass. The **Plan** and **Settings** pages are deliberately empty
placeholders — MM-4 fills in Settings, MM-8 fills in Plan. There are no household
tables yet; MM-3 adds those.

## What you need

Docker, and nothing else. PHP, Composer, Node and npm all live inside the
containers, so the host never needs a toolchain.

- Docker 29+ with Compose v2.24+ (tested on Docker 29.5.3, Compose v5.1.4)

## Setup

On a fresh clone:

```sh
git clone <this repo> mealplanner-milly
cd mealplanner-milly
cp .env.example .env
docker compose up
```

That's it. The first boot takes a few minutes: the `app` container installs
Composer and npm dependencies, generates `APP_KEY`, builds the front-end assets,
waits for MySQL and runs the migrations. Watch it happen in the log.

When you see `ready on http://localhost:8000`, open it. You should get the Plan
page, with working navigation to Settings and back.

> On **Linux** hosts, set `APP_UID` and `APP_GID` in `.env` to your own `id -u` and
> `id -g` first, so the files the container writes stay editable. Docker Desktop on
> macOS ignores them.

If you change `DB_DATABASE`, `DB_USERNAME` or `DB_PASSWORD`, do it **before** the
first boot. MySQL only reads them when its data volume is new; otherwise run
`docker compose down -v` and start over.

## Commands

Everything runs inside the containers. `make <target>` is a wrapper — the plain
`docker compose` command is listed beside it, and `make` on its own lists them all.

| Task | Make | The command it runs |
| --- | --- | --- |
| **dev** — start app, db and scheduler | `make dev` | `docker compose up` |
| **test** — run the Pest suite | `make test` | `docker compose exec app php artisan test` |
| **lint** — check formatting | `make lint` | `docker compose exec app ./vendor/bin/pint --test` |
| **build** — build front-end assets | `make build` | `docker compose exec app npm run build` |
| install dependencies | `make install` | `docker compose exec app composer install` and `… npm ci` |
| fix formatting | `make format` | `docker compose exec app ./vendor/bin/pint` |
| all three checks | `make check` | lint, then test, then build |
| start in the background | `make up` | `docker compose up -d` |
| stop (keeps the data) | `make down` | `docker compose down` |
| follow the logs | `make logs` | `docker compose logs -f` |
| run migrations | `make migrate` | `docker compose exec app php artisan migrate` |
| drop and re-migrate | `make fresh` | `docker compose exec app php artisan migrate:fresh` |
| a shell in the app container | `make shell` | `docker compose exec app bash` |
| a MySQL prompt | `make db` | `docker compose exec db mysql …` |
| rebuild assets on change | `make watch` | `docker compose exec app npm run watch` |

`make test`, `make lint` and `make build` use `docker compose exec`, so start the
stack first (`make up`). Inside the container the same checks are available as
`composer test`, `composer lint`, `composer format` and `composer build`.

Front-end assets are built once on first boot, so nothing needs to be running for
the app to work. While editing CSS or JS, `make watch` rebuilds them on save —
refresh the browser to pick them up. There is no Vite dev server and no extra
published port, so the stack doesn't collide with other projects.

## How it's wired

Three services in [`compose.yaml`](compose.yaml):

| Service | Image | What it does |
| --- | --- | --- |
| `app` | built from `Dockerfile` — PHP 8.4, Apache, Node 22 | Serves the web UI on `http://localhost:8000`, and is where every command runs |
| `db` | `mysql:8.4` | The database. Data lives in the named volume `db_data` |
| `scheduler` | the same image as `app` | Runs `php artisan schedule:work` in the household time zone |

`app` and `scheduler` reach the database at the hostname **`db`** over the Docker
network. MySQL's port is deliberately not published to the host: use `make db`.

The web port binds to all interfaces, so phones and the kitchen tablet on the home
LAN can reach it — which is also why it must not be exposed beyond the LAN.

The project directory is bind-mounted into both PHP containers, so an edit on the
host shows up on the next request. `node_modules` is the exception: it lives in a
named volume, because Vite's native binaries differ between macOS and the container.

### The scheduler and time

The app keeps two clocks apart:

- **It computes and stores in UTC.** `config('app.timezone')` is fixed at `UTC` and
  is deliberately not read from the environment, so every timestamp in MySQL is an
  absolute instant. A `.env` that disagreed with the data would quietly
  reinterpret every row already stored.
- **The household's own time zone is a setting.** The `time_zone` row in `settings`
  (default `America/Toronto`) is what pages render times in, and what the scheduler
  fires on, so "7:00 pm the evening before shopping" means 7:00 pm in the kitchen.
  It is editable on the Settings page (MM-4).

Laravel reads `app.schedule_timezone` when it builds the schedule. `config/app.php`
seeds that from `HOUSEHOLD_TIMEZONE` in `.env` — the fallback for before the
database can be read — and `AppServiceProvider` then replaces it with the
`time_zone` setting, so changing the time zone in Settings moves the reminders with
it. `HOUSEHOLD_TIMEZONE` also sets the containers' clocks, so logs read locally.

Dates that are *calendar* dates — a plan's nights, a shopping date, meal history —
are `date` columns, not timestamps, so they cannot slide a day when a zone is
applied. `HouseholdSettings::at('18:00', $date)` turns a stored time plus a date
into a real moment in the household's zone.

Scheduled work is declared in `routes/console.php`; MM-9 and MM-13 add the real jobs.

### The database

`php artisan migrate` runs on every boot of `app`, followed by
`HouseholdDefaultsSeeder`, which installs the starting settings and the weekly cook
schedule from `docs/decisions.md`. That seeder only ever *adds*, so a value the
household has since changed on the Settings page survives a restart.

Alongside Laravel's own tables (`sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `migrations` — the session, cache and queue drivers
are database-backed) the household's tables are:

| Table | What it holds |
|---|---|
| `settings` | One row per household setting, value as JSON |
| `cook_schedule_nights` | The normal week: seven rows of who cooks and how long |
| `recipes` | The recipe vault |
| `bulk_ingredients`, `bulk_ingredient_recipe` | Packs worth splitting, and which recipes share them |
| `recipe_prep_steps` | Upstream prep, with how many hours ahead it happens |
| `ingredients` | What a recipe **or** a kitchen project needs (polymorphic) |
| `kitchen_projects` | Recurring projects, their cadence and what they produce |
| `freezer_items` | The freezer stash and its portion counts |
| `kid_backup_foods` | The guaranteed-safe backups to keep stocked |
| `plans`, `plan_nights` | One plan per shopping window, and its nights |
| `plan_grocery_items` | The grocery checklist, including what's been ticked |
| `meal_history` | What we actually cooked, and when |

There is no `users` table: there is no login.

`make fresh` rebuilds all of it from scratch. `docker compose exec app php artisan
db:seed` additionally loads `ExampleDataSeeder` — the Bulgogi/tacos/chili sample
set from `AppPlan.md`, useful in development. The household's own recipes are
loaded by MM-5, not by a seeder.

Tests run against a **separate database** on the same server, `<DB_DATABASE>_test`
(so `milly_test` by default), created the first time the `db` volume initialises.
`phpunit.xml` selects it through the `mysql_testing` connection, and
`tests/TestCase.php` refuses to run if the target database name doesn't end in
`_test`. That means the suite exercises real MySQL 8.4 — not SQLite — and can never
touch the household's data.

## Layout

```
app/Livewire/            Livewire page components (Plan, Settings)
config/                  Laravel config; livewire.php, database.php and app.php are tweaked
database/migrations/     Laravel's own tables for now; MM-3 adds the household's
docker/                  Dockerfile support: Apache vhost, php.ini, entrypoint, MySQL init
resources/views/
  layouts/app.blade.php  The shared shell: head, header, nav, footer
  components/            Blade components (navigation, page-heading, placeholder-panel)
  livewire/              The page templates
routes/web.php           / redirects to /plan; /plan and /settings
routes/console.php       Where scheduled work gets declared
tests/Unit/              No framework, no database. Planning logic goes here
tests/Feature/           Boots the app and hits MySQL
compose.yaml             app, db, scheduler
Makefile                 The wrappers listed above
```

## Troubleshooting

**The first boot seems stuck.** It is installing dependencies. `make logs` shows
progress; expect a few minutes.

**`make test` says "no such container".** The stack isn't running. `make up` first.

**`Unknown database 'milly_test'`.** The db volume was created before the init
script existed, or you renamed `DB_DATABASE` afterwards. `docker compose down -v &&
docker compose up` recreates it.

**Vite manifest not found.** Assets were never built. `make build`.

**`Bind for 0.0.0.0:8000 failed: port is already allocated`.** Something else has
port 8000. Set `APP_PORT` in `.env` to a free port and `docker compose up -d` again.

**Changes to `.env` don't take effect.** `docker compose restart app scheduler` —
and remember compose itself reads `.env` for ports and credentials, so changes
there need `docker compose up -d` again.
