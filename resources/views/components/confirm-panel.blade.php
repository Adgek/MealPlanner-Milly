{{--
    "Are you sure?", inline rather than in a dialog.

    Removing anything the plan can point at opens one of these under the row it
    belongs to: what the removal touches, in sentences, and then the buttons. In
    the flow of the page rather than over it, so it needs no focus trapping and
    works on a phone with the keyboard up.

    `warnings` is a list of plain sentences (App\Vault\MealUsage::warnings()).
--}}
@props([
    'title',
    'warnings' => [],
])

<div
    role="group"
    aria-label="{{ $title }}"
    class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3"
>
    <p class="text-sm font-semibold text-amber-900">{{ $title }}</p>

    @if (count($warnings) > 0)
        <ul class="mt-2 space-y-1 text-sm text-amber-900">
            @foreach ($warnings as $warning)
                <li class="flex gap-2">
                    <span aria-hidden="true">&middot;</span>
                    <span>{{ $warning }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        {{ $slot }}
    </div>
</div>
