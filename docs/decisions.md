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
