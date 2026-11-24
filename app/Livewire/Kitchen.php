<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsRows;
use App\Models\FreezerItem;
use App\Models\KitchenProject;
use App\Planning\CalendarDate;
use App\Settings\HouseholdSettings;
use App\Vault\MealUsage;
use App\Vault\Retirement;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The kitchen: the recurring projects and the 80%-prepped freezer stash
 * (MM-15, AppPlan.md section 2).
 *
 * The two live on one page because they are one loop: a project is what puts
 * portions in the freezer, and a portion is what makes a chaos night survivable.
 *
 * The stash is edited in place — the counts are the thing that changes most
 * often, so bumping three of them is one form and one save. A project is a page
 * of its own (ProjectEditor), because it carries an ingredients list.
 *
 * Removing a stash item goes through App\Vault\Retirement, like a recipe: a
 * planned freezer meal holds a portion out of the count (MM-6), and a night that
 * has gone by is the record of having eaten one.
 */
#[Title('Kitchen')]
class Kitchen extends Component
{
    use EditsRows;

    /**
     * The project the household is being asked about before it goes.
     */
    public ?int $removingProject = null;

    /**
     * How much thaw time a freezer item can be given: a week. Anything longer is
     * a typo, and the thaw reminder goes out the night before (MM-13).
     */
    private const MAX_THAW_HOURS = 168;

    private const MAX_PORTIONS = 99;

    public function mount(): void
    {
        $this->status = session('status');
        $this->loadStash();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['rows' => ['array', 'max:60']];

        foreach ($this->rows as $index => $row) {
            $rules["rows.{$index}.name"] = [
                'required',
                'string',
                'max:120',
                Rule::unique('freezer_items', 'name')->ignore($row['id'] ?? null),
                $this->notListedTwice(),
            ];

            // Zero is normal and means "we know this meal, we have none right
            // now" (MM-3): the row is the catalogue entry as well as the count.
            $rules["rows.{$index}.portions_left"] = ['required', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS];
            $rules["rows.{$index}.thaw_lead_time_hours"] = ['required', 'integer', 'min:0', 'max:'.self::MAX_THAW_HOURS];
            $rules["rows.{$index}.kid_off_ramp"] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'rows.*.name.required' => 'Name the freezer meal, or remove the row.',
            'rows.*.name.unique' => 'There is already a freezer meal with that name.',
            'rows.*.name.max' => 'Keep the name to 120 characters or fewer.',

            'rows.*.portions_left.required' => 'Say how many portions are left. Zero is fine: it means you have none right now.',
            'rows.*.portions_left.integer' => 'Give the portions as a whole number.',
            'rows.*.portions_left.min' => 'There cannot be fewer than no portions.',
            'rows.*.portions_left.max' => 'That is more portions than the freezer holds. Use 99 or fewer.',

            'rows.*.thaw_lead_time_hours.required' => 'Say how long it needs out of the freezer. Use 0 if it goes straight in the pot.',
            'rows.*.thaw_lead_time_hours.integer' => 'Give the thaw time as a whole number of hours.',
            'rows.*.thaw_lead_time_hours.min' => 'A thaw time cannot be negative.',
            'rows.*.thaw_lead_time_hours.max' => 'A week out of the freezer is not thawing. Use 168 hours or fewer.',

            'rows.*.kid_off_ramp.max' => 'Keep the kid off-ramp to 1000 characters or fewer.',
        ];
    }

    /**
     * Save every stash row, or none of them.
     */
    public function save(): void
    {
        $this->saved = false;
        $this->status = null;

        $this->tidyInput();
        $this->validate();

        DB::transaction(function (): void {
            foreach ($this->rows as $index => $row) {
                $item = FreezerItem::query()->findOrNew($row['id'] ?? null);

                $item->fill([
                    'name' => $row['name'],
                    'thaw_lead_time_hours' => (int) $row['thaw_lead_time_hours'],
                    'kid_off_ramp' => $row['kid_off_ramp'] === '' ? null : $row['kid_off_ramp'],
                ]);

                /*
                 * The count is the one field two things write. The household
                 * types it here, and the planner spends and hands back portions
                 * as nights are planned, re-rolled and removed (MM-6). So only a
                 * count this form really changed is written: posting every row's
                 * number back would undo whatever the planner did while the page
                 * was open — on this page, on another phone, or in the re-pick a
                 * removal on this very page set off.
                 *
                 * A count the household did type wins, because they are the ones
                 * standing at the freezer.
                 */
                if ($this->countWasTyped($row)) {
                    $item->portions_left = (int) $row['portions_left'];
                }

                $item->save();

                $this->rows[$index]['id'] = (int) $item->getKey();
                $this->rows[$index]['portions_left'] = (string) $item->portions_left;
                $this->rows[$index]['counted'] = (string) $item->portions_left;
            }
        });

        $this->saved = true;
    }

