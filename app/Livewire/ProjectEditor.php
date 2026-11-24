<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsIngredients;
use App\Models\FreezerItem;
use App\Models\KitchenProject;
use App\Planning\CalendarDate;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One recurring kitchen project (MM-15): stock, sauces, hummus, a big batch of
 * soup for the freezer (AppPlan.md, section 2).
 *
 * "Every X days" plus "last done on" is what makes a project due, and what it
 * produces is a freezer item plus a portion count, so finishing it can top the
 * stash up rather than being typed in twice (MM-12).
 */
#[Title('Kitchen project')]
class ProjectEditor extends Component
{
    use EditsIngredients;

    public ?int $projectId = null;

    public string $name = '';

    /**
     * Held as a string, like every number on these forms: a cleared field
     * arrives as "" and has to fail `required` rather than become 0.
     */
    public string $everyDays = '';

    /**
     * The freezer item a finished run tops up, or "" for a project that stocks
     * the fridge instead.
     */
    public string $producesFreezerItemId = '';

    public string $producesPortions = '';

    /**
     * When it was last done, as "YYYY-MM-DD". Empty means never, which makes it
     * due now.
     */
    public string $lastDoneOn = '';

    /**
     * A cadence longer than a year is a typo, not a project.
     */
    private const MAX_EVERY_DAYS = 365;

    private const MAX_PORTIONS = 99;

    public function mount(?KitchenProject $project = null): void
    {
        if ($project === null || ! $project->exists) {
            $this->everyDays = '7';
            $this->ingredients = [$this->blankIngredient()];

            return;
        }

        $project->load('ingredients');

        $this->projectId = (int) $project->getKey();
        $this->name = $project->name;
        $this->everyDays = (string) $project->every_days;
        $this->producesFreezerItemId = (string) ($project->produces_freezer_item_id ?? '');
        $this->producesPortions = $project->produces_portions === null ? '' : (string) $project->produces_portions;
        $this->lastDoneOn = $project->last_done_on?->toDateString() ?? '';

        $this->fillIngredientsFrom($project);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('kitchen_projects', 'name')->ignore($this->projectId)],
            'everyDays' => ['required', 'integer', 'min:1', 'max:'.self::MAX_EVERY_DAYS],

            'producesFreezerItemId' => ['nullable', Rule::exists('freezer_items', 'id')],
            // Only meaningful with something to put the portions in, and then it
            // has to be a real number of portions or MM-12 would top the stash up
            // by nothing.
            'producesPortions' => [
                Rule::requiredIf(fn (): bool => $this->producesFreezerItemId !== ''),
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_PORTIONS,
            ],

            // Not `before_or_equal:today`: "today" is the server's date, and at
            // 8pm in Toronto that is already tomorrow (docs/decisions.md).
            'lastDoneOn' => ['nullable', 'date', 'before_or_equal:'.$this->today()],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Give the project a name.',
            'name.unique' => 'There is already a kitchen project with that name.',
            'name.max' => 'Keep the name to 120 characters or fewer.',

            'everyDays.required' => 'Say how often it comes round, in days.',
            'everyDays.integer' => 'Give the cadence as a whole number of days.',
            'everyDays.min' => 'A project has to come round at least a day apart.',
            'everyDays.max' => 'A year is as far ahead as a project is scheduled. Use 365 days or fewer.',

            'producesFreezerItemId.exists' => 'That freezer item no longer exists. Pick another, or choose nothing.',

            'producesPortions.required' => 'Say how many portions one run makes.',
            'producesPortions.integer' => 'Give the yield as a whole number of portions.',
            'producesPortions.min' => 'A run that makes no portions is not stocking the freezer. Leave the freezer item empty instead.',
            'producesPortions.max' => 'That is more portions than the freezer holds. Use 99 or fewer.',

            'lastDoneOn.date' => 'Give the date it was last done, or leave it empty for never.',
            'lastDoneOn.before_or_equal' => 'A project cannot have been done in the future.',
        ];
    }

    public function save(): void
    {
        $this->tidyInput();
        $this->validate();

        $project = DB::transaction(function (): KitchenProject {
            $project = KitchenProject::query()->findOrNew($this->projectId);

            $project->fill([
                'name' => $this->name,
                'every_days' => (int) $this->everyDays,
                'produces_freezer_item_id' => $this->producesFreezerItemId === ''
                    ? null
                    : (int) $this->producesFreezerItemId,
                // Portions without something to put them in would be a number
                // nothing reads, so the two are cleared together.
                'produces_portions' => $this->producesFreezerItemId === '' || $this->producesPortions === ''
                    ? null
                    : (int) $this->producesPortions,
                'last_done_on' => $this->lastDoneOn === '' ? null : $this->lastDoneOn,
            ])->save();

            $this->saveIngredientsFor($project);

            return $project;
        });

        session()->flash('status', $project->name.' is saved.');

        $this->redirect(route('kitchen'), navigate: true);
    }

    /**
     * What a finished run can top up: the stash, as it stands.
     *
     * A retired item is offered only while this project already points at it, so
     * the household can see what it is pointing at and change it, without
     * retired items cluttering the list (MM-15).
     *
     * @return array<string, string>
     */
    public function freezerItemOptions(): array
    {
        $options = ['' => 'Nothing — it stocks the fridge'];

        $items = FreezerItem::query()
            ->where(fn ($query) => $query->whereNull('retired_at')
                ->orWhereKey($this->producesFreezerItemId === '' ? 0 : (int) $this->producesFreezerItemId))
            ->orderBy('name')
            ->get();

        foreach ($items as $item) {
            $options[(string) $item->getKey()] = $item->isRetired()
                ? $item->name.' (out of the stash)'
                : $item->name;
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.project-editor');
    }

    private function tidyInput(): void
    {
        $this->name = trim($this->name);
        $this->everyDays = trim($this->everyDays);
        $this->producesFreezerItemId = trim($this->producesFreezerItemId);
        $this->producesPortions = trim($this->producesPortions);
        $this->lastDoneOn = trim($this->lastDoneOn);

        // The portions belong to the thing they go into.
        if ($this->producesFreezerItemId === '') {
            $this->producesPortions = '';
        }

        $this->tidyIngredients();
    }

    /**
     * Today on the kitchen clock.
     */
    private function today(): string
    {
        return CalendarDate::today(HouseholdSettings::timeZone())->toDateString();
    }
}
