<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Visualbuilder\EloquentSchema\EloquentSchemaServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            EloquentSchemaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('eloquent-schema.cache_ttl', 0);
        $app['config']->set('eloquent-schema.model_paths', [
            __DIR__.'/Fixtures/Models' => 'Visualbuilder\\EloquentSchema\\Tests\\Fixtures\\Models',
        ]);
    }
}
