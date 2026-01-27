<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Facades;

use Illuminate\Support\Facades\Facade;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

/**
 * @method static array|null getSchema(string $modelClass, int $maxDepth = 2)
 * @method static void clearCache(string $modelClass, int $maxDepth = 2)
 * @method static array getFlatFieldList(string $modelClass, int $maxDepth = 2)
 *
 * @see \Visualbuilder\EloquentSchema\Services\ModelSchemaService
 */
class EloquentSchema extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModelSchemaService::class;
    }
}
