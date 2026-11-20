# Decisions

Shared context for every ticket. Read this together with `AppPlan.md`. When a ticket makes a decision that other tickets need, add it here.

## Decided

- **We build a custom app.** We don't use a spreadsheet or a no-code tool.
- **Household values live in Settings, not in code.** This covers who cooks each night, how much time there is, shopping days and times, the prep block, dinner time and reminder times. Each value has a starting default and can be changed on the Settings page (MM-4).
- **The tech stack, hosting, data storage, messaging and dashboard device are not decided yet.** MM-1 asks the household about them. Don't write app code before MM-1 is done.

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
