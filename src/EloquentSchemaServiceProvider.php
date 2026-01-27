<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema;

use Illuminate\Support\ServiceProvider;
use Visualbuilder\EloquentSchema\Console\Commands\CacheModelsCommand;
use Visualbuilder\EloquentSchema\Console\Commands\ClearCacheCommand;
use Visualbuilder\EloquentSchema\Console\Commands\DiscoverVendorModelsCommand;
use Visualbuilder\EloquentSchema\Console\Commands\ListModelsCommand;
use Visualbuilder\EloquentSchema\Console\Commands\McpCallCommand;
use Visualbuilder\EloquentSchema\Console\Commands\McpToolsCommand;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

class EloquentSchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/eloquent-schema.php',
            'eloquent-schema'
        );

        $this->app->singleton(ModelSchemaService::class, function ($app) {
            return new ModelSchemaService(
                cacheTtl: (int) config('eloquent-schema.cache_ttl', 3600)
            );
        });

        $this->app->singleton(ModelDiscoveryService::class, function ($app) {
            return new ModelDiscoveryService(
                cacheTtl: (int) config('eloquent-schema.cache_ttl', 3600)
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/eloquent-schema.php' => config_path('eloquent-schema.php'),
            ], 'eloquent-schema-config');

            $this->commands([
                CacheModelsCommand::class,
                ClearCacheCommand::class,
                DiscoverVendorModelsCommand::class,
                ListModelsCommand::class,
                McpCallCommand::class,
                McpToolsCommand::class,
            ]);
        }
    }

    /**
     * Get the MCP tools provided by this package.
     *
     * @return array<class-string>
     */
    public static function mcpTools(): array
    {
        if (! class_exists(\Laravel\Mcp\Server\Tool::class)) {
            return [];
        }

        return [
            \Visualbuilder\EloquentSchema\Mcp\Tools\ListModels::class,
            \Visualbuilder\EloquentSchema\Mcp\Tools\ModelFields::class,
            \Visualbuilder\EloquentSchema\Mcp\Tools\ModelSchema::class,
        ];
    }
}
