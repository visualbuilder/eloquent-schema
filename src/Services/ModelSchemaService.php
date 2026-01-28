<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Services;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Service to analyze Eloquent models and extract their schema information.
 */
class ModelSchemaService
{
    /**
     * Relationship types that return collections.
     */
    protected const COLLECTION_RELATIONS = [
        HasMany::class,
        BelongsToMany::class,
        HasManyThrough::class,
        MorphMany::class,
        MorphToMany::class,
    ];

    /**
     * All supported relationship types.
     */
    protected const RELATION_TYPES = [
        BelongsTo::class,
        HasOne::class,
        HasMany::class,
        BelongsToMany::class,
        HasOneThrough::class,
        HasManyThrough::class,
        MorphTo::class,
        MorphOne::class,
        MorphMany::class,
        MorphToMany::class,
    ];

    /**
     * Method name prefixes to exclude from relationship scanning.
     */
    protected const EXCLUDED_PREFIXES = ['get', 'set', 'scope'];

    /**
     * Specific method names to exclude from relationship scanning.
     */
    protected const EXCLUDED_METHODS = [
        'getKey', 'getKeyName', 'getKeyType', 'getTable', 'getConnectionName',
        'getConnection', 'getFillable', 'getGuarded', 'getHidden', 'getVisible',
        'getCasts', 'getDates', 'getAttributes', 'getOriginal', 'getChanges',
        'getDirty', 'getRelations', 'getRouteKey', 'getRouteKeyName', 'getForeignKey',
        'getQualifiedKeyName', 'getCreatedAtColumn', 'getUpdatedAtColumn',
        'getDeletedAtColumn', 'getMorphClass', 'getIncrementing', 'getPerPage',
        'bootIfNotBooted', 'booting', 'booted', 'clearBootedModels',
        'withoutTouching', 'withoutTouchingOn', 'isIgnoringTouch',
        'newQuery', 'newModelQuery', 'newQueryWithoutRelationships',
        'newQueryWithoutScopes', 'query',
        'toArray', 'toJson', 'jsonSerialize',
        '__construct', '__get', '__set', '__isset', '__unset',
        '__call', '__callStatic', '__toString', '__sleep', '__wakeup',
    ];

    /**
     * Whether to use reference-based caching (stores depth 1 only, composes depth 2+).
     */
    protected bool $useReferenceCaching = true;

    public function __construct(
        protected int $cacheTtl = 3600,
    ) {}

    /**
     * Get the complete schema for a model class.
     *
     * Returns a deduplicated schema where each related model appears only once
     * in a 'definitions' object. Relationships reference these definitions
     * instead of duplicating the schema inline.
     */
    public function getSchema(string $modelClass, int $maxDepth = 2): ?array
    {
        if (! $this->isValidModel($modelClass)) {
            return null;
        }

        // Always use deduplicated format to minimize payload size
        return $this->getDeduplicatedSchema($modelClass, $maxDepth);
    }

    /**
     * Get schema in deduplicated format with definitions.
     *
     * Returns structure:
     * [
     *   'model' => 'App\Models\User',
     *   'columns' => [...],
     *   'relationships' => [
     *     'posts' => ['name' => 'posts', 'related_model' => 'App\Models\Post', ...]
     *   ],
     *   'definitions' => [
     *     'App\Models\Post' => ['model' => '...', 'columns' => [...], 'relationships' => [...]]
     *   ]
     * ]
     */
    public function getDeduplicatedSchema(string $modelClass, int $maxDepth = 2): ?array
    {
        if (! $this->isValidModel($modelClass)) {
            return null;
        }

        $definitions = [];
        $schema = $this->buildSchemaCollectingDefinitions($modelClass, 0, $maxDepth, [], $definitions);
        $schema['definitions'] = $definitions;

        return $schema;
    }

    /**
     * Build schema while collecting unique model definitions.
     *
     * Instead of embedding full schemas in relationships, we collect each
     * unique model's schema in $definitions and relationships just reference them.
     */
    protected function buildSchemaCollectingDefinitions(
        string $modelClass,
        int $depth,
        int $maxDepth,
        array $visited,
        array &$definitions
    ): array {
        if (in_array($modelClass, $visited)) {
            return [
                'model' => $modelClass,
                'model_short' => class_basename($modelClass),
                'circular' => true,
            ];
        }

        $model = new $modelClass;
        $newVisited = [...$visited, $modelClass];

        $schema = [
            'model' => $modelClass,
            'model_short' => class_basename($modelClass),
            'table' => $model->getTable(),
            'columns' => $this->getColumns($model),
            'relationships' => [],
        ];

        if ($depth < $maxDepth) {
            $schema['relationships'] = $this->getRelationshipsWithDefinitions(
                $model,
                $modelClass,
                $depth,
                $maxDepth,
                $newVisited,
                $definitions
            );
        }

        return $schema;
    }

