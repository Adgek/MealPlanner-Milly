{{--
    The bulk packs a recipe can split, and how long the raw half keeps (MM-15).

    One row per ingredient, one save for the lot.
--}}
<div class="space-y-6">
    <x-page-heading
        title="Bulk ingredients"
        description="The shared packs worth buying whole: ground beef across the bulgogi and the tacos. Tag a recipe with one on the recipe's own page."
    />

    <x-form.notices :saved="filled($status)" :message="$status" :heading="null" />

    <form wire:submit="save" class="space-y-6 pb-4">
        <x-form.notices
            :saved="$saved"
            message="The planner pairs meals that split a pack whenever the second one still falls inside the fridge life."
        />

        <x-settings-section
            title="The packs"
            description="Leave the fridge life empty to use the household default from Settings, which is {{ $this->defaultFridgeLifeDays() }} days."
        >
            <x-form.error field="rows" />

            @forelse ($rows as $index => $row)
                <div wire:key="bulk-row-{{ $index }}-{{ $row['id'] ?? 'new' }}" class="rounded-lg border border-stone-200 p-3">
                    <div class="sm:flex sm:items-start sm:gap-3">
                        <div class="sm:flex-1">
                            <x-form.input
                                field="rows.{{ $index }}.name"
                                label="Name"
                                :value="$row['name'] ?? ''"
                                autocomplete="off"
                                maxlength="120"
                                placeholder="Ground beef"
                            />
                        </div>

                        <div class="mt-3 sm:mt-0 sm:w-44">
                            <x-form.input
                                field="rows.{{ $index }}.fridge_life_days"
                                label="Fridge life (days)"
                                type="number"
                                inputmode="numeric"
                                min="1"
                                max="14"
                                step="1"
                                :value="$row['fridge_life_days'] ?? ''"
                            />
                        </div>
                    </div>

                    <div class="mt-3">
                        @if (($row['id'] ?? null) === null)
                            <x-button tone="danger" wire:click="dropRow({{ $index }})">
                                Remove<span class="sr-only"> the new row</span>
                            </x-button>
                        @else
                            <x-button tone="danger" wire:click="confirmRemove({{ $row['id'] }})">
                                Remove<span class="sr-only"> {{ $row['name'] }}</span>
                            </x-button>
                        @endif
                    </div>

                    @if (($row['id'] ?? null) !== null && $removing === $row['id'])
                        <x-confirm-panel
                            :title="'Remove '.$row['name'].'?'"
                            :warnings="$this->removalWarnings()"
                        >
                            <x-button tone="danger" wire:click="remove({{ $row['id'] }})">
                                Remove it
                            </x-button>

                            <x-button wire:click="cancelRemove">Keep it</x-button>
                        </x-confirm-panel>
                    @endif
                </div>
            @empty
                <x-empty-state>
                    No bulk ingredients yet. Add the packs you buy whole and split across two meals.
                </x-empty-state>
            @endforelse

            <x-button wire:click="addRow">Add a bulk ingredient</x-button>
        </x-settings-section>

        <x-save-bar label="Save bulk ingredients" note="Changes apply to the next plan." />
    </form>

    <x-button as="a" href="{{ route('recipes') }}" wire:navigate>Back to the recipes</x-button>
</div>
