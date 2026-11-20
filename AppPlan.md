# Product Requirements Document: Kitchen Logistics & Handoff System

## What Problem Are We Solving?
When life gets busy, we fail to carve out time to go to the grocery store or prep ahead. By 5:00 PM, there isn't enough in the fridge to make a complete (or wanted) meal, leading to a last-minute scramble (like instant ramen and canned soup for the kids). 

The system needs to solve six specific household constraints:
* **Small Fridge Capacity:** We cannot hold a full week of fresh groceries at once, so shopping needs to happen on a fixed twice-weekly schedule (Sundays and Wednesdays).
* **Forced Schedule Blocks:** Instead of trying to read our calendars and find free gaps, the system should force fixed times for shopping and kitchen prep that we work our schedules around.
* **Cooking Handoffs & Solo Nights:** Both partners cook on different nights depending on schedules. When my partner is cooking solo, she needs either a quick meal she likes making (like quick pasta or pupusas) or something 80% prepped from the freezer (like frozen soups made during earlier prep blocks).
* **Recipe Randomization with Constraints:** We want a place to store favorite recipes and randomize them so we don't forget about them, while respecting how much time we have that night and who is cooking.
* **Bulk Ingredient Synergy (Raw Split):** Many recipes share bulk ingredients (e.g., Beef Bulgogi and White Boy Tacos both use ground beef). The system should pair these within the same shopping window so we can buy in bulk, use half for Meal 1, and throw the raw second half back in the fridge for Meal 2.
* **Picky Kid "Off-Ramps":** The kids are becoming pickier, so meals need both a deconstruction plan (modifying or pulling food out early for them) and a reliable inventory of safe backup foods.

---

## What Information Does the System Need to Track?

### 1. The Recipe Vault
* **Recipe Name** (e.g., Beef Bulgogi, White Boy Tacos, Chili, Quick Pasta, Pupusas)
* **Who Can Cook It:** Tagged as `Me`, `Partner`, or `Both` (so my partner is never assigned a recipe from the vault she doesn't want to make on her solo nights).
* **Time / Effort Tier:** 
  * `15-Min / Chaos Night` (Including 80% prepped freezer meals and quick staples)
  * `30-Min Workhorse` (Standard weeknight meals)
  * `60-Min+ Project` (High-effort cooking when time allows)
* **Bulk Ingredient Tag:** Tracks main shared ingredients (like `Ground Beef`) so the randomizer can pair meals that split a bulk pack.
* **Upstream Prep Dependency:** Notes if something needs to happen ahead of time (e.g., soaking beans before Wednesday's chili, or thawing frozen soup).
* **Kid Off-Ramp Note:** How to adapt this meal for the kids.
* **Ingredients List:** What is needed from the store to make it.

### 2. Scheduled Kitchen Projects & Freezer Stash
* **Recurring Kitchen Projects:** Things we do in the kitchen on an "every X days" schedule (e.g., making stock, sauces, fresh hummus, soaking beans, or making big batches of soup to freeze).
* **80% Prepped Stash:** Tracks the pre-made meals (like frozen soups) created during Kitchen Project times that can be pulled out for my partner's solo nights or chaos nights.

### 3. Kid Off-Ramp Inventory
* A programmed list of guaranteed safe backup items for the kids so we always keep them stocked alongside regular groceries.

---

## How Should the Weekly Workflow Run?

### 1. Fixed Sunday & Wednesday Shopping Cadence
* **Sunday Shopping:** Covers meals for Monday, Tuesday, and Wednesday.
* **Wednesday Shopping:** Covers meals for Thursday, Friday, Saturday, and Sunday.
* The system enforces fixed reminders for these shopping blocks and scheduled Kitchen Project days (e.g., "Mondays are prep days") so we build our week around them.

### 2. Smart Meal Randomization
For each 3-to-4 day block, the system selects meals from the Recipe Vault based on:
* **Who is cooking that night:** Only assigns recipes tagged for that person (or `Both`).
* **How much time we have:** Matches the recipe's Effort Tier to the night's schedule. For my partner's solo nights, it strictly pulls from her quick meals (pasta, pupusas) or the 80% prepped freezer stash.
* **Bulk Ingredient Synergy:** Looks for opportunities to pair two recipes that use the same bulk ingredient across the block (using half for the first meal and keeping the raw second half in the fridge for the second meal).

### 3. Manual Fridge Check & Grocery List
* Once the meals and any due Kitchen Projects for the 3-day window are set, the system outputs the combined ingredients needed (plus programmed kid off-ramp items).
* **No Automated Pantry Tracking:** I will look at the required ingredients list while standing at the fridge and manually cross off what we already have before heading to the store.

### 4. Daily Reminders & Upstream Prep Nudges
* **Upstream Prep Reminders:** Alerts us ahead of time when a meal requires advance prep (like soaking beans the day before chili) or when a scheduled Kitchen Project is due.
* **Daily Cooking Reminder:** Reminds both of us of what to cook that night, who is on duty, and the kid off-ramp plan.

---

## How Will We Interact With It?
Both partners need full access to the system without relying on a personal calendar app:
1. **Shared Messaging / Text Reminders:** A shared household group (such as a shared Slack group or text message notifications) that pings both of us with the meal plan, grocery list, upstream prep tasks, and tonight's dinner reminder.
2. **Kitchen Dashboard:** A visual display for the kitchen showing the upcoming meals, scheduled kitchen projects, and reminders at a glance.

---

## Instructions for Claude Code
Before writing any code, review these requirements and ask me how I want to approach the tech stack, hosting, data storage, and messaging integration.