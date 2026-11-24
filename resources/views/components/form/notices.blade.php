{{--
    What the last save did, at the top of a form.

    Two banners, because they answer two different questions: "did that work?"
    and "why did nothing happen?". The error one is deliberately blunt — a save
    that fails validation writes nothing at all (MM-4), and the household has to
    know that rather than assume half of it went in.

    `saved` is the component's own flag, or a session flash on a page that was
    redirected to after saving somewhere else.
--}}
@props([
    'saved' => false,
    'message' => null,
    'heading' => 'Saved.',
])

@if ($saved)
    <div
        role="status"
        class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
    >
        @if ($heading)
            <span class="font-medium">{{ $heading }}</span>
        @endif
        {{ $message }}
    </div>
@endif

@if ($errors->any())
    <div
        role="alert"
        class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
    >
        <span class="font-medium">Nothing was saved.</span>
        Check the fields marked below, then save again.
    </div>
@endif
