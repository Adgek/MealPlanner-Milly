# How it works inside

[`use-cases.md`](use-cases.md) says *what* the household can do. This page says *how*:
which classes a request passes through, in what order, what each one reads and
writes, and where the rules and the transactions sit. Each section is one part of the
system, in the order the data flows: the runtime, the tables, the settings, the vault,
the planner, the grocery list, and then where the parts that aren't built yet plug in.

Read the diagrams with this key:

- **Solid** boxes and arrows are code that exists and runs today.
- **Dashed grey** boxes are parts that are not built yet, drawn where they will plug
  in, with the ticket that builds them.
- File references are relative to the repository root. `MealPlanner.php:135` means
  `app/Planning/MealPlanner.php`, line 135, as of the commit that added this page.

Two facts shape everything below:

1. **There are exactly two front doors to the planning logic today:** `milly:plan` and
   `milly:groceries`, run in a terminal. The pages edit the data the planner reads;
   no page calls the planner yet (MM-8).
2. **`MealPlanner` and `GroceryListBuilder` are the only classes that touch the
   database for planning.** Everything they call (`NightPicker`, `GroceryMerge`, …)
   is plain PHP with no database, which is why it is unit-tested in `tests/Unit`.

---

## 1. The runtime

Three containers on one machine at home. Nothing is reachable from outside the home
network, and nothing goes out to the internet yet.

```mermaid
flowchart LR
    subgraph lan["Home network"]
        phone["Phone or tablet<br/>browser"]
        term["Terminal<br/>docker compose exec app<br/>php artisan milly:plan"]
    end

    subgraph compose["Docker Compose on the home machine"]
        subgraph app["app container · Apache + PHP 8.4 · port 8000"]
            direction TB
            apache["Apache<br/>DocumentRoot public/"]
            laravel["Laravel<br/>routes/web.php"]
            lw["Full-page Livewire component<br/>app/Livewire/*.php"]
            layout["layouts/app.blade.php<br/>nav + page"]
            cmd["Artisan commands<br/>milly:plan · milly:groceries"]
            apache --> laravel --> lw --> layout
        end
        sched["scheduler container<br/>php artisan schedule:work<br/>runs in the household time zone<br/>nothing is scheduled"]
        db[("db container<br/>MySQL 8.4<br/>volume db_data")]
    end

    slack["Slack<br/>MM-9 · MM-13"]

    phone -- "HTTP on the LAN" --> apache
    term --> cmd
    lw --> db
    cmd --> db
    sched --> db
    sched -. "posts, not built" .-> slack

    classDef notbuilt fill:#f4f4f5,stroke:#71717a,color:#52525b,stroke-dasharray: 4 3
    class slack notbuilt
```

**On boot, the `app` container** (`docker/entrypoint.sh`) does this, in order: copy
`.env.example` to `.env` if missing → `composer install` → generate `APP_KEY` →
`npm ci` and `npm run build` if the assets are missing → wait for MySQL → `migrate` →
seed `HouseholdDefaultsSeeder` (it only *adds* settings, so edits survive a restart) →
start Apache. The **scheduler** waits for the app to be healthy, then runs
`schedule:work`. `routes/console.php` declares no jobs, so it logs "No scheduled
commands are ready to run" every minute.

**A page request**: Apache → `public/index.php` → `routes/web.php`, which maps each
URL straight to a Livewire class (`/recipes` → `App\Livewire\Recipes`) → the
component renders inside `resources/views/layouts/app.blade.php`. There is no login
and no middleware of the app's own. A click on the page posts back to Livewire's
update endpoint, which calls a public method on the same component.

**Clocks**: everything is stored in UTC (`config/app.php`). The household's time zone
is the `time_zone` setting. `AppServiceProvider::scheduleInTheHouseholdTimeZone()`
copies it into `app.schedule_timezone` when a console command boots, so a future
`dailyAt('15:00')` fires at 3 pm in the kitchen.

**Sessions, cache and queue** all use the database driver. There is no queue worker,
and nothing dispatches a job or reads the cache.

---

## 2. The tables

Every household table, and what happens to the rows that point at a row you delete.
The arrow labels say what a delete does: `cascade` deletes the children,
`null on delete` empties the pointer.