    // -----------------------------------------------------------------
    // Taking a freezer meal out of the stash
    // -----------------------------------------------------------------

    /**
     * Take it out of the stash the picker draws from, and repair the plan.
     */
    public function retire(int $item): void
    {
        $model = FreezerItem::query()->find($item);

        if ($model === null) {
            $this->afterRemoving($item, 'That freezer meal is already gone.');

            return;
        }

        $this->afterRemoving($item, (new Retirement)->retire($model)->whatRemovingDid());
    }

    public function bringBack(int $item): void
    {
        $model = FreezerItem::query()->find($item);

        if ($model === null) {
            $this->status = 'That freezer meal is already gone.';

            return;
        }

        (new Retirement)->bringBack($model);

        $this->status = $model->name.' is back in the stash, and the picker can plan it again.';
        $this->removing = null;
        $this->refreshStash();
    }

    /**
     * Delete the row itself, which is only possible while nothing points at it.
     */
    public function deleteForGood(int $item): void
    {
        $model = FreezerItem::query()->find($item);

        if ($model === null) {
            $this->afterRemoving($item, 'That freezer meal is already gone.');

            return;
        }

        $name = $model->name;
        $why = MealUsage::of($model)->whyItCannotBeDeleted();

        if (! (new Retirement)->deleteForGood($model)) {
            $this->status = sprintf('%s was kept. %s Take it out of the stash instead.', $name, (string) $why);
            $this->removing = null;

            return;
        }

        $this->afterRemoving($item, $name.' is deleted.');
    }

    /**
     * What still points at the freezer meal in question.
     */
    #[Computed]
    public function usage(): ?MealUsage
    {
        $item = $this->removing === null ? null : FreezerItem::query()->find($this->removing);

        return $item === null ? null : MealUsage::of($item);
    }

    // -----------------------------------------------------------------
    // Kitchen projects
    // -----------------------------------------------------------------

    public function confirmRemoveProject(int $project): void
    {
        $this->removingProject = $project;
        $this->status = null;
    }

    public function cancelRemoveProject(): void
    {
        $this->removingProject = null;
    }

    /**
     * Remove a project.
     *
     * A plain delete: nothing on a plan points at a project, and its ingredients
     * go with it in a model event (they are polymorphic, so MySQL cannot cascade
     * them). What it produced in the past stays in the freezer — the portions are
     * counted on the stash item, not here.
     */
    public function removeProject(int $project): void
    {
        $model = KitchenProject::query()->find($project);

        $this->removingProject = null;

        if ($model === null) {
            $this->status = 'That project is already gone.';

            return;
        }

        $name = $model->name;
        $model->delete();

        $this->status = $name.' is removed. Anything it has already put in the freezer is still counted there.';
    }

    /**
     * What removing the project in question takes with it.
     *
     * @return array<int, string>
     */
    public function projectRemovalWarnings(): array
    {
        $project = $this->removingProject === null
            ? null
            : KitchenProject::query()->withCount('ingredients')->find($this->removingProject);

        if ($project === null) {
            return [];
        }

        $warnings = [];

        if ($project->ingredients_count > 0) {
            $warnings[] = sprintf(
                'Its %d %s go with it, so they stop appearing on the grocery list.',
                $project->ingredients_count,
                $project->ingredients_count === 1 ? 'ingredient' : 'ingredients',
            );
        }

        if ($project->produces_freezer_item_id !== null) {
            $warnings[] = 'Nothing will top up '.($project->producesFreezerItem?->name ?? 'the freezer meal it made')
                .' any more. What is already in the freezer is still counted.';
        }

        return $warnings;
    }

    /**
     * What finishing a project puts in the freezer, in one line.
     */
    public function producesLabel(KitchenProject $project): string
    {
        if ($project->producesFreezerItem === null) {
            return 'Stocks the fridge, not the freezer';
        }

        $portions = (int) $project->produces_portions;

        return sprintf(
            'Makes %d %s of %s',
            $portions,
            $portions === 1 ? 'portion' : 'portions',
            $project->producesFreezerItem->name,
        );
    }

    /**
     * When a project is next due, in the household's words.
     */
    public function dueLabel(KitchenProject $project): string
    {
        $today = CalendarDate::today(HouseholdSettings::timeZone());
        $due = CalendarDate::of($project->dueOn(Carbon::parse($today->toDateString())));

        return $due->lessThanOrEqualTo($today) ? 'due now' : 'due '.$due->format('D j M');
    }

