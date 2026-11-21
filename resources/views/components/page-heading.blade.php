@props(['title', 'description' => null])

<div class="mb-8">
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ $title }}</h1>

    @if ($description)
        <p class="mt-1 max-w-2xl text-stone-600">{{ $description }}</p>
    @endif
</div>
