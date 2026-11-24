{{--
    A button, in the three tones the edit screens need: the quiet one for "add a
    row", the loud one for "remove it", and the plain one for a link that acts
    like a button.

    Always at least 44px tall and full width on a phone, so every one of them can
    be hit with a thumb; `sm:w-auto` lets them sit in a row on a bigger screen.
    Pass `as="a"` with an href for a link that should look the same.
--}}
@props([
    'tone' => 'secondary',
    'as' => 'button',
    'block' => true,
])

@php
    $tones = [
        'primary' => 'border-transparent bg-stone-900 text-white hover:bg-stone-800 focus:ring-stone-900/30',
        'secondary' => 'border-stone-300 bg-white text-stone-700 hover:bg-stone-50 focus:ring-stone-900/15',
        'danger' => 'border-red-300 bg-white text-red-700 hover:bg-red-50 focus:ring-red-500/20',
    ];

    $classes = 'inline-flex min-h-11 items-center justify-center rounded-md border px-4 text-sm font-medium transition '
        .'focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-50 '
        .($block ? 'w-full sm:w-auto ' : '')
        .($tones[$tone] ?? $tones['secondary']);
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
