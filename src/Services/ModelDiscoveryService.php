<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Service to discover Eloquent models from the application and vendor packages.
 */
class ModelDiscoveryService
{
    protected const CACHE_KEY = 'eloquent_schema_models';

    public function __construct(
        protected int $cacheTtl = 3600,
    ) {
        $this->cacheTtl = (int) config('eloquent-schema.cache_ttl', 3600);
    }

    /**
     * Get all discovered models, using cache if available.
     *
     * @return array<string>
     */
    public function getModels(bool $includeVendor = false, ?string $filter = null): array
    {
        $cacheKey = self::CACHE_KEY.($includeVendor ? '_with_vendor' : '');

        $models = $this->cacheTtl > 0
            ? Cache::remember($cacheKey, $this->cacheTtl, fn () => $this->discoverModels($includeVendor))
            : $this->discoverModels($includeVendor);

        return collect($models)
            ->when($filter, fn (Collection $models) => $models->filter(
                fn (string $model) => str_contains(strtolower($model), strtolower($filter))
            ))
            ->values()
            ->all();
    }

    /**
     * Warm the cache by discovering all models.
     *
     * @return array{app: int, vendor: int, total: int}
     */
    public function warmCache(): array
    {
        $this->clearCache();

        $appModels = $this->discoverModels(false);
        Cache::put(self::CACHE_KEY, $appModels, $this->cacheTtl);

        $allModels = $this->discoverModels(true);
        Cache::put(self::CACHE_KEY.'_with_vendor', $allModels, $this->cacheTtl);

        return [
            'app' => count($appModels),
            'vendor' => count($allModels) - count($appModels),
            'total' => count($allModels),
        ];
    }

    /**
     * Clear the model discovery cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.'_with_vendor');
    }

    /**
     * Discover Eloquent models from the application.
     *
     * @return array<string>
     */
    protected function discoverModels(bool $includeVendor = false): array
    {
        $excludedModels = collect(config('eloquent-schema.excluded_models', []));

        return collect()
            // App models
            ->merge($this->discoverModelsInPath(app_path('Models'), 'App\\Models'))
            // Configured paths
            ->merge($this->discoverFromConfiguredPaths())
            // Vendor models (if requested)
            ->when($includeVendor, fn (Collection $models) => $models->merge($this->discoverVendorModels()))
            // Additional models from config
            ->merge($this->getAdditionalModels())
            // Remove excluded
            ->reject(fn (string $model) => $excludedModels->contains($model))
            // Deduplicate (prefer App over vendor)
            ->pipe(fn (Collection $models) => $this->deduplicateModels($models))
            // Sort and unique
            ->sort()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Discover models from configured paths.
     */
    protected function discoverFromConfiguredPaths(): Collection
    {
        return collect(config('eloquent-schema.model_paths', []))
            ->flatMap(function (mixed $namespace, int|string $path) {
                if (is_int($path)) {
                    $path = $namespace;
                    $namespace = $this->guessNamespaceFromPath($path);
                }

                return is_dir($path) ? $this->discoverModelsInPath($path, $namespace) : [];
            });
    }

    /**
     * Get additional models from config.
     */
    protected function getAdditionalModels(): Collection
    {
        return collect(config('eloquent-schema.additional_models', []))
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class));
    }

    /**
     * Discover models in a given path.
     */
    protected function discoverModelsInPath(string $path, string $namespace): Collection
    {
        if (! is_dir($path)) {
            return collect();
        }

        $namespace = rtrim($namespace, '\\');

        return collect(File::allFiles($path))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(function ($file) use ($namespace) {
                $relativePath = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

                return $namespace.'\\'.ltrim($relativePath, '\\');
            })
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class));
    }

    /**
     * Discover models from configured vendor packages.
     */
    protected function discoverVendorModels(): Collection
    {
        $includedPackages = collect(config('eloquent-schema.included_packages', []));

        if ($includedPackages->isEmpty()) {
            return collect();
        }

        $installedJsonPath = base_path('vendor/composer/installed.json');

        if (! file_exists($installedJsonPath)) {
            return collect();
        }

        $installed = json_decode(file_get_contents($installedJsonPath), true);

        return collect($installed['packages'] ?? $installed)
            ->filter(fn (array $package) => $includedPackages->contains($package['name'] ?? ''))
            ->flatMap(fn (array $package) => $this->discoverModelsFromPackage($package));
    }

    /**
     * Discover models from a single composer package.
     */
    protected function discoverModelsFromPackage(array $package): Collection
    {
        $installPath = $package['install-path'] ?? null;
        $fullPath = $installPath ? realpath(base_path('vendor/composer').'/'.$installPath) : null;

        if (! $fullPath) {
            return collect();
        }

        return collect($package['autoload']['psr-4'] ?? [])
            ->flatMap(function (array|string $paths, string $namespace) use ($fullPath) {
                return collect((array) $paths)
                    ->reject(fn (string $path) => str_contains($path, 'test'))
                    ->map(fn (string $path) => $fullPath.'/'.rtrim($path, '/').'/Models')
                    ->filter(fn (string $modelsPath) => is_dir($modelsPath))
                    ->flatMap(fn (string $modelsPath) => $this->discoverModelsInPath(
                        $modelsPath,
                        rtrim($namespace, '\\').'\\Models'
                    ));
            });
    }

    /**
     * Guess namespace from path.
     */
    protected function guessNamespaceFromPath(string $path): string
    {
        return str_contains($path, 'app/Models') ? 'App\\Models' : 'App';
    }

    /**
     * Deduplicate models, preferring App models over vendor models.
     */
    protected function deduplicateModels(Collection $models): Collection
    {
        [$appModels, $vendorModels] = $models->partition(
            fn (string $model) => str_starts_with($model, 'App\\')
        );

        $appBasenames = $appModels->keyBy(fn (string $model) => class_basename($model));

        // Find vendor models to exclude (extended by App or same basename)
        $excludedVendor = $vendorModels->filter(function (string $vendorModel) use ($appModels, $appBasenames) {
            // Exclude if App has same basename
            if ($appBasenames->has(class_basename($vendorModel))) {
                return true;
            }

            // Exclude if any App model extends this vendor model
            return $appModels->contains(
                fn (string $appModel) => class_exists($appModel)
                    && class_exists($vendorModel)
                    && is_subclass_of($appModel, $vendorModel)
            );
        });

        return $appModels->merge($vendorModels->diff($excludedVendor));
    }
}
