<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>AI lab</title>
        @fonts
        {{-- The current page's chunk is listed too, so the browser fetches it with app.ts instead of after it. --}}
        @vite(['resources/css/ai.css', 'resources/js/ai/app.ts', "resources/js/ai/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
