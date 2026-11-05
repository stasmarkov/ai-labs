<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Early Hints
    |--------------------------------------------------------------------------
    |
    | When on, the listing detail route sends a 103 response with the asset
    | preload links before the page is built. It only does anything on a SAPI
    | that can send one (FrankenPHP); everywhere else the request runs as before.
    | "early_hint_entries" are the Vite entries read from the build manifest.
    |
    */

    'early_hints' => filled(env('EARLY_HINTS')) ? filter_var(env('EARLY_HINTS'), FILTER_VALIDATE_BOOL) : true,

    'early_hint_build_directory' => 'build',

    'early_hint_entries' => ['resources/css/app.css', 'resources/js/app.js'],

];
