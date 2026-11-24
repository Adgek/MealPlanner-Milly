{{--
    The recipe vault (MM-15).

    A phone-first list: one card per recipe, every control a 44px target, and the
    "are you sure" for a removal opening inline under the card it belongs to
    rather than in a dialog.
--}}
<div class="space-y-6">
    <x-page-heading
        title="Recipes"
        description="The vault the picker draws from. Every field the plan, the grocery list and the reminders use lives on a recipe."
    />

    <x-form.notices :saved="filled($status)" :message="$status" :heading="null" />

    <div class="flex flex-col gap-2 sm:flex-row">
        <x-button tone="primary" as="a" href="{{ route('recipes.new') }}" wire:navigate>
            Add a recipe
        </x-button>

        <x-button as="a" href="{{ route('recipes.bulk-ingredients') }}" wire:navigate>
            Bulk ingredients
        </x-button>
    </div>

    <x-settings-section
        title="In the vault"
        description="These are the recipes the picker can choose for a night. {{ $vault->count() }} of them."
    >
        @forelse ($vault as $recipe)
            <article wire:key="recipe-{{ $recipe->id }}" class="rounded-lg border border-stone-200 p-3">
                <h3 class="text-base font-semibold text-stone-900">{{ $recipe->name }}</h3>

                <p class="mt-1 text-sm text-stone-600">
                    {{ $this->whoCanCookIt($recipe) }} &middot; {{ $recipe->effort_tier->label() }}
                </p>

                <p class="mt-1 text-xs text-stone-500">
                    {{ $recipe->ingredients_count }} {{ Str::plural('ingredient', $recipe->ingredients_count) }}
                    &middot;
                    {{ $recipe->prep_steps_count }} {{ Str::plural('prep step', $recipe->prep_steps_count) }}
                    @if ($recipe->bulkIngredients->isNotEmpty())
                        &middot; splits {{ $recipe->bulkIngredients->pluck('name')->join(', ') }}
                    @endif
                    @unless ($recipe->kid_off_ramp)
                        &middot; no kid off-ramp yet
                    @endunless
                </p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <x-button as="a" href="{{ route('recipes.edit', $recipe) }}" wire:navigate>
                        Edit<span class="sr-only"> {{ $recipe->name }}</span>
                    </x-button>

                    <x-button tone="danger" wire:click="confirmRemove({{ $recipe->id }})">
                        Remove<span class="sr-only"> {{ $recipe->name }}</span>
                    </x-button>
                </div>

                @if ($removing === $recipe->id)
                    <x-confirm-panel
                        :title="'Remove '.$recipe->name.' from the vault?'"
                        :warnings="$this->usage()?->warnings() ?? []"
                    >
                        <x-button tone="danger" wire:click="retire({{ $recipe->id }})">
                            Take it out of the vault
                        </x-button>

                        @if ($this->usage()?->canBeDeletedForGood())
                            <x-button tone="danger" wire:click="deleteForGood({{ $recipe->id }})">
                                Delete it for good
                            </x-button>
                        @endif

                        <x-button wire:click="cancelRemove">Keep it</x-button>

                        @unless ($this->usage()?->canBeDeletedForGood())
                            <p class="text-sm text-amber-900 sm:basis-full">
                                There is no "delete for good" here.
                                {{ $this->usage()?->whyItCannotBeDeleted() }}
                                Out of the vault, it stops being planned and everything else stays true.
                            </p>
                        @endunless
                    </x-confirm-panel>
                @endif
            </article>
        @empty
            <x-empty-state>
                No recipes yet. Add the meals you already cook — the picker needs something to
                choose from before it can plan a week.
            </x-empty-state>
        @endforelse
    </x-settings-section>

    @if ($retired->isNotEmpty())
        <x-settings-section
            title="Out of the vault"
            description="Kept, but never planned. The nights these were cooked on, and the record of cooking them, are untouched — bring one back and the picker can choose it again."
        >
            @foreach ($retired as $recipe)
                <article wire:key="retired-{{ $recipe->id }}" class="rounded-lg border border-stone-200 bg-stone-50 p-3">
                    <h3 class="text-base font-medium text-stone-900">{{ $recipe->name }}</h3>

                    <p class="mt-1 text-sm text-stone-600">
                        {{ $this->whoCanCookIt($recipe) }} &middot; {{ $recipe->effort_tier->label() }}
                    </p>

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <x-button wire:click="bringBack({{ $recipe->id }})">
                            Bring back<span class="sr-only"> {{ $recipe->name }}</span>
                        </x-button>

                        <x-button as="a" href="{{ route('recipes.edit', $recipe) }}" wire:navigate>
                            Edit<span class="sr-only"> {{ $recipe->name }}</span>
                        </x-button>

                        <x-button tone="danger" wire:click="confirmRemove({{ $recipe->id }})">
                            Delete<span class="sr-only"> {{ $recipe->name }}</span>
                        </x-button>
                    </div>

                    @if ($removing === $recipe->id)
                        <x-confirm-panel
                            :title="'Delete '.$recipe->name.' for good?'"
                            :warnings="$this->usage()?->warnings() ?? []"
                        >
                            @if ($this->usage()?->canBeDeletedForGood())
                                <x-button tone="danger" wire:click="deleteForGood({{ $recipe->id }})">
                                    Delete it for good
                                </x-button>
                            @else
                                <p class="text-sm text-amber-900 sm:basis-full">
                                    It cannot be deleted. {{ $this->usage()?->whyItCannotBeDeleted() }}
                                    Leaving it out of the vault keeps all of that true.
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
