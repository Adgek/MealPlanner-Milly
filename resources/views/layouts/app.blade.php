{{--
    The shared shell every page renders into.

    Livewire full-page components use this layout by default (see
    config/livewire.php, 'component_layout' => 'layouts::app'), so a new page only
    needs a route and a component — not its own <html> boilerplate.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1c1917">

    {{-- Livewire components set this with the #[Title] attribute. --}}
    <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-stone-100 text-stone-900 antialiased">
    <div class="flex min-h-full flex-col">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4 sm:px-6">
                <a
                    href="{{ route('plan') }}"
                    wire:navigate
                    class="text-lg font-semibold tracking-tight text-stone-900"
                >
                    {{ config('app.name') }}
                </a>

                <x-navigation />
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-stone-200 bg-white">
            <div class="mx-auto w-full max-w-5xl px-4 py-4 text-sm text-stone-500 sm:px-6">
                Runs at home on the private network. No sign-in by design.
            </div>
        </footer>
    </div>
</body>
</html>
