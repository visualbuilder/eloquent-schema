<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

class McpToolsCommand extends Command
{
    protected $signature = 'mcp:tools
                            {tool? : The tool name to call (use --list to see available tools)}
                            {--list : List all available MCP tools}
                            {--describe : Show tool description and parameters}
                            {--args= : JSON arguments to pass to the tool}';

    protected $description = 'List and call MCP tools from the CLI';

    public function handle(): int
    {
        if ($this->option('list') || ! $this->argument('tool')) {
            return $this->listTools();
        }

        $toolName = $this->argument('tool');

        if ($this->option('describe')) {
            return $this->describeTool($toolName);
        }

        return $this->callTool($toolName);
    }

    protected function listTools(): int
    {
        $tools = $this->getAvailableTools();

        if (empty($tools)) {
            $this->components->warn('No MCP tools found.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Available MCP Tools (%d)', count($tools)));
        $this->newLine();

        foreach ($tools as $name => $class) {
            $tool = app($class);
            $description = $this->getToolDescription($tool);

            $this->components->twoColumnDetail(
                sprintf('<fg=green>%s</>', $name),
                strlen($description) > 80 ? substr($description, 0, 77).'...' : $description
            );
        }

        $this->newLine();
        $this->line('Use <fg=yellow>php artisan mcp:tools {name} --describe</> for details');
        $this->line('Use <fg=yellow>php artisan mcp:tools {name} --args=\'{"key":"value"}\'</> to call');

        return self::SUCCESS;
    }

    protected function describeTool(string $toolName): int
    {
        $tools = $this->getAvailableTools();

        $class = $this->findToolClass($toolName, $tools);
        if (! $class) {
            $this->components->error("Tool not found: {$toolName}");

            return self::FAILURE;
        }

        $tool = app($class);

        $this->components->info($toolName);
        $this->newLine();
        $this->line($this->getToolDescription($tool));
        $this->newLine();

        $schema = $this->getToolSchema($tool);

        if (! empty($schema)) {
            $this->components->info('Parameters:');
            foreach ($schema as $param => $config) {
                $type = $config['type'] ?? 'mixed';
                $required = ($config['required'] ?? false) ? ' <fg=red>*required</>' : '';
                $description = $config['description'] ?? '';

                $this->components->twoColumnDetail(
                    sprintf('  <fg=yellow>%s</> (%s)%s', $param, $type, $required),
                    $description
                );
            }
        } else {
            $this->line('No parameters required.');
        }

        return self::SUCCESS;
    }

    protected function callTool(string $toolName): int
    {
        $tools = $this->getAvailableTools();

        $class = $this->findToolClass($toolName, $tools);
        if (! $class) {
            $this->components->error("Tool not found: {$toolName}");

            return self::FAILURE;
        }

        $argsJson = $this->option('args') ?? '{}';
        $args = json_decode($argsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->components->error('Invalid JSON in --args: '.json_last_error_msg());

            return self::FAILURE;
        }

        $tool = app($class);
        $request = new Request($args);

        try {
            $response = $tool->handle($request);
            $content = $this->extractResponseContent($response);

            $this->line(json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, class-string>
     */
    protected function getAvailableTools(): array
    {
        $tools = [];

        // Get tools from Laravel Boost if available
        if (class_exists(\Laravel\Boost\Mcp\ToolRegistry::class)) {
            $tools = \Laravel\Boost\Mcp\ToolRegistry::getToolNames();
        }

        // Also check config for any additional tools
        $extraTools = config('eloquent-schema.mcp.tools', []);
        foreach ($extraTools as $toolClass) {
            if (class_exists($toolClass) && is_subclass_of($toolClass, Tool::class)) {
                $tools[class_basename($toolClass)] = $toolClass;
            }
        }

        ksort($tools);

        return $tools;
    }

    /**
     * @param  array<string, class-string>  $tools
     */
    protected function findToolClass(string $name, array $tools): ?string
    {
        // Exact match
        if (isset($tools[$name])) {
            return $tools[$name];
        }

        // Case-insensitive match
        foreach ($tools as $toolName => $class) {
            if (strcasecmp($toolName, $name) === 0) {
                return $class;
            }
        }

        // Partial match
        foreach ($tools as $toolName => $class) {
            if (stripos($toolName, $name) !== false) {
                return $class;
            }
        }

        return null;
    }

    protected function getToolDescription(Tool $tool): string
    {
        $reflection = new ReflectionClass($tool);

        if ($reflection->hasProperty('description')) {
            $property = $reflection->getProperty('description');
            $property->setAccessible(true);

            return $property->getValue($tool) ?? '';
        }

        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getToolSchema(Tool $tool): array
    {
        if (! method_exists($tool, 'schema')) {
            return [];
        }

        $jsonSchema = new JsonSchemaTypeFactory;
        $schema = $tool->schema($jsonSchema);

        $result = [];
        foreach ($schema as $name => $type) {
            $result[$name] = [
                'type' => $this->extractTypeName($type),
                'description' => $this->extractDescription($type),
                'required' => $this->extractRequired($type),
            ];
        }

        return $result;
    }

    protected function extractTypeName($type): string
    {
        if (method_exists($type, 'toArray')) {
            $arr = $type->toArray();

            return $arr['type'] ?? 'mixed';
        }

        return 'mixed';
    }

    protected function extractDescription($type): string
    {
        if (method_exists($type, 'toArray')) {
            $arr = $type->toArray();

            return $arr['description'] ?? '';
        }

        return '';
    }

    protected function extractRequired($type): bool
    {
        if (method_exists($type, 'toArray')) {
            $arr = $type->toArray();

            return ! ($arr['nullable'] ?? true);
        }

        return false;
    }

    protected function extractResponseContent($response): mixed
    {
        // Get text content from Response
        if (method_exists($response, 'content')) {
            $content = $response->content();
            $text = (string) $content;

            $decoded = json_decode($text, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
        }

        return $response;
    }
}
