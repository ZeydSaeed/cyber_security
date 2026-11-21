<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Platform\Http\Middleware\SetLocale::direction() }}" data-density="{{ $density ?? 'comfortable' }}" @class(['dark' => ($appearance ?? 'light') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- The page background before the stylesheet arrives: generated with
             the palette (scripts/design-system/palette.mjs). --}}
        @include('design-system.prepaint')

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        {{-- The page's own chunk; module pages live at modules/<name>/pages (ADR-0033, as in app.tsx). --}}
        @vite(['resources/css/app.css', 'resources/js/app.tsx', str_starts_with($page['component'], 'modules/') ? "resources/js/{$page['component']}.tsx" : "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
