{{--
    The app's navigation. Add a link here when a ticket adds a page.
    `wire:navigate` swaps pages without a full reload, which keeps the kitchen
    tablet (MM-14) responsive.

    One link per thing the household looks after, which is also how AppPlan.md
    divides what the system tracks: the recipe vault, the kitchen projects and
    the freezer stash, and the kid off-ramp inventory. The editors underneath
    (a single recipe, a single project, the bulk ingredients) are reached from
    their list page instead, so this stays readable on a phone. `match` is the
    route pattern that keeps the parent tab marked while you are in one of them.
--}}
@php
    $links = [
        ['route' => 'plan', 'label' => 'Plan', 'match' => 'plan'],
        ['route' => 'recipes', 'label' => 'Recipes', 'match' => 'recipes*'],
        ['route' => 'kitchen', 'label' => 'Kitchen', 'match' => 'kitchen*'],
        ['route' => 'kid-foods', 'label' => 'Kid foods', 'match' => 'kid-foods'],
        ['route' => 'settings', 'label' => 'Settings', 'match' => 'settings'],
    ];
@endphp

<nav aria-label="Main" class="-mx-1 flex flex-wrap items-center gap-1">
    @foreach ($links as $link)
        @php($active = request()->routeIs($link['match'] ?? $link['route']))

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
