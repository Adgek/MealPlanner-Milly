{{--
    The app's navigation. Add a link here when a ticket adds a page.
    `wire:navigate` swaps pages without a full reload, which keeps the kitchen
    tablet (MM-14) responsive.
--}}
@php
    $links = [
        ['route' => 'plan', 'label' => 'Plan'],
        ['route' => 'settings', 'label' => 'Settings'],
    ];
@endphp

<nav aria-label="Main" class="-mx-1 flex items-center gap-1">
    @foreach ($links as $link)
        @php($active = request()->routeIs($link['route']))

        <a
            href="{{ route($link['route']) }}"
            wire:navigate
            @if ($active) aria-current="page" @endif
            class="rounded-md px-3 py-2 text-sm font-medium transition
                {{ $active
                    ? 'bg-stone-900 text-white'
                    : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900' }}"
        >
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