    /**
     * Get relationships, adding related model schemas to definitions instead of inline.
     */
    protected function getRelationshipsWithDefinitions(
        Model $model,
        string $modelClass,
        int $depth,
        int $maxDepth,
        array $visited,
        array &$definitions
    ): array {
        $reflection = new ReflectionClass($model);

        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->class === $modelClass)
            ->reject(fn (ReflectionMethod $method) => $method->getNumberOfRequiredParameters() > 0)
            ->reject(fn (ReflectionMethod $method) => $this->isExcludedMethod($method->getName()))
            ->mapWithKeys(function (ReflectionMethod $method) use ($model, $depth, $maxDepth, $visited, &$definitions) {
                return $this->parseRelationshipWithDefinitions(
                    $model,
                    $method,
                    $depth,
                    $maxDepth,
                    $visited,
                    $definitions
                );
            })
            ->filter()
            ->all();
    }

    /**
     * Parse a relationship method, storing related schema in definitions.
     */
    protected function parseRelationshipWithDefinitions(
        Model $model,
        ReflectionMethod $method,
        int $depth,
        int $maxDepth,
        array $visited,
        array &$definitions
    ): array {
        // Check return type hint first
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && in_array($returnType->getName(), self::RELATION_TYPES)) {
            $info = $this->buildRelationshipWithDefinitions($model, $method, $depth, $maxDepth, $visited, $definitions);

            return $info ? [$method->getName() => $info] : [];
        }

        // If method has a non-relation return type, skip invoking it
        if ($returnType instanceof ReflectionNamedType && ! in_array($returnType->getName(), ['mixed', 'void', 'null', 'static', 'self'])) {
            return [];
        }

        // Try invoking the method (only for methods without type hints)
        try {
            $result = $method->invoke($model);

            if ($result instanceof Relation) {
                return [$method->getName() => $this->buildRelationshipInfoWithDefinitions(
                    $result,
                    $method->getName(),
                    $depth,
                    $maxDepth,
                    $visited,
                    $definitions
                )];
            }
        } catch (\Throwable) {
            // Method threw an exception, skip it
        }

        return [];
    }

    /**
     * Build relationship info by invoking the method, storing related schema in definitions.
     */
    protected function buildRelationshipWithDefinitions(
        Model $model,
        ReflectionMethod $method,
        int $depth,
        int $maxDepth,
        array $visited,
        array &$definitions
    ): ?array {
        try {
            $result = $method->invoke($model);

            return $result instanceof Relation
                ? $this->buildRelationshipInfoWithDefinitions($result, $method->getName(), $depth, $maxDepth, $visited, $definitions)
                : null;
        } catch (\Throwable) {
            $typeName = $method->getReturnType()->getName();

            return [
                'name' => $method->getName(),
                'type' => class_basename($typeName),
                'type_full' => $typeName,
                'is_collection' => in_array($typeName, self::COLLECTION_RELATIONS),
            ];
        }
    }

    /**
     * Build relationship info, adding related model's schema to definitions (once).
     */
    protected function buildRelationshipInfoWithDefinitions(
        Relation $relation,
        string $name,
        int $depth,
        int $maxDepth,
        array $visited,
        array &$definitions
    ): array {
        $relatedModel = get_class($relation->getRelated());
        $typeName = get_class($relation);

        $info = [
            'name' => $name,
            'type' => class_basename($typeName),
            'type_full' => $typeName,
            'related_model' => $relatedModel,
            'related_model_short' => class_basename($relatedModel),
            'is_collection' => in_array($typeName, self::COLLECTION_RELATIONS),
        ];

        // Add related model to definitions if not already there and not circular
        if ($depth + 1 <= $maxDepth && ! in_array($relatedModel, $visited)) {
            if (! isset($definitions[$relatedModel])) {
                // Build and store the related model's schema in definitions
                $definitions[$relatedModel] = $this->buildSchemaCollectingDefinitions(
                    $relatedModel,
                    $depth + 1,
                    $maxDepth,
                    $visited,
                    $definitions
                );
            }
        }

        return $info;
    }

    /**
     * Get the base (depth 1) schema for a model, from cache or freshly built.
     */
    protected function getBaseSchema(string $modelClass): array
    {
        return Cache::remember(
            $this->getCacheKey($modelClass, 1),
            $this->cacheTtl,
            fn () => $this->buildSchema($modelClass, 0, 1, [])
        );
    }

    /**
     * Clear the cached schema for a model.
     *
     * With reference caching, only depth 1 is stored, so we clear that.
     * The maxDepth parameter is kept for backward compatibility but ignored
     * when reference caching is enabled.
     */
    public function clearCache(string $modelClass, int $maxDepth = 2): void
    {
        // Always clear the base (depth 1) schema since that's what's cached
        Cache::forget($this->getCacheKey($modelClass, 1));

        // Also clear the requested depth for backward compatibility
        if ($maxDepth !== 1) {
            Cache::forget($this->getCacheKey($modelClass, $maxDepth));
        }
    }

    /**
     * Get a flat list of all available fields including relationship paths.
     *
     * @return array<string, string>
     */
    public function getFlatFieldList(string $modelClass, int $maxDepth = 2): array
    {
        $schema = $this->getSchema($modelClass, $maxDepth);

        return $schema ? $this->flattenFields($schema) : [];
    }

    /**
     * Build the schema array for a model.
     */
    protected function buildSchema(string $modelClass, int $depth, int $maxDepth, array $visited): array
    {
        if (in_array($modelClass, $visited)) {
            return [
                'model' => $modelClass,
                'model_short' => class_basename($modelClass),
                'circular' => true,
            ];
        }

        $model = new $modelClass;

        return [
            'model' => $modelClass,
            'model_short' => class_basename($modelClass),
            'table' => $model->getTable(),
            'columns' => $this->getColumns($model),
            'relationships' => $depth < $maxDepth
                ? $this->getRelationships($model, $modelClass, $depth, $maxDepth, [...$visited, $modelClass])
                : [],
        ];
    }

    /**
     * Get column and accessor information for a model.
     */
    protected function getColumns(Model $model): array
    {
        $tableName = $this->getTableName($model->getTable());
        $casts = $model->getCasts();

        return collect($this->getDatabaseColumns($tableName))
            ->map(fn (array $info, string $column) => $this->applyEnumCast($info, $column, $casts))
            ->merge($this->getAccessors($model))
            ->all();
    }

    /**
     * Apply enum information to a column from PHP cast or SQL enum type.
     */
    protected function applyEnumCast(array $info, string $column, array $casts): array
    {
        // First priority: PHP enum cast
        if (isset($casts[$column])) {
            $castType = $casts[$column];

            // Handle enum casts - could be class string or class:EnumClass format
            $enumClass = $castType;
            if (str_contains($castType, ':')) {
                $enumClass = explode(':', $castType)[1] ?? $castType;
            }

            // Check if it's a backed enum
            if (enum_exists($enumClass)) {
                $reflection = new \ReflectionEnum($enumClass);
                if ($reflection->isBacked()) {
                    $cases = array_map(
                        fn ($case) => $case->value,
                        $enumClass::cases()
                    );

                    $info['type'] = 'enum:' . implode('|', $cases);
                    $info['enum_class'] = $enumClass;

                    return $info;
                }
            }
        }

        // Second priority: SQL ENUM type - parse values from type_raw
        if (($info['type'] ?? '') === 'enum' && isset($info['type_raw'])) {
            $sqlEnumValues = $this->parseSqlEnumValues($info['type_raw']);
            if (! empty($sqlEnumValues)) {
                $info['type'] = 'enum:' . implode('|', $sqlEnumValues);
            }
        }

        return $info;
    }

    /**
     * Parse SQL enum values from the raw type definition.
     * e.g., "enum('draft','published','archived')" => ['draft', 'published', 'archived']
     *
     * @return array<string>
     */
    protected function parseSqlEnumValues(string $typeRaw): array
    {
        if (! preg_match("/^enum\s*\((.+)\)$/i", $typeRaw, $matches)) {
            return [];
        }

        // Parse the comma-separated quoted values
        preg_match_all("/'([^']+)'/", $matches[1], $valueMatches);

        return $valueMatches[1] ?? [];
    }

    /**
     * Get database columns for a table.
     */
    protected function getDatabaseColumns(string $tableName): Collection
    {
        try {
            return collect(Schema::getColumns($tableName))
                ->mapWithKeys(fn (array $column) => [
                    $column['name'] => [
                        'name' => $column['name'],
                        'type' => $column['type_name'],
                        'type_raw' => $column['type'],
                        'type_icon' => $this->getTypeIcon($column['type_name']),
                        'is_accessor' => false,
                    ],
                ]);
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Get accessor attributes from a model using reflection.
     */
    protected function getAccessors(Model $model): Collection
    {
        $reflection = new ReflectionClass($model);

        // Include both public and protected methods (modern accessors are protected)
        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED))
            ->reject(fn (ReflectionMethod $method) => str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\'))
            ->mapWithKeys(fn (ReflectionMethod $method) => $this->parseAccessor($method))
            ->filter();
    }

    /**
     * Parse a method to determine if it's an accessor.
     *
     * @return array<string, array>
     */
    protected function parseAccessor(ReflectionMethod $method): array
    {
        $methodName = $method->getName();

        // Legacy accessor pattern: getXxxAttribute
        if (preg_match('/^get(.+)Attribute$/', $methodName, $matches)) {
            $name = Str::snake($matches[1]);

            return [$name => $this->buildAccessorInfo($name, $this->getReturnTypeName($method))];
        }

        // Modern accessor pattern: returns Attribute
        if ($method->getNumberOfRequiredParameters() === 0) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType && $returnType->getName() === Attribute::class) {
                $name = Str::snake($methodName);

                return [$name => $this->buildAccessorInfo($name, $this->getModernAccessorReturnType($method))];
            }
        }

        return [];
    }

    /**
     * Build accessor info array.
     */
    protected function buildAccessorInfo(string $name, string $type): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'type_icon' => 'accessor',
            'is_accessor' => true,
        ];
    }

    /**
     * Get the return type name from a method.
     */
    protected function getReturnTypeName(ReflectionMethod $method): string
    {
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType) {
            return 'mixed';
        }

        return match ($typeName = $returnType->getName()) {
            'int', 'integer' => 'int',
            'float', 'double' => 'float',
            'bool', 'boolean' => 'bool',
            'string', 'array', 'object', 'null', 'mixed', 'void' => $typeName,
            \Illuminate\Support\Collection::class => 'Collection',
            \Carbon\Carbon::class, \Carbon\CarbonImmutable::class, \Illuminate\Support\Carbon::class => 'Carbon',
            default => class_exists($typeName) ? class_basename($typeName) : $typeName,
        };
    }

    /**
     * Get the return type of a modern Attribute accessor by parsing source.
     */
    protected function getModernAccessorReturnType(ReflectionMethod $method): string
    {
        try {
            $source = $this->getMethodSource($method);

            // Try to find return type hint in get closure
            foreach (['/get:\s*fn\s*\([^)]*\)\s*:\s*(\??\w+)/', '/get:\s*function\s*\([^)]*\)\s*:\s*(\??\w+)/'] as $pattern) {
                if (preg_match($pattern, $source, $matches)) {
                    return ltrim($matches[1], '?');
                }
            }

            return 'mixed';
        } catch (\Throwable) {
            return 'mixed';
        }
    }

    /**
     * Get the source code of a method.
     */
    protected function getMethodSource(ReflectionMethod $method): string
    {
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if (! $filename || ! $startLine || ! $endLine) {
            return '';
        }

        $lines = file($filename);

        return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }

    /**
     * Get relationships for a model using reflection.
     */
    protected function getRelationships(Model $model, string $modelClass, int $depth, int $maxDepth, array $visited): array
    {
        $reflection = new ReflectionClass($model);

        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->class === $modelClass)
            ->reject(fn (ReflectionMethod $method) => $method->getNumberOfRequiredParameters() > 0)
            ->reject(fn (ReflectionMethod $method) => $this->isExcludedMethod($method->getName()))
            ->mapWithKeys(fn (ReflectionMethod $method) => $this->parseRelationship($model, $method, $depth, $maxDepth, $visited))
            ->filter()
            ->all();
    }

    /**
     * Parse a method to determine if it's a relationship.
     *
     * @return array<string, array>
     */
    protected function parseRelationship(Model $model, ReflectionMethod $method, int $depth, int $maxDepth, array $visited): array
    {
        // Check return type hint first
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && in_array($returnType->getName(), self::RELATION_TYPES)) {
            $info = $this->buildRelationshipFromMethod($model, $method, $depth, $maxDepth, $visited);

            return $info ? [$method->getName() => $info] : [];
        }

        // If method has a non-relation return type, skip invoking it
        if ($returnType instanceof ReflectionNamedType && ! in_array($returnType->getName(), ['mixed', 'void', 'null', 'static', 'self'])) {
            return [];
        }

        // Try invoking the method (only for methods without type hints)
        try {
            $result = $method->invoke($model);

            if ($result instanceof Relation) {
                return [$method->getName() => $this->buildRelationshipInfo($result, $method->getName(), $depth, $maxDepth, $visited)];
            }
        } catch (\Throwable) {
            // Method threw an exception, skip it
        }

        return [];
    }

    /**
     * Build relationship info by invoking the method.
     */
    protected function buildRelationshipFromMethod(Model $model, ReflectionMethod $method, int $depth, int $maxDepth, array $visited): ?array
    {
        try {
            $result = $method->invoke($model);

            return $result instanceof Relation
                ? $this->buildRelationshipInfo($result, $method->getName(), $depth, $maxDepth, $visited)
                : null;
        } catch (\Throwable) {
            $typeName = $method->getReturnType()->getName();

            return [
                'name' => $method->getName(),
                'type' => class_basename($typeName),
                'type_full' => $typeName,
                'is_collection' => in_array($typeName, self::COLLECTION_RELATIONS),
            ];
        }
    }

    /**
     * Build relationship info from a relation instance.
     */
    protected function buildRelationshipInfo(Relation $relation, string $name, int $depth, int $maxDepth, array $visited): array
    {
        $relatedModel = get_class($relation->getRelated());
        $typeName = get_class($relation);

        $info = [
            'name' => $name,
            'type' => class_basename($typeName),
            'type_full' => $typeName,
            'related_model' => $relatedModel,
            'related_model_short' => class_basename($relatedModel),
            'is_collection' => in_array($typeName, self::COLLECTION_RELATIONS),
        ];

        if ($depth + 1 <= $maxDepth) {
            $info['schema'] = $this->buildSchema($relatedModel, $depth + 1, $maxDepth, $visited);
        }

        return $info;
    }

    /**
     * Check if a method should be excluded from relationship scanning.
     */
    protected function isExcludedMethod(string $name): bool
    {
        if (in_array($name, self::EXCLUDED_METHODS)) {
            return true;
        }

        return collect(self::EXCLUDED_PREFIXES)->contains(
            fn (string $prefix) => str_starts_with($name, $prefix)
        );
    }

    /**
     * Get the column type from the database.
     */
    protected function getColumnType(string $table, string $column): string
    {
        try {
            return Schema::getColumnType($table, $column);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Get an icon identifier for a column type.
     */
    protected function getTypeIcon(string $type): string
    {
        return match ($type) {
            'integer', 'bigint', 'smallint', 'tinyint', 'mediumint' => 'number',
            'decimal', 'float', 'double' => 'decimal',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'time' => 'time',
            'text', 'mediumtext', 'longtext' => 'text-long',
            'json' => 'json',
            'accessor' => 'accessor',
            default => 'text',
        };
    }

    /**
     * Get table name without schema prefix.
     */
    protected function getTableName(string $table): string
    {
        return str_contains($table, '.')
            ? substr($table, strpos($table, '.') + 1)
            : $table;
    }

    /**
     * Check if a class is a valid Eloquent model.
     */
    protected function isValidModel(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Model::class);
    }

    /**
     * Get cache key for a model schema.
     */
    protected function getCacheKey(string $modelClass, int $maxDepth): string
    {
        return 'eloquent_schema_'.md5($modelClass.'_'.$maxDepth);
    }

    /**
     * Flatten the schema into a list of field paths.
     *
     * @return array<string, string>
     */
    protected function flattenFields(array $schema, string $prefix = ''): array
    {
        $fields = collect($schema['columns'] ?? [])
            ->keys()
            ->mapWithKeys(fn (string $column) => [
                $prefix ? "{$prefix}.{$column}" : $column => $prefix ? "{$prefix}.{$column}" : $column,
            ])
            ->all();

        foreach ($schema['relationships'] ?? [] as $relationName => $relationInfo) {
            if (isset($relationInfo['schema']) && ! ($relationInfo['schema']['circular'] ?? false)) {
                $newPrefix = $prefix ? "{$prefix}.{$relationName}" : $relationName;
                $fields = array_merge($fields, $this->flattenFields($relationInfo['schema'], $newPrefix));
            }
        }

        return $fields;
    }
}
