<?php

namespace App\Planning;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use Closure;

/**
 * Why nothing fitted a night, as facts rather than as a sentence.
 *
 * "Nothing fits" on its own is useless: the answer is either "add a quick recipe
 * she likes", "cook together that night" or "restock the freezer", and which one
 * depends on what ruled everything out. So the picker counts what it rejected
 * and why, and this carries the counts.
 *
 * It is deliberately not a rendered string. A rendered reason has to name the
 * cook, the household's own name for the cook lives in Settings (MM-4), and the
 * reason is **stored** on the plan night — so a sentence written at pick time
 * would still say "Partner" after the household had renamed her, with no way to
 * repair it. Keeping the cook as an enum and rendering on the way out means a
 * rename reaches rows that were written months ago, and keeps NightPicker free of
 * the database, which is what lets every rule be unit-tested (AGENTS.md).
 *
 * render() therefore asks for the labeller rather than choosing one: pass
 * HouseholdSettings::cookLabel(...) anywhere a person will read the result.
 */
final class NothingFitsReason
{
    public function __construct(
        public readonly CookAssignment $cook,
        /**
         * The time the picker actually allowed on this night.
         */
        public readonly EffortTier $timeAvailable,
        /**
         * The weekly schedule's budget, when the solo cap narrowed it; null when
         * the night had all the time the schedule gave it.
         */
        public readonly ?EffortTier $cappedFrom,
        public readonly int $recipesInVault,
        public readonly int $ruledOutByCook,
        public readonly int $ruledOutByTime,
        public readonly int $recipesTaken,
        public readonly int $freezerInStash,
        public readonly bool $freezerAllowed,
        public readonly int $freezerEmpty,
        public readonly int $freezerTaken,
    ) {}

    /**
     * The reason in the household's words.
     *
     * @param  Closure(CookAssignment): string  $cookLabel  what to call the cook —
     *                                                      HouseholdSettings::cookLabel(...)
     *                                                      wherever a person reads it
     */
    public function render(Closure $cookLabel): string
    {
        return sprintf(
            'Nothing fits a %s night with %s cooking%s: %s.',
            $this->timeAvailable->label(),
            $cookLabel($this->cook),
            $this->capNote(),
            implode('; ', $this->parts($cookLabel)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cook' => $this->cook->value,
            'time_available' => $this->timeAvailable->value,
            'capped_from' => $this->cappedFrom?->value,
            'recipes_in_vault' => $this->recipesInVault,
            'ruled_out_by_cook' => $this->ruledOutByCook,
            'ruled_out_by_time' => $this->ruledOutByTime,
            'recipes_taken' => $this->recipesTaken,
            'freezer_in_stash' => $this->freezerInStash,
            'freezer_allowed' => $this->freezerAllowed,
            'freezer_empty' => $this->freezerEmpty,
            'freezer_taken' => $this->freezerTaken,
        ];
    }

    /**
     * A reason read back off a plan night, or null when the row carries none.
     *
     * Tolerant of a row written before this column existed, or by a version that
     * spelled a value differently: a plan with an unreadable reason is still a
     * plan, and a night that cannot explain itself is better than a page that
     * cannot load.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        $cook = CookAssignment::tryFrom((string) ($stored['cook'] ?? ''));
        $timeAvailable = EffortTier::tryFrom((string) ($stored['time_available'] ?? ''));

        if ($cook === null || $timeAvailable === null) {
            return null;
        }

        return new self(
            cook: $cook,
            timeAvailable: $timeAvailable,
            cappedFrom: EffortTier::tryFrom((string) ($stored['capped_from'] ?? '')),
            recipesInVault: (int) ($stored['recipes_in_vault'] ?? 0),
            ruledOutByCook: (int) ($stored['ruled_out_by_cook'] ?? 0),
            ruledOutByTime: (int) ($stored['ruled_out_by_time'] ?? 0),
            recipesTaken: (int) ($stored['recipes_taken'] ?? 0),
            freezerInStash: (int) ($stored['freezer_in_stash'] ?? 0),
            freezerAllowed: (bool) ($stored['freezer_allowed'] ?? false),
            freezerEmpty: (int) ($stored['freezer_empty'] ?? 0),
            freezerTaken: (int) ($stored['freezer_taken'] ?? 0),
        );
    }

    /**
     * What ruled the vault and the stash out, in the order that is most use.
     *
     * @param  Closure(CookAssignment): string  $cookLabel
     * @return array<int, string>
     */
    private function parts(Closure $cookLabel): array
    {
        $parts = [];

        if ($this->recipesInVault === 0) {
            $parts[] = 'the recipe vault is empty';
        } else {
            if ($this->ruledOutByCook > 0) {
                $parts[] = sprintf(
                    '%d of %d recipes are not tagged for %s',
                    $this->ruledOutByCook,
                    $this->recipesInVault,
                    $cookLabel($this->cook),
                );
            }

            if ($this->ruledOutByTime > 0) {
                $parts[] = sprintf(
                    '%d need more than %d min',
                    $this->ruledOutByTime,
                    $this->timeAvailable->minutes(),
                );
            }

            if ($this->recipesTaken > 0) {
                $parts[] = sprintf('%d are already spoken for this window', $this->recipesTaken);
            }
        }

        // An empty stash first: when there is nothing in the freezer, that is the
        // more useful fact than which nights the freezer is saved for.
        $parts[] = match (true) {
            $this->freezerInStash === 0 => 'the freezer stash is empty',
            ! $this->freezerAllowed => sprintf(
                'freezer meals are kept for %s nights',
                EffortTier::Chaos15->label(),
            ),
            $this->freezerTaken > 0 && $this->freezerEmpty === 0 => 'the freezer meals left are already spoken for this window',
            default => 'no freezer meal has a portion left',
        };

        return $parts;
    }

    /**
     * Say so when the night is shorter than the schedule because of the solo rule,
     * so "needs more than 15 min" doesn't look like a mistake on a 60-minute night.
     */
    private function capNote(): string
    {
        if ($this->cappedFrom === null) {
            return '';
        }

        return sprintf(
            ' (a solo night is capped at %s, from %s)',
            $this->timeAvailable->label(),
            $this->cappedFrom->label(),
        );
    }
}
