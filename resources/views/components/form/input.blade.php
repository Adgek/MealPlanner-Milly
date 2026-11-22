{{--
    A labelled text, time or number input bound to a Livewire property.

    `field` is the property name, and doubles as the wire:model target, the id
    and the validation key — one name per field, so a renamed property can't
    leave a label pointing at nothing.

    Sized for a phone: full width, a 44px tap target, and 16px text, which is
    what stops iOS Safari zooming the page when a field takes focus.
--}}
@props([
    'field',
    'label',
    'hint' => null,
    'labelClass' => '',
])

@php
    $id = str_replace(['.', '*'], '-', $field);
    $invalid = $errors->has($field);
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-stone-700 {{ $labelClass }}">
        {{ $label }}
    </label>

    <input
        id="{{ $id }}"
        @if ($invalid)
            aria-invalid="true"
            aria-describedby="{{ $id }}-error"
        @endif
        {{ $attributes->merge([
            'type' => 'text',
            'wire:model' => $field,
            'class' => 'mt-1 block min-h-11 w-full rounded-md border bg-white px-3 py-2 text-base text-stone-900 shadow-sm transition focus:outline-none focus:ring-2 '
                .($invalid
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500/20'
                    : 'border-stone-300 focus:border-stone-500 focus:ring-stone-900/15'),
        ]) }}
    >

    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif

    <x-form.error :field="$field" :id="$id.'-error'" />
</div>
