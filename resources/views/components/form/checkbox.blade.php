{{--
    A checkbox bound to a Livewire property: a pantry basic, a bulk ingredient
    tag.

    The label wraps the box so the whole line is the tap target, which is what
    makes it usable one-handed. `checked` is rendered server-side for the same
    reason x-form.select renders `selected`: the first paint of a Livewire page is
    plain HTML, and an unticked box would quietly show the household the wrong
    value.
--}}
@props([
    'field',
    'label',
    'checked' => false,
    'hint' => null,
    'id' => null,
])

@php
    // Several boxes can share one property — the bulk-pack tags are one array of
    // ids — so a box in a group names its own id rather than the property's.
    $id = $id ?? str_replace(['.', '*'], '-', $field);
@endphp

<div>
    <label for="{{ $id }}" class="flex min-h-11 items-center gap-3">
        <input
            id="{{ $id }}"
            type="checkbox"
            @checked($checked)
            {{ $attributes->merge([
                'wire:model' => $field,
                'class' => 'size-5 shrink-0 rounded border-stone-300 text-stone-900 focus:ring-2 focus:ring-stone-900/20',
            ]) }}
        >

        <span class="text-sm text-stone-700">{{ $label }}</span>
    </label>

    @if ($hint)
        <p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>
    @endif

    <x-form.error :field="$field" />
</div>
