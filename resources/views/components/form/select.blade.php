{{--
    A labelled <select> bound to a Livewire property.

    `options` is value => label, or group => [value => label] for a grouped
    picker (the time-zone list). `selected` is the value the form currently
    holds: it has to be rendered server-side, because the first paint of a
    Livewire page is plain HTML and an unmarked option would quietly show the
    household the wrong setting.
--}}
@props([
    'field',
    'label',
    'options' => [],
    'selected' => null,
    'hint' => null,
    'labelClass' => '',
])

@php
    $id = str_replace(['.', '*'], '-', $field);
    $invalid = $errors->has($field);
    $isSelected = fn ($value) => (string) $value === (string) $selected;
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-stone-700 {{ $labelClass }}">
        {{ $label }}
    </label>

    <select
        id="{{ $id }}"
        @if ($invalid)
            aria-invalid="true"
            aria-describedby="{{ $id }}-error"
        @endif
        {{ $attributes->merge([
            'wire:model' => $field,
            'class' => 'mt-1 block min-h-11 w-full rounded-md border bg-white px-3 py-2 text-base text-stone-900 shadow-sm transition focus:outline-none focus:ring-2 '
                .($invalid
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500/20'
                    : 'border-stone-300 focus:border-stone-500 focus:ring-stone-900/15'),
        ]) }}
    >
        @foreach ($options as $key => $option)
            @if (is_array($option))
                <optgroup label="{{ $key }}">
                    @foreach ($option as $value => $optionLabel)
                        <option value="{{ $value }}" @selected($isSelected($value))>{{ $optionLabel }}</option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $key }}" @selected($isSelected($key))>{{ $option }}</option>
            @endif
        @endforeach
    </select>

    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif

    <x-form.error :field="$field" :id="$id.'-error'" />
</div>
