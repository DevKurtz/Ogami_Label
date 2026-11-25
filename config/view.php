<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Laravel compiles Blade templates to this directory. On Vercel the app
    | storage path is redirected to a writable temporary directory during
    | container startup, so resolve the compiled view path from that location.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views')) ?: storage_path('framework/views'),
    ),

];
