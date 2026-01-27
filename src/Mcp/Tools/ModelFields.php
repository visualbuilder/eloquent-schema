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
 * MCP tool to get a flat list of all available field paths for a model.
 */
#[IsReadOnly]
class ModelFields extends Tool
{
    protected string $description = 'Get a compact list of model columns and relationship names. Returns direct fields and available relationships for eager loading. Use model-schema for detailed relationship info.';

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
        ];
    }

    public function handle(Request $request): Response
    {
        $modelClass = $request->get('model');

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

        // Get schema at depth 1 only - we just need direct fields and relationship names
        $schema = $this->schemaService->getSchema($modelClass, 1);

        if (! $schema) {
            return Response::json([
                'error' => "Could not load schema for: {$modelClass}",
            ]);
        }

        // Extract column names (excluding accessors)
        $columns = [];
        $accessors = [];
        foreach ($schema['columns'] ?? [] as $name => $info) {
            if ($info['is_accessor'] ?? false) {
                $accessors[] = $name;
            } else {
                $columns[] = $name;
            }
        }

        // Extract relationship names
        $relationships = array_keys($schema['relationships'] ?? []);

        return Response::json([
            'model' => $modelClass,
            'table' => $schema['table'] ?? null,
            'columns' => $columns,
            'accessors' => $accessors,
            'relationships' => $relationships,
        ]);
    }
}
