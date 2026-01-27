# Eloquent Schema

Adds new MCP tools to [Laravel Boost](https://github.com/bootstrapguru/laravel-boost) for Eloquent model introspection. Designed for AI assistants and development tools that need to understand your application's data structure.

Provides complete model schemas, relationships and accessors so AI assistants can generate more accurate Eloquent queries and code without needing to read through multiple model files. This reduces token usage, speeds up responses, and eliminates guesswork about your database structure.

## Features

- **MCP Tools** - Expose model schemas to AI assistants via Laravel MCP
- **Model Discovery** - Automatically discover models in your app and configured vendor packages
- **Schema Introspection** - Extract columns, relationships, and accessors from models
- **Caching** - Built-in caching for performance
- **Vendor Support** - Include models from packages like Spatie Permission, Media Library, etc.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Laravel MCP package (optional, for MCP tools)

## Installation

```bash
composer require visualbuilder/eloquent-schema
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=eloquent-schema-config
```

## Configuration

```php
// config/eloquent-schema.php

return [
    // Cache TTL in seconds (0 to disable)
    'cache_ttl' => env('ELOQUENT_SCHEMA_CACHE_TTL', 3600),

    // Additional model paths to scan
    'model_paths' => [
        // app_path('Domain/Models'),
    ],

    // Explicitly include specific models
    'additional_models' => [
        // \App\Models\SomeModel::class,
    ],

    // Exclude specific models from discovery
    'excluded_models' => [
        // \App\Models\ExcludedModel::class,
    ],

    // Vendor packages to include (use composer package names)
    'included_packages' => [
        // 'spatie/laravel-permission',
        // 'spatie/laravel-medialibrary',
    ],

    // MCP tools configuration
    'mcp' => [
        'enabled' => true,
    ],
];
```

## MCP Tools

This package provides three MCP tools for AI assistants:

### list-models

List all discoverable Eloquent models in the application.

**Parameters:**
- `filter` (string, optional) - Filter models by name (case-insensitive partial match)
- `include_vendor` (boolean, default: false) - Include models from vendor packages

**Example Response:**
```json
{
  "count": 5,
  "namespace": "App\\Models",
  "models": ["User", "Order", "Product"],
  "vendor_models": ["Spatie\\Permission\\Models\\Role", "Spatie\\Permission\\Models\\Permission"]
}
```

Note: Combine `namespace` + model name for the full class (e.g., `App\Models\User`). Vendor models include their full namespace. The `vendor_models` key is only present when vendor models exist.

### model-schema

Get the complete schema for an Eloquent model including columns, relationships, and accessors.

**Parameters:**
- `model` (string, required) - Fully qualified model class name
- `max_depth` (integer, default: 2, max: 3) - Depth for relationship exploration

**Example Response:**
```json
{
  "model": "App\\Models\\Order",
  "table": "orders",
  "columns": {
    "id": "bigint",
    "user_id": "bigint",
    "status": "string",
    "total": "decimal",
    "created_at": "datetime",
    "updated_at": "datetime"
  },
  "accessors": {
    "formatted_total": "string"
  },
  "relationships": {
    "user": {
      "type": "BelongsTo",
      "model": "User"
    },
    "lineItems": {
      "type": "HasMany",
      "model": "OrderLineItem"
    }
  }
}
```

### model-fields

Get a compact list of model columns and relationship names. Useful for building queries.

**Parameters:**
- `model` (string, required) - Fully qualified model class name

**Example Response:**
```json
{
  "model": "App\\Models\\Order",
  "table": "orders",
  "columns": ["id", "user_id", "status", "total", "created_at", "updated_at"],
  "accessors": ["formatted_total"],
  "relationships": ["user", "lineItems", "payments"]
}
```

## Artisan Commands

### List Models

```bash
# List all app models
php artisan eloquent-schema:list

# Include vendor models
php artisan eloquent-schema:list --vendor

# Filter by name
php artisan eloquent-schema:list --filter=User

# Show count only
php artisan eloquent-schema:list --count
```

### Discover Vendor Packages

Interactively discover and select vendor packages with Eloquent models:

```bash
# Interactive selection
php artisan eloquent-schema:discover

# List available packages without selection
php artisan eloquent-schema:discover --list
```

### Cache Management

```bash
# Warm the cache (discovers models and preloads schemas)
php artisan eloquent-schema:cache

# Skip schema preloading
php artisan eloquent-schema:cache --no-schema

# Set relationship depth for schema caching (warning: depth 2+ uses significant memory)
php artisan eloquent-schema:cache --max-depth=2

# Clear the cache
php artisan eloquent-schema:clear

# Clear including individual schema caches
php artisan eloquent-schema:clear --schema
```

### Testing MCP Tools from CLI

Test the eloquent-schema MCP tools directly from the command line:

```bash
# Test list-models
php artisan eloquent-schema:mcp list-models
php artisan eloquent-schema:mcp list-models --vendor --filter=User

# Test model-schema
php artisan eloquent-schema:mcp model-schema --model='App\Models\Order'
php artisan eloquent-schema:mcp model-schema --model='App\Models\Order' --max-depth=2

# Test model-fields
php artisan eloquent-schema:mcp model-fields --model='App\Models\Order'
```

### Generic MCP Tools Command

List and call any registered MCP tool (works with Laravel Boost and custom tools):

```bash
# List all available MCP tools
php artisan mcp:tools --list

# Describe a tool's parameters
php artisan mcp:tools ModelSchema --describe

# Call any MCP tool with arguments
php artisan mcp:tools GetConfig --args='{"key":"app.name"}'
php artisan mcp:tools ListModels
php artisan mcp:tools DatabaseSchema --args='{"filter":"users"}'
php artisan mcp:tools ModelSchema --args='{"model":"App\\Models\\Order","max_depth":1}'
```

## Vendor Model Discovery

To include models from vendor packages:

1. Run the discovery command to find packages with models:
   ```bash
   php artisan eloquent-schema:discover
   ```

2. Select the packages you want to include

3. Add the suggested configuration to `config/eloquent-schema.php`:
   ```php
   'included_packages' => [
       'spatie/laravel-permission',
       'spatie/laravel-medialibrary',
   ],
   ```

4. Warm the cache:
   ```bash
   php artisan eloquent-schema:cache
   ```

### Deduplication

When your app extends a vendor model, the package intelligently prefers your app model:

- `App\Models\User` extends `Spatie\Permission\Models\User` → Only `App\Models\User` is included
- Models with the same basename prefer the App version

## Using with Laravel Boost

Add the tools to your `config/boost.php`:

```php
'mcp' => [
    'tools' => [
        'include' => [
            \Visualbuilder\EloquentSchema\Mcp\Tools\ListModels::class,
            \Visualbuilder\EloquentSchema\Mcp\Tools\ModelSchema::class,
            \Visualbuilder\EloquentSchema\Mcp\Tools\ModelFields::class,
        ],
        'exclude' => [],
    ],
],
```

## Using with Custom MCP Server

If using a custom MCP server, register the tools in your server class:

```php
use Visualbuilder\EloquentSchema\Mcp\Tools\ListModels;
use Visualbuilder\EloquentSchema\Mcp\Tools\ModelSchema;
use Visualbuilder\EloquentSchema\Mcp\Tools\ModelFields;

class YourServer extends Server
{
    protected array $tools = [
        ListModels::class,
        ModelSchema::class,
        ModelFields::class,
    ];
}
```

## Programmatic Usage

You can also use the services directly:

```php
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

// Discover models
$discovery = app(ModelDiscoveryService::class);
$models = $discovery->getModels(includeVendor: true);

// Get schema for a model
$schema = app(ModelSchemaService::class);
$orderSchema = $schema->getSchema(Order::class, maxDepth: 2);

// Get flat field list
$fields = $schema->getFlatFieldList(Order::class);
```

## Caching

Caching improves performance when frequently querying model schemas:

```bash
# Warm the cache (recommended after model changes)
php artisan eloquent-schema:cache

# Clear the cache when models change
php artisan eloquent-schema:clear --schema
```

Set cache TTL via environment variable:
```
ELOQUENT_SCHEMA_CACHE_TTL=3600
```

Set to `0` to disable caching during active development.

## License

MIT License. See [LICENSE](LICENSE) for details.