    public function render(): View
    {
        return view('livewire.kitchen', [
            'projects' => KitchenProject::query()
                ->with('producesFreezerItem')
                ->withCount('ingredients')
                ->orderBy('name')
                ->get(),
            'retiredStash' => $this->retiredStash(),
        ]);
    }

    /**
     * @return array{id: null, name: string, portions_left: string, counted: string, thaw_lead_time_hours: string, kid_off_ramp: string}
     */
    protected function blankRow(): array
    {
        return [
            'id' => null,
            'name' => '',
            'portions_left' => '0',
            // Nothing is counted for a row that does not exist yet, so whatever
            // is typed counts as typed.
            'counted' => '',
            'thaw_lead_time_hours' => '24',
            'kid_off_ramp' => '',
        ];
    }

    /**
     * The stash the picker can draw from, as form rows.
     */
    private function loadStash(): void
    {
        $this->rows = FreezerItem::query()
            ->inTheVault()
            ->orderBy('name')
            ->get()
            ->map(fn (FreezerItem $item): array => $this->rowFor($item))
            ->all();
    }

    /**
     * Bring the form back in step with the stash after something else has moved
     * a count: a removal that re-picked a night, or a bring-back.
     *
     * A count the household has typed is kept — they typed it on purpose — and
     * every other row shows what is really in the freezer, rather than the
     * number that was there when the page was opened.
     */
    private function refreshStash(): void
    {
        $typed = collect($this->rows)
            ->filter(fn (array $row): bool => ($row['id'] ?? null) !== null)
            ->keyBy(fn (array $row) => $row['id']);

        $rows = FreezerItem::query()
            ->inTheVault()
            ->orderBy('name')
            ->get()
            ->map(function (FreezerItem $item) use ($typed): array {
                $row = $typed->get($item->getKey());

                if ($row === null) {
                    return $this->rowFor($item);
                }

                return [
                    ...$row,
                    'portions_left' => $this->countWasTyped($row)
                        ? $row['portions_left']
                        : (string) $item->portions_left,
                    'counted' => (string) $item->portions_left,
                ];
            })
            ->all();

        // Rows nobody has saved yet belong to the household, not to the stash.
        $unsaved = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => ($row['id'] ?? null) === null,
        ));

        $this->rows = [...$rows, ...$unsaved];
    }

    /**
     * @return array{id: int, name: string, portions_left: string, counted: string, thaw_lead_time_hours: string, kid_off_ramp: string}
     */
    private function rowFor(FreezerItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'name' => $item->name,
            'portions_left' => (string) $item->portions_left,
            // What the count was when this row was last in step with the
            // database, so the save can tell a typed count from an untouched one.
            'counted' => (string) $item->portions_left,
            'thaw_lead_time_hours' => (string) $item->thaw_lead_time_hours,
            'kid_off_ramp' => (string) $item->kid_off_ramp,
        ];
    }

    /**
     * Did the household change this row's count, rather than leave it as it was
     * loaded?
     *
     * @param  array<string, mixed>  $row
     */
    private function countWasTyped(array $row): bool
    {
        return trim((string) ($row['portions_left'] ?? '')) !== trim((string) ($row['counted'] ?? ''));
    }

    /**
     * @return Collection<int, FreezerItem>
     */
    private function retiredStash(): Collection
    {
        return FreezerItem::query()->retired()->orderBy('name')->get();
    }

    /**
     * Say what happened, and take the row off the form without disturbing the
     * rest of what the household has typed.
     */
    private function afterRemoving(int $id, string $status): void
    {
        $this->forgetRow($id);
        $this->status = $status;
        unset($this->usage);

        // Removing one item re-picks the nights that had it, which spends or
        // hands back portions of the others.
        $this->refreshStash();
    }

    private function tidyInput(): void
    {
        foreach ($this->rows as $index => $row) {
            $this->rows[$index]['name'] = trim((string) ($row['name'] ?? ''));
            $this->rows[$index]['portions_left'] = trim((string) ($row['portions_left'] ?? ''));
            $this->rows[$index]['thaw_lead_time_hours'] = trim((string) ($row['thaw_lead_time_hours'] ?? ''));
            $this->rows[$index]['kid_off_ramp'] = trim((string) ($row['kid_off_ramp'] ?? ''));
        }
    }

    /**
     * Two rows of the same name would both pass the `unique` rule — neither is in
     * the database yet — and then collide on the index halfway through saving.
     */
    private function notListedTwice(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $name = mb_strtolower(trim((string) $value));
            $index = (int) explode('.', $attribute)[1];

            foreach ($this->rows as $other => $row) {
                if ($other !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === $name) {
                    $fail('That name is on the list twice.');

                    return;
                }
            }
        };
    }
}
