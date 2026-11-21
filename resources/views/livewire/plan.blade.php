<div>
    <x-page-heading
        title="Plan"
        description="The meals for the current shopping window, who is cooking each night, and the grocery list."
    />

    <div class="space-y-6">
        <x-placeholder-panel ticket="MM-8">
            <p class="font-medium text-stone-900">Nothing planned yet.</p>
            <p class="mt-2">
                This page will show each night in the window with its cook, its time budget and
                its kid off-ramp, plus the grocery list that both partners tick off together.
            </p>
        </x-placeholder-panel>

        {{--
            A live region, so the Livewire wiring that MM-8 and MM-14 depend on is
            visibly working from day one. wire:poll re-renders this component on an
            interval without a page reload.
        --}}
        <div
            wire:poll.5s
            class="flex items-baseline justify-between rounded-lg border border-stone-200 bg-white px-6 py-4"
        >
            <span class="text-sm font-medium text-stone-500">
                Kitchen clock ({{ config('app.timezone') }})
            </span>
            <span class="font-mono text-sm text-stone-900" data-testid="household-time">
                {{ $this->householdTime() }}
            </span>
        </div>
    </div>
</div>