```mermaid
erDiagram
    RECIPES ||--o{ RECIPE_PREP_STEPS : "cascade"
    RECIPES ||--o{ BULK_INGREDIENT_RECIPE : "cascade"
    BULK_INGREDIENTS ||--o{ BULK_INGREDIENT_RECIPE : "cascade"
    RECIPES ||--o{ INGREDIENTS : "polymorphic, deleted in code"
    KITCHEN_PROJECTS ||--o{ INGREDIENTS : "polymorphic, deleted in code"
    FREEZER_ITEMS |o--o{ KITCHEN_PROJECTS : "restocked by, null on delete"
    PLANS ||--o{ PLAN_NIGHTS : "cascade"
    PLANS ||--o{ PLAN_GROCERY_ITEMS : "cascade"
    RECIPES |o--o{ PLAN_NIGHTS : "null on delete"
    FREEZER_ITEMS |o--o{ PLAN_NIGHTS : "null on delete"
    RECIPES ||--o{ MEAL_HISTORY : "cascade"

    RECIPES {
        string name UK
        string cookable_by "me, partner, both"
        string effort_tier "15, 30 or 60+ min"
        text kid_off_ramp
        timestamp retired_at "set instead of deleting"
    }
    RECIPE_PREP_STEPS {
        string description
        int lead_time_hours "0 to 168"
        int position
    }
    BULK_INGREDIENTS {
        string name UK
        int fridge_life_days "null means the setting"
    }
    BULK_INGREDIENT_RECIPE {
        bigint recipe_id FK
        bigint bulk_ingredient_id FK
    }
    INGREDIENTS {
        string ingredientable_type "Recipe or KitchenProject"
        bigint ingredientable_id
        string name
        decimal quantity "nullable"
        string unit "nullable"
        string store_section "free text"
        bool pantry_basic
    }
    FREEZER_ITEMS {
        string name UK
        int portions_left "the only thing counted"
        int thaw_lead_time_hours
        text kid_off_ramp
        timestamp retired_at
    }
    KITCHEN_PROJECTS {
        string name UK
        int every_days
        bigint produces_freezer_item_id FK
        int produces_portions
        date last_done_on
    }
    PLANS {
        date shopping_date UK
        date first_night
        date last_night
        timestamp grocery_list_changed_at
    }
    PLAN_NIGHTS {
        bigint plan_id FK
        date date "unique per plan"
        string cook
        string time_budget
        bool overrides_schedule
        string selection "recipe, freezer_item, night_off, nothing_fits"
        bigint recipe_id FK
        bigint freezer_item_id FK
        bool holds_freezer_portion
        bool is_locked
        json pick_reason
    }
    PLAN_GROCERY_ITEMS {
        bigint plan_id FK
        string name
        char merge_key "sha256, unique per plan"
        json meals
        decimal quantity
        bool checked
        timestamp checked_at
    }
    MEAL_HISTORY {
        date cooked_on
        bigint recipe_id FK
    }
    SETTINGS {
        string key UK
        json value
    }
    COOK_SCHEDULE_NIGHTS {
        string weekday UK
        string cook
        string time_budget
    }
    KID_BACKUP_FOODS {
        string name UK
        decimal quantity
        string unit
    }
```

What the shape of these tables decides:

- **A plan night copies its cook and time** from `cook_schedule_nights` rather than
  pointing at it, so editing the normal week never rewrites a plan already made.
- **`ingredients` is one table for two owners.** It has no database foreign key, so
  `Recipe` and `KitchenProject` delete their ingredients in a `deleting` model event.
  It exists so that a kitchen project's needs can join the same grocery merge (MM-12).
