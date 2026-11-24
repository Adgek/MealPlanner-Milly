{{--
    The kitchen (MM-15): the recurring projects, and the freezer stash they fill.

    Phone first. The stash counts are the thing that changes most often, so they
    are editable in place with one save for the lot; a project opens its own page
    because it carries an ingredients list.
--}}
<div class="space-y-6">
    <x-page-heading
        title="Kitchen"
        description="What you make ahead, and what is in the freezer because of it. A chaos night or a solo night pulls a portion out of here."
    />

    <x-form.notices :saved="filled($status)" :message="$status" :heading="null" />

    <x-settings-section
        title="Kitchen projects"
        description="Things you do on an “every X days” cadence: stock, sauces, hummus, a big batch of soup. A project that has never been done is due now."
    >
        @forelse ($projects as $project)
            <article wire:key="project-{{ $project->id }}" class="rounded-lg border border-stone-200 p-3">
                <h3 class="text-base font-semibold text-stone-900">{{ $project->name }}</h3>

                <p class="mt-1 text-sm text-stone-600">
                    Every {{ $project->every_days }} {{ Str::plural('day', $project->every_days) }}
                    &middot; {{ $this->dueLabel($project) }}
                </p>

                <p class="mt-1 text-xs text-stone-500">
                    @if ($project->producesFreezerItem)
                        Makes {{ $project->produces_portions }}
                        {{ Str::plural('portion', (int) $project->produces_portions) }}
                        of {{ $project->producesFreezerItem->name }}
                    @else
                        Stocks the fridge, not the freezer
                    @endif
                    &middot;
                    {{ $project->ingredients_count }} {{ Str::plural('ingredient', $project->ingredients_count) }}
                    @if ($project->last_done_on)
                        &middot; last done {{ $project->last_done_on->format('D j M') }}
                    @else
                        &middot; never done
                    @endif
                </p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <x-button as="a" href="{{ route('kitchen.projects.edit', $project) }}" wire:navigate>
                        Edit<span class="sr-only"> {{ $project->name }}</span>
                    </x-button>

                    <x-button tone="danger" wire:click="confirmRemoveProject({{ $project->id }})">
                        Remove<span class="sr-only"> {{ $project->name }}</span>
                    </x-button>
                </div>

                @if ($removingProject === $project->id)
                    <x-confirm-panel
                        :title="'Remove '.$project->name.'?'"
                        :warnings="$this->projectRemovalWarnings()"
                    >
                        <x-button tone="danger" wire:click="removeProject({{ $project->id }})">
                            Remove it
                        </x-button>

                        <x-button wire:click="cancelRemoveProject">Keep it</x-button>
                    </x-confirm-panel>
                @endif
            </article>
        @empty
            <x-empty-state>
                No kitchen projects yet. Add the things you batch ahead — they are what keeps the
                freezer stocked for a solo night.
            </x-empty-state>
        @endforelse

        <x-button tone="primary" as="a" href="{{ route('kitchen.projects.new') }}" wire:navigate>
            Add a kitchen project
        </x-button>
    </x-settings-section>

    <form wire:submit="save" class="space-y-6 pb-4">
        <x-form.notices
            :saved="$saved"
            message="The planner spends a portion when it plans a freezer meal, and puts it back if the night is re-rolled."
        />

        <x-settings-section
            title="Freezer stash"
            description="The 80%-prepped meals and how many portions are left. This is the only thing the app counts, so keep it honest: zero is fine and means you know the meal but have none right now."
        >
            <x-form.error field="rows" />

            @forelse ($rows as $index => $row)
                <div wire:key="stash-row-{{ $index }}-{{ $row['id'] ?? 'new' }}" class="rounded-lg border border-stone-200 p-3">
                    <x-form.input
                        field="rows.{{ $index }}.name"
                        label="Freezer meal"
                        :value="$row['name'] ?? ''"
                        autocomplete="off"
                        maxlength="120"
                        placeholder="Frozen minestrone"
                    />

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <x-form.input
                            field="rows.{{ $index }}.portions_left"
                            label="Portions left"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            max="99"
                            step="1"
                            :value="$row['portions_left'] ?? ''"
                        />

                        <x-form.input
                            field="rows.{{ $index }}.thaw_lead_time_hours"
                            label="Thaw hours"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            max="168"
                            step="1"
                            :value="$row['thaw_lead_time_hours'] ?? ''"
                        />
                    </div>

                    <div class="mt-3">
                        <x-form.textarea
                            field="rows.{{ $index }}.kid_off_ramp"
                            label="Kid off-ramp"
                            rows="2"
                            hint="How to serve it to the kids. Shown with the dinner reminder."
                        >{{ $row['kid_off_ramp'] ?? '' }}</x-form.textarea>
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
                            :title="'Remove '.$row['name'].' from the stash?'"
                            :warnings="$this->usage()?->warnings() ?? []"
                        >
                            <x-button tone="danger" wire:click="retire({{ $row['id'] }})">
                                Take it out of the stash
                            </x-button>

                            @if ($this->usage()?->canBeDeletedForGood())
                                <x-button tone="danger" wire:click="deleteForGood({{ $row['id'] }})">
                                    Delete it for good
                                </x-button>
                            @endif

                            <x-button wire:click="cancelRemove">Keep it</x-button>

                            @unless ($this->usage()?->canBeDeletedForGood())
                                <p class="text-sm text-amber-900 sm:basis-full">
                                    There is no "delete for good" here.
                                    {{ $this->usage()?->whyItCannotBeDeleted() }}
                                    Out of the stash, it stops being planned and everything else stays true.
                                </p>
                            @endunless
                        </x-confirm-panel>
                    @endif
                </div>
            @empty
                <x-empty-state>
                    Nothing in the freezer yet. Add the meals you batch ahead, so a chaos night has
                    something to pull out.
                </x-empty-state>
            @endforelse

            <x-button wire:click="addRow">Add a freezer meal</x-button>
        </x-settings-section>

        <x-save-bar label="Save the stash" note="The counts are what the planner spends." />
    </form>

    @if ($retiredStash->isNotEmpty())
        <x-settings-section
            title="Out of the stash"
            description="Kept, but never planned. The nights these were eaten on are untouched, and so is the count — bring one back and the picker can plan it again."
        >
            @foreach ($retiredStash as $item)
                <article wire:key="retired-stash-{{ $item->id }}" class="rounded-lg border border-stone-200 bg-stone-50 p-3">
                    <h3 class="text-base font-medium text-stone-900">{{ $item->name }}</h3>

                    <p class="mt-1 text-sm text-stone-600">
                        {{ $item->portions_left }} {{ Str::plural('portion', $item->portions_left) }} still counted
                    </p>

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <x-button wire:click="bringBack({{ $item->id }})">
                            Bring back<span class="sr-only"> {{ $item->name }}</span>
                        </x-button>

                        <x-button tone="danger" wire:click="confirmRemove({{ $item->id }})">
                            Delete<span class="sr-only"> {{ $item->name }}</span>
                        </x-button>
                    </div>

                    @if ($removing === $item->id)
                        <x-confirm-panel
                            :title="'Delete '.$item->name.' for good?'"
                            :warnings="$this->usage()?->warnings() ?? []"
                        >
                            @if ($this->usage()?->canBeDeletedForGood())
                                <x-button tone="danger" wire:click="deleteForGood({{ $item->id }})">
                                    Delete it for good
                                </x-button>
                            @else
                                <p class="text-sm text-amber-900 sm:basis-full">
                                    It cannot be deleted. {{ $this->usage()?->whyItCannotBeDeleted() }}
                                    Leaving it out of the stash keeps all of that true.
                                </p>
                            @endif

                            <x-button wire:click="cancelRemove">Keep it</x-button>
                        </x-confirm-panel>
                    @endif
                </article>
            @endforeach
        </x-settings-section>
    @endif
</div>
