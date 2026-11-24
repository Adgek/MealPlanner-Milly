{{--
    A labelled textarea bound to a Livewire property, for the notes the household
    writes in sentences: a kid off-ramp, a prep step's instructions.

    The same contract as x-form.input — `field` is the property name, the id and
    the validation key — so the two can be swapped without touching anything
    else. 16px text, because iOS Safari zooms the page for anything smaller.
--}}
@props([
    'field',
    'label',
    'hint' => null,
    'labelClass' => '',
    'rows' => 2,
])

@php
    $id = str_replace(['.', '*'], '-', $field);
    $invalid = $errors->has($field);
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-stone-700 {{ $labelClass }}">
        {{ $label }}
    </label>

    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        @if ($invalid)
            aria-invalid="true"
            aria-describedby="{{ $id }}-error"
        @endif
        {{ $attributes->merge([
            'wire:model' => $field,
            'class' => 'mt-1 block w-full rounded-md border bg-white px-3 py-2 text-base text-stone-900 shadow-sm transition focus:outline-none focus:ring-2 '
                .($invalid
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500/20'
                    : 'border-stone-300 focus:border-stone-500 focus:ring-stone-900/15'),
        ]) }}
    >{{ $slot }}</textarea>

    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif

    <x-form.error :field="$field" :id="$id.'-error'" />
</div>
