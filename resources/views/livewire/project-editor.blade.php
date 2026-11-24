{{--
    One recurring kitchen project (MM-15).
--}}
<div>
    <x-page-heading
        :title="$projectId === null ? 'Add a kitchen project' : 'Edit '.($name !== '' ? $name : 'project')"
        description="Something you do in the kitchen on a cadence: stock, sauces, hummus, a big batch of soup for the freezer."
    />

    <form wire:submit="save" class="space-y-6 pb-4">
        <x-form.notices />

        <x-settings-section
            title="The project"
            description="How often it comes round, and when it was last done. A project that has never been done is due now."
        >
            <x-form.input
                field="name"
                label="Name"
                :value="$name"
                autocomplete="off"
                maxlength="120"
                placeholder="Big batch minestrone"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input
                    field="everyDays"
                    label="Every (days)"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="365"
                    step="1"
                    :value="$everyDays"
                    hint="7 is weekly, 21 is every three weeks."
                />

                <x-form.input
                    field="lastDoneOn"
                    label="Last done"
                    type="date"
                    :value="$lastDoneOn"
                    hint="Leave empty if you have never done it, which makes it due now."
                />
            </div>
        </x-settings-section>

        <x-settings-section
            title="What it makes"
            description="A project that fills the freezer adds portions to the stash when it is done, so a chaos night has something 80% prepped to pull out."
        >
            <x-form.select
                field="producesFreezerItemId"
                label="Tops up"
                :options="$this->freezerItemOptions()"
                :selected="$producesFreezerItemId"
                hint="Add the freezer meal on the kitchen page first if it is not here yet."
            />

            @if ($producesFreezerItemId !== '')
                <x-form.input
                    field="producesPortions"
                    label="Portions one run makes"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="99"
                    step="1"
                    :value="$producesPortions"
                />
            @endif
        </x-settings-section>

        <x-settings-section
            title="Ingredients"
            description="What the shop has to cover when this project is due. The grocery list adds these to the meals' ingredients for the same window."
        >
            <x-ingredient-rows
                :ingredients="$ingredients"
                :sections="$this->storeSections()"
                empty="No ingredients yet. Without them this project adds nothing to the grocery list."
            />
        </x-settings-section>

        <x-save-bar
            :label="$projectId === null ? 'Add the project' : 'Save the project'"
            note="Saving takes you back to the kitchen."
        />

        <x-button as="a" href="{{ route('kitchen') }}" wire:navigate>
            Back to the kitchen without saving
        </x-button>
    </form>
</div>
