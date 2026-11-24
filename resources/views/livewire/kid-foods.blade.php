{{--
    The kid off-ramp inventory (MM-15).

    One row per food, one save for the lot.
--}}
<div class="space-y-6">
    <x-page-heading
        title="Kid foods"
        description="The guaranteed-safe backup items to keep stocked. These go on every grocery list, so the answer to “they won't eat it” is already in the house."
    />

    <x-form.notices :saved="filled($status)" :message="$status" :heading="null" />

    <form wire:submit="save" class="space-y-6 pb-4">
        <x-form.notices
            :saved="$saved"
            message="These are added to the grocery list for every shop."
        />

        <x-settings-section
            title="Always in the house"
            description="How much to keep stocked, in the units you buy it in. If a recipe needs the same thing, it stays its own line on the list — a bag of tortillas and “8 count” are not the same shopping decision."
        >
            <x-form.error field="rows" />

            @forelse ($rows as $index => $row)
                <div wire:key="kid-row-{{ $index }}-{{ $row['id'] ?? 'new' }}" class="rounded-lg border border-stone-200 p-3">
                    <x-form.input
                        field="rows.{{ $index }}.name"
                        label="Food"
                        :value="$row['name'] ?? ''"
                        autocomplete="off"
                        maxlength="120"
                        placeholder="Chicken nuggets"
                    />

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <x-form.input
                            field="rows.{{ $index }}.quantity"
                            label="Amount"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0"
                            :value="$row['quantity'] ?? ''"
                        />

                        <x-form.input
                            field="rows.{{ $index }}.unit"
                            label="Unit"
                            :value="$row['unit'] ?? ''"
                            autocomplete="off"
                            maxlength="40"
                            placeholder="box, bag, loaf"
                        />
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
                            :title="'Take '.$row['name'].' off the list?'"
                            :warnings="['It stops being added to the grocery list. A list already out keeps the line it was built with until someone opens it again.']"
                        >
                            <x-button tone="danger" wire:click="remove({{ $row['id'] }})">
                                Take it off
                            </x-button>

                            <x-button wire:click="cancelRemove">Keep it</x-button>
                        </x-confirm-panel>
                    @endif
                </div>
            @empty
                <x-empty-state>
                    No backup foods yet. Add the handful of things the kids will always eat, so the
                    grocery list keeps them in stock.
                </x-empty-state>
            @endforelse

            <x-button wire:click="addRow">Add a backup food</x-button>
        </x-settings-section>

        <x-save-bar label="Save kid foods" note="Added to every grocery list." />
    </form>
</div>
