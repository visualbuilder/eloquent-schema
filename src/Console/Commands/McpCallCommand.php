<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

class McpCallCommand extends Command
{
    protected $signature = 'eloquent-schema:mcp
                            {tool : The MCP tool to call (list-models, model-schema, model-fields)}
                            {--model= : Model class for model-schema and model-fields}
                            {--max-depth=2 : Max depth for model-schema}
                            {--filter= : Filter for list-models}
                            {--vendor : Include vendor models for list-models}';

    protected $description = 'Call an MCP tool and output the JSON result';

    public function handle(ModelDiscoveryService $discoveryService, ModelSchemaService $schemaService): int
    {
        $tool = $this->argument('tool');

        $result = match ($tool) {
            'list-models' => $this->listModels($discoveryService),
            'model-schema' => $this->modelSchema($schemaService),
            'model-fields' => $this->modelFields($schemaService),
            default => null,
        };

        if ($result === null) {
            $this->components->error("Unknown tool: {$tool}");
            $this->components->info('Available tools: list-models, model-schema, model-fields');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function listModels(ModelDiscoveryService $discoveryService): array
    {
        $includeVendor = (bool) $this->option('vendor');
        $filter = $this->option('filter');

        $models = $discoveryService->getModels($includeVendor, $filter);

        $appModels = [];
        $vendorModels = [];

        foreach ($models as $model) {
            if (str_starts_with($model, 'App\\Models\\')) {
                $appModels[] = substr($model, 11);
            } elseif (str_starts_with($model, 'App\\')) {
                $appModels[] = substr($model, 4);
            } else {
                $vendorModels[] = $model;
            }
        }

        $result = [
            'count' => count($models),
            'namespace' => 'App\\Models',
            'models' => $appModels,
        ];

        if (! empty($vendorModels)) {
            $result['vendor_models'] = $vendorModels;
        }

        return $result;
    }

    protected function modelSchema(ModelSchemaService $schemaService): array
    {
        $modelClass = $this->option('model');

        if (! $modelClass) {
            return ['error' => 'Model class is required. Use --model=App\\Models\\YourModel'];
        }

        if (! class_exists($modelClass)) {
            return ['error' => "Model class not found: {$modelClass}"];
        }

        $maxDepth = min((int) $this->option('max-depth'), 3);
        $schema = $schemaService->getSchema($modelClass, $maxDepth);

        if (! $schema) {
            return ['error' => "Could not load schema for: {$modelClass}"];
        }

        return $this->formatSchema($schema);
    }

    protected function modelFields(ModelSchemaService $schemaService): array
    {
        $modelClass = $this->option('model');

        if (! $modelClass) {
            return ['error' => 'Model class is required. Use --model=App\\Models\\YourModel'];
        }

        if (! class_exists($modelClass)) {
            return ['error' => "Model class not found: {$modelClass}"];
        }

        $schema = $schemaService->getSchema($modelClass, 1);

        if (! $schema) {
            return ['error' => "Could not load schema for: {$modelClass}"];
        }

        $columns = [];
        $accessors = [];

        foreach ($schema['columns'] ?? [] as $name => $info) {
            if ($info['is_accessor'] ?? false) {
                $accessors[] = $name;
            } else {
                $columns[] = $name;
            }
        }

        return [
            'model' => $modelClass,
            'table' => $schema['table'] ?? null,
            'columns' => $columns,
            'accessors' => $accessors,
            'relationships' => array_keys($schema['relationships'] ?? []),
        ];
    }

    protected function formatSchema(array $schema): array
    {
        $result = [
            'model' => $schema['model'] ?? null,
            'table' => $schema['table'] ?? null,
            'columns' => [],
            'accessors' => [],
            'relationships' => [],
        ];

        foreach ($schema['columns'] ?? [] as $name => $info) {
            if ($info['is_accessor'] ?? false) {
                $result['accessors'][$name] = $info['type'] ?? 'mixed';
            } else {
                $result['columns'][$name] = $info['type'] ?? 'unknown';
            }
        }

        foreach ($schema['relationships'] ?? [] as $name => $info) {
            $relatedModel = $info['related_model'] ?? null;

            if ($relatedModel && str_starts_with($relatedModel, 'App\\Models\\')) {
                $relatedModel = substr($relatedModel, 11);
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
