{{--
    One group of settings, as a card. On a phone the cards are the page's
    structure: each one is a single scrollable block with its own explanation of
    what the values underneath actually do.
--}}
@props(['title', 'description' => null])

<section class="rounded-lg border border-stone-200 bg-white p-4 sm:p-6">
    <h2 class="text-base font-semibold text-stone-900">{{ $title }}</h2>

    @if ($description)
        <p class="mt-1 text-sm text-stone-600">{{ $description }}</p>
    @endif

    <div class="mt-4 space-y-4">
        {{ $slot }}
    </div>
</section>