- **The deletes are why recipes are retired, not deleted.** Deleting a recipe would
  cascade away its `meal_history` (the variety rule's only input) and null out
  `plan_nights.recipe_id` (a planned night with no dinner). So "remove" sets
  `retired_at`, and a real delete is only allowed when nothing points at the row.
- **The grocery list is stored**, one row per line, so two phones see the same ticks.
  `unique (plan_id, merge_key)` makes the database refuse a second row for one line.
- `settings`, `cook_schedule_nights` and `kid_backup_foods` stand alone. Nothing
  points at them.

---

## 3. Settings

One row per setting in `settings`, the value stored as JSON. Every read goes through
`App\Settings\HouseholdSettings`, which holds every default in one place
(`defaults()`). There is **no cache**: each getter is one `SELECT`, so a change on the
Settings page is seen by the very next command.

```mermaid
flowchart LR
    page["Settings page<br/>/settings<br/>Livewire\Settings"]

    subgraph save["Settings::save()"]
        direction TB
        tidy["tidyInput<br/>trim names, times to HH:MM"]
        val["validate<br/>7 nights, 1 to 7 distinct shopping days,<br/>prep ends after it starts, real time zone"]
        tx["one DB transaction"]
        tidy --> val --> tx
    end

    settings[("settings<br/>13 rows · key + JSON value")]
    week[("cook_schedule_nights<br/>7 rows")]
    hs["HouseholdSettings<br/>typed getters + defaults"]

    page --> save
    tx -- "9 keys via set()" --> settings
    tx -- "saveTheWeek()" --> week
    settings --> hs

    hs --> planner["Planner<br/>shopping days, time zone,<br/>solo cap, lookback, freezer rule"]
    week --> planner
    hs --> grocery["Grocery list<br/>store_section_order, cook names"]
    hs --> render["Anything shown to a person<br/>cookLabel(), the time zone"]
    hs --> provider["AppServiceProvider<br/>scheduler time zone"]
    hs --> unread["dinner_time · plan_post_time ·<br/>daily_dinner_reminder_time · prep_block<br/>read by nothing yet<br/>MM-9 · MM-13"]

    classDef notbuilt fill:#f4f4f5,stroke:#71717a,color:#52525b,stroke-dasharray: 4 3
    class unread notbuilt
```

- **The page edits 9 of the 13 settings.** `variety_lookback_days`,
  `partner_solo_effort_cap`, `freezer_meals_on_chaos_nights_only` and
  `store_section_order` are seeded and read by the engine, and nothing writes them
  (MM-22 puts them on the page).
- **Four settings are written and never read**: the dinner time, the plan-post time,
  the daily reminder time and the prep block. They are waiting for the scheduled jobs
  (MM-9, MM-13).
- **`cookLabel()`** is how a cook becomes a name on screen: the household's name for
  `me` and `partner`, the enum's own label for `together` and `nobody`. Names are
  applied when something is shown, never stored in a plan, so renaming a cook renames
  them everywhere.
- **`shoppingTimes()` sorts Monday-first when read**, not when saved.
- **`at('18:00', $date)`** turns a stored time plus a calendar date into a real moment
  in the household's zone. It is how "after the shop" is worked out.

---

## 4. The vault

The vault pages are forms over the tables in section 2. Two of their flows are worth
drawing: saving a recipe, and taking one out of the vault.

### Saving a recipe

```mermaid
sequenceDiagram
    actor Cook as Me or my partner
    participant Ed as RecipeEditor
    participant DB as MySQL

    Cook->>Ed: Save the recipe
    Ed->>Ed: tidyInput drops empty ingredient and prep rows
    Ed->>Ed: validate. Unique name, amounts above 0 with 2 decimals, the same name and unit not listed twice, lead time 0 to 168 h
    rect rgb(236, 253, 245)
        Note over Ed,DB: one transaction. All of it is saved, or none of it
        Ed->>DB: recipes. Insert or update name, who can cook it, effort, kid off-ramp
        Ed->>DB: bulk_ingredient_recipe. Sync the bulk-pack tags
        Ed->>DB: ingredients. Delete this recipe's rows, insert the form's rows
        Ed->>DB: recipe_prep_steps. Delete, then insert in the order shown
    end
    Ed-->>Cook: Back to /recipes with "Beef Bulgogi is saved."
```

The ingredients and prep steps are **rewritten wholesale** rather than matched row
by row. That is safe because nothing points at an ingredient row by id: the grocery
list copies amounts out of them when it is built.

**The editor never touches the stored grocery list.** An edited recipe makes the
list stale, and the list catches up the next time it is read through the builder
(section 6).

### Taking a recipe or a freezer meal out of the vault

```mermaid
sequenceDiagram
    actor Cook as Me or my partner
    participant Page as Recipes or Kitchen page
    participant MU as MealUsage
    participant Ret as Retirement
    participant MP as MealPlanner
    participant DB as MySQL

    Cook->>Page: Remove
    Page->>MU: of(meal)
    MU->>DB: plan nights that use it, times cooked, projects that restock it
    MU-->>Page: forecast. Which nights get re-picked, which locked night keeps it, what the stash will count
    Page-->>Cook: warning, before anything is written
    Cook->>Page: Take it out of the vault
    Page->>Ret: retire(meal)
    rect rgb(236, 253, 245)
        Note over Ret,DB: one transaction
        Ret->>DB: set retired_at first, so the re-pick cannot choose it again
        loop each night that has not passed and is not locked
            Ret->>MP: rerollNight(night)
            MP->>DB: release the night. A held freezer portion goes back to the stash
            MP->>DB: pick again from the vault without it, or keep it with "nothing else fits"
            MP->>DB: rebuild that plan's grocery list
        end
    end
    Ret-->>Page: Removal. What actually changed, night by night
    Page-->>Cook: outcome banner
```

- **Forecast and outcome are separate objects on purpose.** `MealUsage` predicts;
  `Removal` reports what really happened by comparing each night before and after.
  They differ when nothing else fits a night and the planner keeps the meal.
- **Locked nights and nights that have passed are never re-picked.** A lock is the
  household's answer for that date, and a past night is history.
- **"Delete for good"** goes through `Retirement::deleteForGood()`, which locks the
  row (`SELECT … FOR UPDATE`), checks again inside the transaction that nothing
  points at it, and only then deletes. A page left open while a plan was made cannot
  destroy anything.
- **`MealUsage`, `Removal` and `UsedNight` are pure values** apart from
  `MealUsage::of()`, so the wording of every warning is unit-tested without a
  database.

---

## 5. Planning a window

`MealPlanner` is the one class the rest of the app is meant to call. It reads
settings and the vault, turns them into plain values, hands them to the picking
classes, and writes the result. The picking classes never see the database.

```mermaid
flowchart LR
    subgraph db["Reads and writes the database"]
        direction TB
        MP["MealPlanner"]
        GB["GroceryListBuilder"]
    end
    subgraph pure["Plain PHP, unit-tested, no database"]
        direction TB
        SC["ShoppingCalendar<br/>which nights a shop feeds"]
        WCS["WeeklyCookSchedule<br/>who cooks, how long"]
        WP["WindowPicker<br/>nights in date order"]
        NP["NightPicker<br/>every rule for one night"]
        PR["PlanningRules<br/>solo cap, lookback, freezer rule"]
        MPool["MealPool<br/>used meals, reserved portions"]
        SR["SeededRandom<br/>the weighted draw"]
        Pick["Pick + NothingFitsReason<br/>the result, and why"]
    end
    MP --> SC
    MP --> WCS
    MP --> WP --> NP
    NP --> PR
    NP --> MPool
    NP --> SR
    NP --> Pick
    MP --> GB
```

### `milly:plan`, step by step

```mermaid
sequenceDiagram
    participant C as milly:plan
    participant MP as MealPlanner
    participant SC as ShoppingCalendar
    participant WP as WindowPicker and NightPicker
    participant GB as GroceryListBuilder
    participant DB as MySQL

    C->>MP: nextWindow()
    MP->>DB: settings. shopping_times, time_zone
    MP->>SC: nextWindow(today in the kitchen)
    SC-->>MP: the shop on or after today, and the nights up to the next shop
    C->>MP: planWindow(window, seed, replan)
    MP->>DB: meal_history. Record every past night's recipe as cooked
    Note over MP,DB: this runs before the transaction opens (MM-21)
    rect rgb(236, 253, 245)
        Note over MP,DB: DB transaction
        MP->>DB: plans. Find or create by shopping date, set first and last night
        MP->>DB: plan_nights. Take over this window's nights from any older plan
        MP->>DB: plan_nights. One row per night, cook and time copied from cook_schedule_nights
        MP->>MP: choose which nights to roll. Not locked, not passed, and empty or replan
        MP->>DB: release those nights. A held freezer portion goes back
        MP->>DB: MealPool.fromVault. Recipes and freezer meals not retired, last cooked dates
        MP->>WP: pick(briefs, pool, SeededRandom, kept nights)
        WP-->>MP: one Pick per night
        MP->>DB: plan_nights. Write the dinner, note and reason. Freezer pick locks the item and spends a portion
        MP->>GB: rebuild(plan), and every plan that gave nights up
        GB->>DB: plan_grocery_items. Reconcile (section 6)
    end
    MP-->>C: the plan and its nights
    C->>C: print the table. Cook names through cookLabel, reasons through Pick.explain
```

- **Everything except the history catch-up is one transaction.** If a freezer
  portion runs out halfway (`PlanningException::noPortionsLeft`), the whole plan
  rolls back. The catch-up is outside it, which is one of MM-21's two complaints.
- **`--dry-run` runs exactly the same code** inside a transaction it always rolls
  back (`previewWindow()`), so the preview can never disagree with the real thing.
- **The seed is not stored.** `--seed=42` makes a roll repeatable for tests and demos;
  a normal roll is fresh every time.
- **Nights that have passed are history**: never invented, never re-rolled, never
  moved to another plan, never given new cooks, and their freezer portion is never
  handed back.

### How one night is picked

`NightPicker::pick()`. Everything it needs is passed in, so every branch here has a
unit test.

```mermaid
flowchart TD
    start(["A night to fill:<br/>date, cook, time budget"]) --> who{"Is anybody cooking?"}
    who -- "nobody" --> off["Night off<br/>no meal, nothing to buy"]
    who -- "me, partner or together" --> time["Time available<br/>= the night's budget,<br/>capped at partner_solo_effort_cap<br/>when my partner cooks alone"]

    time --> rec["For each recipe in the vault"]
    rec --> r1{"Can this cook make it?<br/>me ✗ partner-only,<br/>partner ✗ me-only,<br/>together ✓ all"}
    r1 -- no --> c1["count: ruled out by cook"]
    r1 -- yes --> r2{"Effort fits<br/>the time available?"}
    r2 -- no --> c2["count: ruled out by time"]
    r2 -- yes --> r3{"Already used<br/>this window?"}
    r3 -- yes --> c3["count: already taken"]
    r3 -- no --> cand["Candidate"]

    time --> fz{"Freezer allowed tonight?<br/>only at 15 min or less<br/>while the chaos-only rule is on"}
    fz -- no --> nofz["No freezer candidates"]
    fz -- yes --> f1{"A portion left,<br/>counting this roll's reservations?"}
    f1 -- no --> c4["count: freezer empty"]
    f1 -- yes --> f2{"Already used<br/>this window?"}
    f2 -- yes --> c5["count: freezer taken"]
    f2 -- no --> cand

    cand --> any{"Any candidates?"}
    c1 & c2 & c3 & c4 & c5 & nofz --> any
    any -- no --> nf["Nothing fits<br/>the counts are stored as pick_reason<br/>and worded later with the cook's name"]
    any -- yes --> w["Weight each candidate<br/>1 + days since last cooked, up to the lookback<br/>never cooked or a freezer meal = the maximum"]
    w --> draw["Weighted draw with SeededRandom"]
    draw --> take["Mark it used for the rest of the window.<br/>A freezer meal reserves a portion in memory"]
    take --> done(["Pick: the recipe or the freezer meal"])
```

- **"No repeat in a window" is the only hard rule about variety.** Having been
  cooked recently only means fewer tickets in the draw (21 days by default), so a
  small vault still produces a dinner.
- **Freezer meals skip the cook and effort checks.** They are 80% prepped, so they
  only need the night to be short enough.
- **Retired recipes never reach the picker.** `MealPool::fromVault()` only loads
  rows with no `retired_at`.
- **The reason is stored as counts, not as a sentence** (`NothingFitsReason`),
  because the sentence names the cook and the cook's name is a setting that can
  change.

### The freezer portion lifecycle

`freezer_items.portions_left` is the only thing in the app that is counted. Exactly
one method takes a portion out (`MealPlanner::reservePortionFor`) and exactly one
puts one back (`PlanNight::releaseFreezerPortion`). The night records that it is
holding one (`holds_freezer_portion`), so releasing twice cannot add a portion that
was never taken.

```mermaid
stateDiagram-v2
    direction LR
    Stash: In the stash
    Held: Held by a planned night
    Eaten: Eaten

    [*] --> Stash: typed on /kitchen
    Stash --> Held: picked, or chosen by hand. Row locked, portions_left - 1
    Held --> Stash: re-rolled, cook set to nobody, night dropped, plan deleted, stash item retired. portions_left + 1
    Held --> Eaten: the night's date passes
    Eaten --> [*]: never handed back
    Stash --> [*]: typed down on /kitchen
```

- **A plan's `deleting` event releases its nights' portions.** It has to be the plan:
  `plan_nights` rows go by MySQL cascade, so no event on a night would ever fire.
- **The `/kitchen` form only writes counts the household typed.** The planner spends
  and returns portions while the page is open, and posting every row back would undo
  that.
- **Nothing adds portions when a project is done** (MM-12), and nothing warns when the
  stash runs low (MM-12).

---

## 6. The grocery list

`GroceryListBuilder` is the only grocery class that touches the database. It turns a
plan into needs, hands them to the pure merge classes, and reconciles the result with
the stored rows.

### From a plan to a list

```mermaid
flowchart TD
    nights[("plan_nights of the plan")] --> sel{"What is the night?"}
    sel -- "freezer meal, night off,<br/>nothing fits, not planned" --> none["Buys nothing"]
    sel -- "a recipe" --> ing[("ingredients<br/>where the owner is that Recipe")]
    ing --> need["One IngredientNeed per row<br/>tagged with the dinner's name.<br/>A recipe on two nights counts twice"]
    kid[("kid_backup_foods<br/>every row")] --> kneed["One need per food<br/>section Kid backup<br/>added last"]
    proj["Due kitchen projects' ingredients<br/>MM-12"]

    need --> merge["GroceryMerge<br/>fold needs with the same merge key:<br/>name and unit, lower-cased,<br/>spaces collapsed. No unit conversion"]
    kneed --> merge
    proj -.-> merge

    merge --> rules["Amounts add up. A need with no amount adds nothing.<br/>The first spelling wins.<br/>A pantry basic only if every need says so"]
    rules --> together["keepNamesTogether<br/>every line with the same name<br/>takes the first real section"]
    together --> group["GroceryList<br/>pantry basics go to Check you have, always last.<br/>No section goes to Other"]
    group --> order["Order the sections<br/>store_section_order setting,<br/>otherwise alphabetical"]
    order --> out(["The list the window asks for"])

    classDef notbuilt fill:#f4f4f5,stroke:#71717a,color:#52525b,stroke-dasharray: 4 3
    class proj notbuilt
```

Why `1 lb` and `500 g` of beef stay two lines: they have different merge keys, and
converting between them would be the app inventing household data. Both lines share
a section, so they sit next to each other.

### Rebuilding without losing a tick

The list is **stored**, because both phones must see the same ticks. When the plan
changes, `rebuild()` reconciles line by line instead of replacing the list.

```mermaid
flowchart TD
    start(["rebuild(plan)"]) --> passed{"Has the window's<br/>last night gone by?"}
    passed -- yes --> alone["Left exactly as it was shopped.<br/>Nothing written"]
    passed -- no --> lock["Transaction. Read the plan's rows<br/>SELECT … FOR UPDATE,<br/>so a second rebuild waits for this one"]
    lock --> each["For each line the plan now asks for"]
    each --> found{"A stored row with<br/>the same merge key?"}
    found -- no --> add["Insert, unticked<br/>an upsert on plan_id + merge_key,<br/>so a race cannot store it twice"]
    found -- yes --> dup["Keep one row: the ticked one, or the oldest.<br/>Delete any duplicates and log it"]
    dup --> more{"It is ticked and the plan<br/>now asks for more?"}
    more -- yes --> untick["Untick it<br/>we had enough of the old amount"]
    more -- no --> keep["Keep the row and its tick,<br/>update the amount and the dinners"]
    lock --> left["Stored rows nothing wants any more"]
    left --> del["Delete them, ticked or not"]
    add & untick & keep & del --> moved{"Did any line really<br/>change?"}
    moved -- yes --> stamp["plans.grocery_list_changed_at = now"]
    moved -- no --> quiet["Nothing stamped"]
```

- **"Asks for more"** means the amount went up, or an amount appeared at the same
  time as a second dinner started wanting the line. The same single dinner gaining an
  amount is a recipe getting more precise, and keeps its tick.
- **The rebuild runs inside the planner's transaction** when the planner calls it.
  That keeps the dinner and the list in step, and it is also MM-24's bug: a failure
  while building the list rolls back the dinner change that caused it.
- **`grocery_list_changed_at` after the shopping time** is the "the plan changed after
  the shop" warning (`Plan::groceryListChangedAfterShopping()`). It compares against
  `HouseholdSettings::at(shop time, shopping date)`.

### Reading the list

| Method | What it does | Writes? |
| --- | --- | --- |
| `listFor()` | The list exactly as stored, with its ticks | No |
| `previewFor()` | What the plan asks for right now, without ticks | No |
| `isStale()` | Compares those two, ignoring ticks | No |
| `currentFor()` | `listFor()`, but rebuilds first if stale. **What a page should call** | If stale |
| `textFor()` | `currentFor()` as plain text, the body of the MM-9 post | If stale |

A list goes stale without the plan changing whenever somebody edits a recipe's
ingredients or the kid backup foods, which is why readers use `currentFor()`.
`milly:groceries` rebuilds on the first run, on `--rebuild`, or when `isStale()`
says so.

**Ticking exists and nothing calls it.** `PlanGroceryItem::check()` is a conditional
`UPDATE … WHERE checked = false`, so when two phones tick one line, exactly one wins.
Only tests call it until the plan page lands (MM-8).

---

## 7. Where the parts that aren't built plug in

Everything in this diagram's dashed boxes is missing. Everything solid already
exists and is waiting for it, so each future ticket is mostly wiring.

```mermaid
flowchart LR
    subgraph exists["Exists today"]
        direction TB
        mp["MealPlanner<br/>rerollNight, lockNight, unlockNight,<br/>chooseMeal, setOverride, clearOverride"]
        gb["GroceryListBuilder<br/>currentFor, textFor"]
        check["PlanGroceryItem::check()"]
        nights[("plan_nights<br/>tonight's dinner and cook")]
        offramp[("kid_off_ramp<br/>on recipes and freezer items")]
        prep[("recipe_prep_steps<br/>lead_time_hours")]
        thaw[("freezer_items<br/>thaw_lead_time_hours")]
        times[("settings<br/>plan_post_time,<br/>daily_dinner_reminder_time,<br/>prep_block")]
        proj[("kitchen_projects<br/>every_days, last_done_on,<br/>produces_portions")]
        low["FreezerItem::isRunningLow()"]
    end

    planpage["Plan page<br/>MM-8"]
    post["Plan and list post<br/>MM-9"]
    remind["Dinner, prep and thaw reminders<br/>MM-13"]
    dash["Kitchen dashboard<br/>MM-14"]
    done["Project done button,<br/>project ingredients on the list,<br/>low-stash warning<br/>MM-12"]
    sched(["scheduler"])
    slack(["Slack"])

    planpage -.-> mp
    planpage -.-> gb
    planpage -.-> check
    sched -.-> post
    sched -.-> remind
    post -.-> gb
    post -.-> times
    remind -.-> nights
    remind -.-> offramp
    remind -.-> prep
    remind -.-> thaw
    remind -.-> times
    post -.-> slack
    remind -.-> slack
    dash -.-> nights
    dash -.-> offramp
    done -.-> proj
    done -.-> low

    classDef notbuilt fill:#f4f4f5,stroke:#71717a,color:#52525b,stroke-dasharray: 4 3
    class planpage,post,remind,dash,done,slack notbuilt
```

| Ticket | What it calls or reads that already exists |
| --- | --- |
| **MM-8** plan page | All six `MealPlanner` night methods (they have no caller today), `GroceryListBuilder::currentFor()`, `PlanGroceryItem::check()`, `Plan::groceryListChangedAfterShopping()` |
| **MM-9** shopping-day post | `GroceryListBuilder::textFor()` (what `milly:groceries --text` prints), the `plan_post_time` setting, `config/services.php`'s Slack token. The first job in `routes/console.php` |
| **MM-13** reminders | `plan_nights` for tonight, the kid off-ramp columns, `recipe_prep_steps.lead_time_hours` (`RecipePrepStep::isAheadOfTheDay()` has no caller), `freezer_items.thaw_lead_time_hours`, the reminder-time settings |
| **MM-14** dashboard | The same night data as MM-13, rendered as a page with `wire:poll` |
| **MM-12** projects | `KitchenProject::dueOn()` (only the Kitchen page's label reads it), `produces_portions`, `FreezerItem::isRunningLow()`, and the recipe-only filter in `GroceryListBuilder::needsByRecipe()` to widen to projects |
| **MM-11** bulk pairing | `bulk_ingredients`, its fridge life (`BulkIngredient::fridgeLifeDays()`), and the recipes' tags. It has to bend `NightPicker`'s weighted draw rather than replace it |
