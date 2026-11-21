{{--
    A "this arrives in a later ticket" panel. Delete the usages as the real
    content lands; delete this component once nothing uses it.
--}}
@props(['ticket' => null])

<div class="rounded-lg border border-dashed border-stone-300 bg-white p-6">
    <div class="text-stone-700">
        {{ $slot }}
    </div>

    @if ($ticket)
        <p class="mt-4 text-xs font-medium uppercase tracking-wide text-stone-400">
            Arrives in {{ $ticket }}
        </p>
    @endif
</div>
