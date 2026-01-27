<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\note;

class DiscoverVendorModelsCommand extends Command
{
    protected $signature = 'eloquent-schema:discover
                            {--list : List available packages without interactive selection}';

    protected $description = 'Discover Eloquent models in vendor packages and select which to include';

    public function handle(): int
    {
        $this->components->info('Scanning vendor packages for Eloquent models...');

        $packagesWithModels = $this->discoverVendorPackagesWithModels();

        if (empty($packagesWithModels)) {
            $this->components->warn('No vendor packages with Eloquent models found.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Found %d packages with models.', count($packagesWithModels)));
        $this->newLine();

        // Build options for selection
        $options = [];
        foreach ($packagesWithModels as $package => $info) {
            $modelCount = count($info['models']);
            $modelList = implode(', ', array_slice($info['models'], 0, 3));
            if ($modelCount > 3) {
                $modelList .= sprintf(' (+%d more)', $modelCount - 3);
            }
            $options[$package] = sprintf('%s (%d models: %s)', $package, $modelCount, $modelList);
        }

        // List mode - just show available packages
        if ($this->option('list')) {
            foreach ($packagesWithModels as $package => $info) {
                $this->components->twoColumnDetail(
                    $package,
                    implode(', ', $info['models'])
                );
            }

            return self::SUCCESS;
        }

        // Let user select packages
        $selected = multisearch(
            label: 'Select packages to include (type to filter, space to select):',
            options: fn (string $search) => array_filter(
                $options,
                fn ($label, $key) => $search === '' || stripos($label, $search) !== false,
                ARRAY_FILTER_USE_BOTH
            ),
            scroll: 15,
            hint: 'Use arrow keys to navigate, space to select, enter to confirm',
        );

        if (empty($selected)) {
            $this->components->warn('No packages selected.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Add this to your config/eloquent-schema.php:');
        $this->newLine();

        $this->line("'included_packages' => [");
        foreach ($selected as $package) {
            $this->line(sprintf("    '%s',", $package));
        }
        $this->line('],');

        $this->newLine();

        // Show the models that will be included
        $this->components->info('Models that will be available:');
        foreach ($selected as $package) {
            $models = $packagesWithModels[$package]['models'] ?? [];
            $this->components->twoColumnDetail($package, implode(', ', $models));
        }

        $this->newLine();
        note('After updating the config, run: php artisan eloquent-schema:cache');

        return self::SUCCESS;
    }

    /**
     * Discover all vendor packages that contain Eloquent models.
     *
     * @return array<string, array{models: array<string>}>
     */
    protected function discoverVendorPackagesWithModels(): array
    {
        $packages = [];

        $installedJsonPath = base_path('vendor/composer/installed.json');
        if (! file_exists($installedJsonPath)) {
            return $packages;
        }

        $installed = json_decode(file_get_contents($installedJsonPath), true);
        $packageList = $installed['packages'] ?? $installed;

        foreach ($packageList as $package) {
            $packageName = $package['name'] ?? '';

            // Skip Laravel framework packages (they have internal models)
            if (str_starts_with($packageName, 'laravel/') && $packageName !== 'laravel/sanctum') {
                continue;
            }

            // Skip common non-model packages
            if ($this->isExcludedPackage($packageName)) {
                continue;
            }

            $installPath = $package['install-path'] ?? null;
            if (! $installPath) {
                continue;
            }

            $fullPath = realpath(base_path('vendor/composer').'/'.$installPath);
            if (! $fullPath) {
                continue;
            }

            // Get namespace from autoload
            $autoload = $package['autoload']['psr-4'] ?? [];
            $models = [];

            foreach ($autoload as $ns => $paths) {
                $paths = is_array($paths) ? $paths : [$paths];
                foreach ($paths as $autoloadPath) {
                    $autoloadPath = rtrim($autoloadPath, '/');
                    $modelsPath = $fullPath.'/'.$autoloadPath.'/Models';

                    // Skip test directories
                    if (str_contains($autoloadPath, 'test') || str_contains($modelsPath, 'test')) {
                        continue;
                    }

                    if (is_dir($modelsPath)) {
                        $namespace = rtrim($ns, '\\').'\\Models';
                        $foundModels = $this->discoverModelsInPath($modelsPath, $namespace);
                        $models = array_merge($models, $foundModels);
                    }
                }
            }

            if (! empty($models)) {
                $packages[$packageName] = [
                    'models' => array_map(fn ($m) => class_basename($m), $models),
                ];
            }
        }

        ksort($packages);

        return $packages;
    }

    /**
     * Check if a package should be excluded from discovery.
     */
    protected function isExcludedPackage(string $packageName): bool
    {
        $excluded = [
            'illuminate/',
            'symfony/',
            'phpunit/',
            'mockery/',
            'pestphp/',
            'fakerphp/',
            'doctrine/',
            'nikic/',
            'psr/',
            'guzzlehttp/',
            'monolog/',
            'league/flysystem',
            'vlucas/',
            'ramsey/',
            'brick/',
            'carbonphp/',
            'nesbot/',
            'nette/',
            'composer/',
            'psy/',
            'sebastian/',
            'phpstan/',
            'rector/',
        ];

        foreach ($excluded as $prefix) {
            if (str_starts_with($packageName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Discover models in a given path.
     *
     * @return array<string>
     */
    protected function discoverModelsInPath(string $path, string $namespace): array
    {
        $models = [];

        if (! is_dir($path)) {
            return $models;
        }

        $files = File::allFiles($path);
        $namespace = rtrim($namespace, '\\');

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = $file->getRelativePathname();
            $relativePath = str_replace(['/', '.php'], ['\\', ''], $relativePath);

            $className = $namespace.'\\'.ltrim($relativePath, '\\');

            try {
                if (class_exists($className) && is_subclass_of($className, Model::class)) {
                    $models[] = $className;
                }
            } catch (\Throwable) {
                // Class might have dependencies that aren't loaded
            }
        }

        return $models;
    }
}
