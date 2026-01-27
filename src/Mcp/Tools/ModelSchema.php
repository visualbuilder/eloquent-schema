<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

/**
 * MCP tool to get the Eloquent model schema including columns, relationships, and accessors.
 */
#[IsReadOnly]
class ModelSchema extends Tool
{
    protected string $description = 'Get the Eloquent model schema including database columns, relationships, and accessors. Useful for understanding model structure before writing queries.';

    public function __construct(
        protected ModelSchemaService $schemaService,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model' => $schema->string()
                ->description('The fully qualified model class name (e.g., App\\Models\\Order)')
                ->required(),
            'max_depth' => $schema->integer()
                ->description('Maximum depth for relationship exploration (default: 2, max: 3)')
                ->default(2),
        ];
    }

    public function handle(Request $request): Response
    {
        $modelClass = $request->get('model');
        $maxDepth = min((int) ($request->get('max_depth') ?? 2), 3);

        if (! $modelClass) {
            return Response::json([
                'error' => 'Model class is required',
            ]);
        }

        if (! class_exists($modelClass)) {
            return Response::json([
                'error' => "Model class not found: {$modelClass}",
            ]);
        }

        $schema = $this->schemaService->getSchema($modelClass, $maxDepth);

        if (! $schema) {
            return Response::json([
                'error' => "Could not load schema for: {$modelClass}",
            ]);
        }

        return Response::json($this->formatSchema($schema));
    }

    /**
     * Format the schema for cleaner output.
     * Only includes full column/accessor details for the queried model.
     * Relationships show type and related model only - call model-schema on the related model for its details.
     */
    protected function formatSchema(array $schema): array
    {
        $result = [
            'model' => $schema['model'] ?? null,
            'table' => $schema['table'] ?? null,
            'columns' => [],
            'accessors' => [],
            'relationships' => [],
        ];

        // Flatten columns and accessors to "name": "type" format
        foreach ($schema['columns'] ?? [] as $name => $info) {
            if ($info['is_accessor'] ?? false) {
                $result['accessors'][$name] = $info['type'] ?? 'mixed';
            } else {
                $result['columns'][$name] = $info['type'] ?? 'unknown';
            }
        }

        // Format relationships - compact output
        // is_collection is omitted as it's inferable from type (HasMany, BelongsToMany, etc. = collection)
        foreach ($schema['relationships'] ?? [] as $name => $info) {
            $relatedModel = $info['related_model'] ?? null;

            // Shorten App\Models\ prefix, keep FQN for vendor models
            if ($relatedModel && str_starts_with($relatedModel, 'App\\Models\\')) {
                $relatedModel = substr($relatedModel, 11); // Remove 'App\Models\'
            }

            $rel = [
                'type' => $info['type'] ?? null,
                'model' => $relatedModel,
            ];

            if ($info['schema']['circular'] ?? false) {
                $rel['circular'] = true;
            }

            $result['relationships'][$name] = $rel;
        }

        return $result;
    }
}
