<?php

namespace App\Grocery;

use App\Enums\CookAssignment;
use App\Planning\Pick;
use App\Planning\ShoppingWindow;
use Closure;

/**
 * The grocery list as plain text: what MM-9 posts in the household chat, and what
 * `milly:groceries --text` prints.
 *
 * Checkboxes rather than bullets, because the list is read standing at the fridge
 * crossing things off (AppPlan.md, section 3) — and a line already ticked on a
 * phone comes out ticked here, so the two agree.
 *
 * No markup: Slack renders plain text fine and a text message cannot render
 * anything else.
 */
final class GroceryListText
{
    /**
     * The list itself: each section, then the pantry basics.
     */
    public static function render(GroceryList $list): string
    {
        if ($list->isEmpty()) {
            return 'Nothing to buy.';
        }

        $blocks = [];

        foreach ($list->allSections() as $section) {
            $lines = [$section->name];

            foreach ($section->lines as $line) {
                $lines[] = self::line($line);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The whole post: the shop, the dinners it covers, and the list.
     *
     * The cook's name is the household's own, so the labeller is an argument
     * (Pick::explain() takes one for the same reason: the enum's label reads fine
     * right up until somebody types their name in Settings). Left out, the dinners
     * are listed without naming anybody rather than with a name that might be
     * wrong.
     *
     * @param  array<int, Pick>  $picks
     * @param  (Closure(CookAssignment): string)|null  $cookLabel  pass HouseholdSettings::cookLabel(...)
     */
    public static function post(
        GroceryList $list,
        ShoppingWindow $window,
        array $picks = [],
        ?Closure $cookLabel = null,
    ): string {
        $blocks = [sprintf(
            "Groceries for %s\n%s, %d night%s",
            $window->shoppingDate->format('D j M'),
            $window->label(),
            $window->nightCount(),
            $window->nightCount() === 1 ? '' : 's',
        )];

        if ($picks !== []) {
            $blocks[] = implode("\n", ['Dinners', ...array_map(
                fn (Pick $pick) => self::dinner($pick, $cookLabel),
                $picks,
            )]);
        }

        $blocks[] = self::render($list);

        return implode("\n\n", $blocks);
    }

    /**
     * One line of the list: "[ ] Ground beef — 2 lb (Beef Bulgogi, Chili)".
     */
    private static function line(GroceryLine $line): string
    {
        $meals = $line->mealsLabel();

        return sprintf(
            '%s %s%s',
            $line->checked ? '[x]' : '[ ]',
            $line->describe(),
            $meals === null ? '' : ' ('.$meals.')',
        );
    }

    /**
     * One night of the window: "Mon 12 Jan: Chili (Adrian)".
     *
     * @param  (Closure(CookAssignment): string)|null  $cookLabel
     */
    private static function dinner(Pick $pick, ?Closure $cookLabel): string
    {
        $cook = $cookLabel === null ? null : $cookLabel($pick->night->cook);

        return sprintf(
            '%s: %s%s',
            $pick->night->label(),
            $pick->describe(),
            // A night off already says nobody is cooking; naming the cook of one
            // would read as "Nobody cooking (Nobody)".
            $cook === null || ! $pick->hasMeal() ? '' : ' ('.$cook.')',
        );
    }
}
