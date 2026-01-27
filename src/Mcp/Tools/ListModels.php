<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;

/**
 * MCP tool to list all discoverable Eloquent models in the application.
 */
#[IsReadOnly]
class ListModels extends Tool
{
    protected string $description = 'List all discoverable Eloquent models in the application. Returns model class names that can be used with model-schema and model-fields tools.';

    public function __construct(
        protected ModelDiscoveryService $discoveryService,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Filter models by name (case-insensitive partial match)'),
            'include_vendor' => $schema->boolean()
                ->description('Include models from vendor packages (default: false)')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $filter = $request->get('filter');
        $includeVendor = (bool) ($request->get('include_vendor') ?? false);

        $models = $this->discoveryService->getModels($includeVendor, $filter);

        // Separate app and vendor models
        $appModels = [];
        $vendorModels = [];

        foreach ($models as $model) {
            if (str_starts_with($model, 'App\\Models\\')) {
                $appModels[] = substr($model, 11); // Remove 'App\Models\'
            } elseif (str_starts_with($model, 'App\\')) {
                $appModels[] = substr($model, 4); // Remove 'App\'
            } else {
                $vendorModels[] = $model; // Keep FQN for vendor
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

        return Response::json($result);
    }
}
