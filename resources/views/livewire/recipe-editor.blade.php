{{--
    One recipe, every field (MM-15).

    Phone first: a single column of cards, each ingredient and prep step its own
    bordered block with full-width controls, and the save button sticky at the
    bottom so a long recipe never has to be scrolled to be saved.
--}}
<div>
    <x-page-heading
        :title="$recipeId === null ? 'Add a recipe' : 'Edit '.($name !== '' ? $name : 'recipe')"
        description="What the picker matches against, what the grocery list buys, and what the reminders say."
    />

    <form wire:submit="save" class="space-y-6 pb-4">
        <x-form.notices />

        @if ($retired)
            <div role="status" class="rounded-lg border border-stone-300 bg-stone-50 px-4 py-3 text-sm text-stone-700">
                This recipe is out of the vault, so the picker will not choose it. Editing it is
                fine — bring it back from the recipe list when you want it planned again.
            </div>
        @endif

        <x-settings-section
            title="The recipe"
            description="The three things the picker needs to match a recipe to a night."
        >
            <x-form.input
                field="name"
                label="Name"
                :value="$name"
                autocomplete="off"
                maxlength="120"
                hint="What you call it in the kitchen. It appears on the plan, in the Slack post and on the dashboard."
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select
                    field="cookableBy"
                    label="Who can cook it"
                    :options="$this->cookableByOptions()"
                    :selected="$cookableBy"
                    hint="A recipe tagged for one person is never handed to the other on a solo night."
                />

                <x-form.select
                    field="effortTier"
                    label="How much cooking"
                    :options="$this->effortTierOptions()"
                    :selected="$effortTier"
                    hint="Matched against how much time the night has. A 15-minute meal is fine on a long night; the reverse is not."
                />
            </div>

            <x-form.textarea
                field="kidOffRamp"
                label="Kid off-ramp"
                rows="3"
                hint="How to adapt it for the kids: what to pull out early, what to leave off. Shown with the dinner reminder."
            >{{ $kidOffRamp }}</x-form.textarea>
        </x-settings-section>

        <x-settings-section
            title="Bulk packs it splits"
            description="Tag the shared ingredients this recipe draws on, so the planner can pair it with another meal that uses the same pack and buy one between them."
        >
            @forelse ($bulkIngredients as $ingredient)
                <div wire:key="bulk-{{ $ingredient->id }}">
                    <x-form.checkbox
                        field="bulkIngredientIds"
                        :id="'bulk-ingredient-'.$ingredient->id"
                        :label="$ingredient->name.' ('.$ingredient->fridgeLifeDays().' days in the fridge)'"
                        :value="$ingredient->id"
                        :checked="in_array((string) $ingredient->id, array_map('strval', $bulkIngredientIds), true)"
                    />
                </div>
            @empty
                <x-empty-state>
                    No bulk ingredients yet. Add one on the
                    <a class="font-medium underline" href="{{ route('recipes.bulk-ingredients') }}" wire:navigate>bulk
                        ingredients page</a>
                    and it will be tickable here.
                </x-empty-state>
            @endforelse

            <x-form.error field="bulkIngredientIds" />
        </x-settings-section>

        <x-settings-section
            title="Ingredients"
            description="What the shop has to cover. Amounts and units are kept apart so the grocery list can add them up: 1 lb and 1 lb become 2 lb."
        >
            <x-ingredient-rows
                :ingredients="$ingredients"
                :sections="$this->storeSections()"
                empty="No ingredients yet. Without them this recipe adds nothing to the grocery list."
            />
        </x-settings-section>

        <x-settings-section
            title="Upstream prep"
            description="Anything that has to happen before the night of: beans soaked, meat marinated, a soup thawed. The hours ahead are what turns it into a reminder at the right time."
        >
            <x-form.error field="prepSteps" />

            @forelse ($prepSteps as $index => $step)
                <div wire:key="prep-{{ $index }}" class="rounded-lg border border-stone-200 p-3">
                    <x-form.input
                        field="prepSteps.{{ $index }}.description"
                        label="Step {{ $index + 1 }}"
                        :value="$step['description'] ?? ''"
                        autocomplete="off"
                        maxlength="255"
                        placeholder="Soak the beans"
                    />

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div class="sm:w-56">
                            <x-form.input
                                field="prepSteps.{{ $index }}.lead_time_hours"
                                label="Hours before dinner"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                max="168"
                                step="1"
                                :value="$step['lead_time_hours'] ?? ''"
                                hint="0 is part of cooking, 24 is the night before."
                            />
                        </div>

                        <x-button tone="danger" wire:click="removePrepStep({{ $index }})" :block="false">
                            Remove<span class="sr-only"> prep step {{ $index + 1 }}</span>
                        </x-button>
                    </div>
                </div>
            @empty
                <x-empty-state>
                    Nothing to do ahead of time.
                </x-empty-state>
            @endforelse

            <x-button wire:click="addPrepStep">Add a prep step</x-button>
        </x-settings-section>

        <x-save-bar
            :label="$recipeId === null ? 'Add the recipe' : 'Save the recipe'"
            note="Saving takes you back to the vault."
        />

        <x-button as="a" href="{{ route('recipes') }}" wire:navigate>
            Back to the recipes without saving
        </x-button>
    </form>
</div>
