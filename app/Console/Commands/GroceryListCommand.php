<?php

namespace App\Console\Commands;

use App\Grocery\GroceryLine;
use App\Grocery\GroceryList;
use App\Grocery\GroceryListBuilder;
use App\Grocery\GroceryListUpdate;
use App\Grocery\GrocerySection;
use App\Models\Plan;
use App\Planning\CalendarDate;
use App\Planning\MealPlanner;
use App\Planning\ShoppingWindow;
use App\Settings\HouseholdSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Print a window's grocery list.
 *
 * The list's front door until the plan page (MM-8) and the Slack post (MM-9)
 * exist — the household can take a terminal's worth of list to the fridge, which
 * is what the list is for (AppPlan.md, section 3). `--text` prints exactly what
 * MM-9 will post.
 */
class GroceryListCommand extends Command
{
    protected $signature = 'milly:groceries
        {--shopping-date= : Show the list for this shopping day (YYYY-MM-DD) instead of the next one}
        {--rebuild : Make the list again from the plan as it stands, and say what moved}
        {--text : Print the plain text a chat message carries, and nothing else}';

    protected $description = "Print the grocery list for a shopping window's plan";

    public function handle(): int
    {
        $planner = new MealPlanner;
        $groceries = new GroceryListBuilder(planner: $planner);

        try {
            $window = $this->window($planner);
            $plan = $planner->planFor($window);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($plan === null) {
            $this->components->error(sprintf(
                'No plan for the %s shop yet. Run `milly:plan` first.',
                $window->shoppingDate->format('D j M'),
            ));

            return self::FAILURE;
        }

        // A list nobody has ever made is not a list somebody is holding, so
        // making it now cannot overwrite anything.
        $firstMake = ! $plan->hasGroceryList();
        $update = $this->option('rebuild') || $firstMake
            ? $groceries->rebuild($plan)
            : null;

        if ($this->option('text')) {
            $this->line($groceries->textFor($plan->refresh()));

            return self::SUCCESS;
        }

        $this->show($plan->refresh(), $groceries->listFor($plan), $update, $firstMake);

        return self::SUCCESS;
    }

    private function window(MealPlanner $planner): ShoppingWindow
    {
        $date = $this->option('shopping-date');

        return $date === null
            ? $planner->nextWindow()
            : $planner->windowFor(CalendarDate::parse((string) $date));
    }

    private function show(Plan $plan, GroceryList $list, ?GroceryListUpdate $update, bool $firstMake): void
    {
        $window = $plan->window();

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>Groceries for %s</> — %d nights: %s',
            $window->shoppingDate->format('D j M'),
            $window->nightCount(),
            $window->label(),
        ));

        if ($list->isEmpty()) {
            $this->newLine();
            $this->line('  Nothing to buy for this window.');
            $this->newLine();

            return;
        }

        foreach ($list->allSections() as $section) {
            $this->newLine();
            $this->line('  <options=bold>'.$section->name.'</>'.(
                $section->name === GrocerySection::PANTRY_BASICS ? '  <comment>(look, don’t buy)</comment>' : ''
            ));

            foreach ($section->lines as $line) {
                $this->line($this->row($line));
            }
        }

        $this->footer($plan, $list, $update, $firstMake);
    }

    /**
     * One line of the list, the way it reads at the fridge.
     */
    private function row(GroceryLine $line): string
    {
        $meals = $line->mealsLabel();

        return sprintf(
            '    %s %s%s',
            $line->checked ? '<info>[x]</info>' : '[ ]',
            $line->checked ? '<fg=gray>'.$line->describe().'</>' : $line->describe(),
            $meals === null ? '' : ' <comment>('.$meals.')</comment>',
        );
    }

    private function footer(Plan $plan, GroceryList $list, ?GroceryListUpdate $update, bool $firstMake): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  %d line%s, %d already crossed off.%s',
            $list->count(),
            $list->count() === 1 ? '' : 's',
            $list->checkedCount(),
            $plan->grocery_list_changed_at === null ? '' : sprintf(
                ' Last changed %s.',
                $plan->grocery_list_changed_at
                    ->setTimezone(HouseholdSettings::timeZone())
                    ->format('D j M g:ia'),
            ),
        ));

        // Naming every line of a list that did not exist a moment ago is not
        // news; being told the window is over and the list was left alone is.
        if ($update !== null && (! $firstMake || $update->leftAlone !== null)) {
            $this->line('  <comment>'.$update->summary().'</comment>');
        }

        // The warning MM-8 will show on the page: the list moved after the shop.
        if ($plan->groceryListChangedAfterShopping()) {
            $this->newLine();
            $this->components->warn(
                'The plan changed after the shop. Anything unticked may still need buying.',
            );
        }

        $this->newLine();
    }
}
