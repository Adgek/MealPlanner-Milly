{{--
    The save button for a long form.

    Sticky at the bottom on a phone, so it is reachable with a thumb from
    anywhere in the form without scrolling to the end; a normal card from `sm`
    up, where the whole form is on screen anyway.
--}}
@props([
    'label' => 'Save',
    'note' => null,
    'target' => 'save',
])

<div class="sticky bottom-0 -mx-4 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border sm:px-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        @if ($note)
            <p class="text-sm text-stone-500">{{ $note }}</p>
        @endif

        <button
            type="submit"
            class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-stone-900 px-5 text-base font-medium text-white transition hover:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-stone-900/30 disabled:opacity-60 sm:w-auto"
            wire:loading.attr="disabled"
            wire:target="{{ $target }}"
        >
            <span wire:loading.remove wire:target="{{ $target }}">{{ $label }}</span>
            <span wire:loading wire:target="{{ $target }}">Saving…</span>
        </button>
    </div>
</div>
