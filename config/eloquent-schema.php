<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching for model schema discovery. Set to 0 to disable caching.
    |
    */

    'cache_ttl' => env('ELOQUENT_SCHEMA_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Model Discovery Configuration
    |--------------------------------------------------------------------------
    |
    | Configure paths and namespaces for automatic model discovery.
    |
    */

    'model_paths' => [
        // app_path('Models'),
    ],

    'additional_models' => [
        // \App\Models\SomeModel::class,
    ],

    'excluded_models' => [
        // \App\Models\ExcludedModel::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor Package Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which vendor packages to include when include_vendor is true.
    | Use composer package names (e.g., 'spatie/laravel-permission').
    | If empty, no vendor packages will be scanned.
    |
    */

    'included_packages' => [
        // 'spatie/laravel-permission',
        // 'spatie/laravel-medialibrary',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Tools Configuration
    |--------------------------------------------------------------------------
    |
    | Enable or disable MCP tools. Requires laravel/mcp package.
    |
    */

    'mcp' => [
        'enabled' => true,
    ],

];
