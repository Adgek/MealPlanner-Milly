<?php

namespace App\Console\Commands;

use App\Enums\NightSelection;
use App\Planning\CalendarDate;
use App\Planning\MealPlanner;
use App\Planning\Pick;
use App\Planning\ShoppingWindow;
use App\Settings\HouseholdSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Plan the next shopping window and print it.
 *
 * The picker's front door until the plan page (MM-8) and the Slack post (MM-9)
 * exist — and after that, still the way to see what the rules did and why a
 * night came out empty.
 */
class PlanWindowCommand extends Command
{
    protected $signature = 'milly:plan
        {--shopping-date= : Plan the window for this shopping day (YYYY-MM-DD) instead of the next one}
        {--seed= : Roll with a fixed seed, so the same plan comes out twice}
        {--replan : Roll every night again, keeping the ones that are locked}
        {--dry-run : Show what it would choose without saving anything}';

    protected $description = "Plan the next shopping window's dinners and print them";

    public function handle(): int
    {
        $planner = new MealPlanner;
        $seed = $this->option('seed') === null ? null : (int) $this->option('seed');
        $dryRun = (bool) $this->option('dry-run');
        $replan = (bool) $this->option('replan');

        try {
            $window = $this->window($planner);

            $picks = $dryRun
                ? $planner->previewWindow($window, $seed, $replan)
                : $planner->picksFor($planner->planWindow($window, $seed, $replan));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->heading($window, $dryRun);
        $this->table(
            ['Night', 'Cook', 'Time', 'Dinner', 'Note'],
            array_map(fn (Pick $pick) => $this->row($pick), $picks),
        );
        $this->footer($picks, $dryRun);

        return self::SUCCESS;
    }

    private function window(MealPlanner $planner): ShoppingWindow
    {
        $date = $this->option('shopping-date');

        return $date === null
            ? $planner->nextWindow()
            : $planner->windowFor(CalendarDate::parse((string) $date));
    }

    private function heading(ShoppingWindow $window, bool $dryRun): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>Shopping %s</> — %d nights: %s',
            $window->shoppingDate->format('D j M'),
            $window->nightCount(),
            $window->label(),
        ));

        if ($dryRun) {
            $this->line('  <comment>Dry run: nothing was saved.</comment>');
        }

        $this->newLine();
    }

    /**
     * @return array<int, string>
     */
    private function row(Pick $pick): array
    {
        return [
            $pick->night->label(),
            // The household's own name for the cook, not the enum's: renaming a
            // cook in Settings has to rename them here too (MM-4).
            HouseholdSettings::cookLabel($pick->night->cook).($pick->night->overridesSchedule ? ' *' : ''),
            $pick->night->timeBudget->label(),
            $pick->describe(),
            $this->note($pick),
        ];
    }

    private function note(Pick $pick): string
    {
        return match (true) {
            $pick->selection === NightSelection::NothingFits => (string) $pick->note,
            $pick->night->overridesSchedule => '* '.($pick->night->note ?? 'one-off change for this date'),
            default => (string) $pick->note,
        };
    }

    /**
     * @param  array<int, Pick>  $picks
     */
    private function footer(array $picks, bool $dryRun): void
    {
        $unfilled = array_filter(
            $picks,
            fn (Pick $pick) => $pick->selection === NightSelection::NothingFits,
        );

        if ($unfilled !== []) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d night(s) have nothing that fits. The rest of the plan stands.',
                count($unfilled),
            ));
        }

        if (! $dryRun) {
            $this->newLine();
            $this->line('  <info>Saved.</info> Re-roll a night, lock one or swap it on the plan page.');
        }

        $this->newLine();
    }
}
