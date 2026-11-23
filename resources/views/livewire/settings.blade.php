{{--
    The settings page (MM-4): every household value on one page.

    Built for a phone first — one column of cards, full-width controls, 44px tap
    targets — and widened at `sm` for the kitchen tablet and a laptop.
--}}
<div>
    <x-page-heading
        title="Settings"
        description="Every household value lives here, so changing the week never means changing code."
    />

    <form wire:submit="save" class="space-y-6 pb-4">
        @if ($saved)
            <div
                role="status"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            >
                <span class="font-medium">Saved.</span>
                The next plan uses these settings. The plan already on the fridge keeps what it
                was made with until someone re-plans it.
            </div>
        @endif

        @if ($errors->any())
            <div
                role="alert"
                class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
            >
                <span class="font-medium">Nothing was saved.</span>
                Check the fields marked below, then save again.
            </div>
        @endif

        <x-settings-section
            title="The cooks"
            description="Who the two of you are. These names appear on the plan, in the reminders and in the week below."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input
                    field="cookNames.me"
                    label="Your name"
                    :value="$cookNames['me'] ?? ''"
                    autocomplete="off"
                    maxlength="40"
                />

                <x-form.input
                    field="cookNames.partner"
                    label="Your partner's name"
                    :value="$cookNames['partner'] ?? ''"
                    autocomplete="off"
                    maxlength="40"
                />
            </div>
        </x-settings-section>

        <x-settings-section
            title="The week"
            description="The normal week: who cooks each night and how much time they have. A one-off change for a single date belongs on the plan page, not here."
        >
            <x-form.error field="schedule" />

            <div class="hidden text-xs font-medium uppercase tracking-wide text-stone-500 sm:grid sm:grid-cols-[6rem_1fr_1fr] sm:gap-4">
                <span>Night</span>
                <span>Who cooks</span>
                <span>How much time</span>
            </div>

            <div class="space-y-3 sm:space-y-0">
                @foreach ($this->nights() as $night)
                    <div
                        wire:key="night-{{ $night->value }}"
                        class="rounded-lg border border-stone-200 p-3 sm:grid sm:grid-cols-[6rem_1fr_1fr] sm:items-center sm:gap-4 sm:rounded-none sm:border-0 sm:border-t sm:border-stone-100 sm:p-0 sm:py-2"
                    >
                        <div class="mb-2 text-sm font-semibold text-stone-900 sm:mb-0 sm:font-medium">
                            {{ $night->label() }}
                        </div>

                        <div class="grid grid-cols-2 gap-2 sm:contents">
                            <x-form.select
                                field="schedule.{{ $night->value }}.cook"
                                label="Who cooks"
                                label-class="text-xs font-normal text-stone-500 sm:sr-only"
                                aria-label="Who cooks on {{ $night->label() }}"
                                :options="$this->cookOptions()"
                                :selected="$schedule[$night->value]['cook'] ?? null"
                            />

                            <x-form.select
                                field="schedule.{{ $night->value }}.time_budget"
                                label="How much time"
                                label-class="text-xs font-normal text-stone-500 sm:sr-only"
                                aria-label="How much time there is on {{ $night->label() }}"
                                :options="$this->timeBudgetOptions()"
                                :selected="$schedule[$night->value]['time_budget'] ?? null"
                            />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-settings-section>

        <x-settings-section
            title="Shopping days"
            description="A shopping day covers every night from the next day through to the next shop. With Sunday and Wednesday, Sunday covers Monday to Wednesday and Wednesday covers Thursday to Sunday."
        >
            @if ($this->shoppingSummary() !== '')
                <p class="text-sm text-stone-600">
                    Right now: <span class="font-medium text-stone-900">{{ $this->shoppingSummary() }}</span>
                </p>
            @endif

            <x-form.error field="shoppingTimes" />

            @forelse ($shoppingTimes as $index => $window)
                <div
                    wire:key="shopping-{{ $index }}-{{ $window['weekday'] ?? 'none' }}"
                    class="rounded-lg border border-stone-200 p-3 sm:flex sm:items-start sm:gap-3 sm:rounded-none sm:border-0 sm:border-t sm:border-stone-100 sm:p-0 sm:pt-3"
                >
                    <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-1 sm:gap-3">
                        <x-form.select
                            field="shoppingTimes.{{ $index }}.weekday"
                            label="Day"
                            aria-label="Shopping day {{ $index + 1 }}"
                            :options="$this->weekdayOptions()"
                            :selected="$window['weekday'] ?? null"
                            class="sm:w-48"
                        />

                        <x-form.input
                            field="shoppingTimes.{{ $index }}.time"
                            label="Time"
                            aria-label="Time of shopping day {{ $index + 1 }}"
                            type="time"
                            :value="$window['time'] ?? ''"
                            class="sm:w-40"
                        />
                    </div>

                    <button
                        type="button"
                        wire:click="removeShoppingTime({{ $index }})"
                        class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-md border border-stone-300 px-4 text-sm font-medium text-stone-700 transition hover:bg-stone-50 sm:mt-6 sm:w-auto"
                    >
                        Remove<span class="sr-only"> shopping day {{ $index + 1 }}</span>
                    </button>
                </div>
            @empty
                <p class="rounded-lg border border-dashed border-stone-300 px-4 py-3 text-sm text-stone-600">
                    No shopping days. Add at least one: the plan is built around the shop.
                </p>
            @endforelse

            <button
                type="button"
                wire:click="addShoppingTime"
                @disabled(count($shoppingTimes) >= 7)
                class="inline-flex min-h-11 w-full items-center justify-center rounded-md border border-stone-300 px-4 text-sm font-medium text-stone-700 transition hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
            >
                Add a shopping day
            </button>
        </x-settings-section>

        <x-settings-section
            title="The prep block"
            description="The weekly kitchen block the week is built around: stock and sauces, beans soaked for Wednesday's chili, soups batched for the freezer."
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <x-form.select
                    field="prepBlock.weekday"
                    label="Day"
                    :options="$this->weekdayOptions()"
                    :selected="$prepBlock['weekday'] ?? null"
                />

                <x-form.input
                    field="prepBlock.start"
                    label="Starts"
                    type="time"
                    :value="$prepBlock['start'] ?? ''"
                />

                <x-form.input
                    field="prepBlock.end"
                    label="Ends"
                    type="time"
                    :value="$prepBlock['end'] ?? ''"
                />
            </div>
        </x-settings-section>

        <x-settings-section
            title="When things happen"
            description="Every time on this page is a time on the kitchen clock. The app stores instants in UTC and renders them in the zone below, so these stay right across a time change."
        >
            <x-form.select
                field="timeZone"
                label="Time zone"
                :options="$this->timeZoneOptions()"
                :selected="$timeZone"
                hint="The clock the household reads, and the one the reminders fire on."
            />

            <div class="grid gap-4 sm:grid-cols-3">
                <x-form.input
                    field="dinnerTime"
                    label="Dinner"
                    type="time"
                    :value="$dinnerTime"
                    hint="When dinner lands on the table."
                />

                <x-form.input
                    field="planPostTime"
                    label="Plan goes out"
                    type="time"
                    :value="$planPostTime"
                    hint="The evening before each shopping day."
                />

                <x-form.input
                    field="dailyDinnerReminderTime"
                    label="Daily reminder"
                    type="time"
                    :value="$dailyDinnerReminderTime"
                    hint="Tonight's dinner, who is cooking, and the kid off-ramp."
                />
            </div>
        </x-settings-section>

        <x-settings-section
            title="Planning rules"
            description="The numbers the planner works to when it pairs meals and watches the freezer."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input
                    field="rawSplitFridgeLifeDays"
                    label="Raw-split fridge life (days)"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="14"
                    step="1"
                    :value="$rawSplitFridgeLifeDays"
                    hint="How long the raw second half of a bulk pack keeps, counted from the shop. Past that, the plan says freeze it. An ingredient that records its own fridge life wins."
                />

                <x-form.input
                    field="lowFreezerStashPortions"
                    label="Low freezer stash (portions)"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    max="99"
                    step="1"
                    :value="$lowFreezerStashPortions"
                    hint="Warn when the 80%-prepped stash drops to this many portions. Zero means never warn."
                />
            </div>
        </x-settings-section>

        {{--
            Sticky on a phone, so the save button is reachable from anywhere in a
            long form without scrolling to the bottom.
        --}}
        <div class="sticky bottom-0 -mx-4 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-stone-500">Changes apply to the next plan.</p>

                <button
                    type="submit"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-stone-900 px-5 text-base font-medium text-white transition hover:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-stone-900/30 disabled:opacity-60 sm:w-auto"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    <span wire:loading.remove wire:target="save">Save settings</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </form>
</div>
