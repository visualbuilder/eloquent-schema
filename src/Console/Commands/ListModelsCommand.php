<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;

class ListModelsCommand extends Command
{
    protected $signature = 'eloquent-schema:list
                            {--vendor : Include vendor models}
                            {--filter= : Filter models by name}
                            {--count : Only show count}';

    protected $description = 'List all discovered Eloquent models';

    public function handle(ModelDiscoveryService $discoveryService): int
    {
        $includeVendor = (bool) $this->option('vendor');
        $filter = $this->option('filter');

        // Warn if --vendor but no packages configured
        if ($includeVendor) {
            $includedPackages = config('eloquent-schema.included_packages', []);
            if (empty($includedPackages)) {
                $this->components->warn('No vendor packages configured in eloquent-schema.included_packages');
                $this->components->info('Run `php artisan eloquent-schema:discover` to find and configure vendor packages');
                $this->newLine();
            } else {
                $this->components->info('Configured vendor packages: '.implode(', ', $includedPackages));
                $this->newLine();
            }
        }

        $models = $discoveryService->getModels($includeVendor, $filter);

        if ($this->option('count')) {
            $this->components->info(sprintf('Total models: %d', count($models)));

            return self::SUCCESS;
        }

        if (empty($models)) {
            $this->components->warn('No models found.');

            return self::SUCCESS;
        }

        // Separate app and vendor models
        $appModels = [];
        $vendorModels = [];

        foreach ($models as $model) {
            if (str_starts_with($model, 'App\\')) {
                $appModels[] = $model;
            } else {
                $vendorModels[] = $model;
            }
        }

        // Display App models
        if (! empty($appModels)) {
            $this->components->info(sprintf('App Models (%d)', count($appModels)));
            $this->newLine();
            $this->displayGroupedModels($appModels);
        }

        // Display Vendor models (fully qualified for easy copy/paste)
        if (! empty($vendorModels)) {
            $this->newLine();
            $this->components->info(sprintf('Vendor Models (%d)', count($vendorModels)));
            $this->newLine();
            sort($vendorModels);
            foreach ($vendorModels as $model) {
                $this->line(sprintf('  %s', $model));
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('App models', (string) count($appModels));
        if ($includeVendor) {
            $this->components->twoColumnDetail('Vendor models', (string) count($vendorModels));
        }
        $this->components->twoColumnDetail('<fg=bright-white>Total</>', (string) count($models));

        return self::SUCCESS;
    }

    /**
     * Display models grouped by namespace.
     *
     * @param  array<string>  $models
     */
    protected function displayGroupedModels(array $models): void
    {
        $grouped = [];
        foreach ($models as $model) {
            $parts = explode('\\', $model);
            $className = array_pop($parts);
            $namespace = implode('\\', $parts);
            $grouped[$namespace][] = $className;
        }

        ksort($grouped);

        foreach ($grouped as $namespace => $classes) {
            sort($classes);
            $this->components->twoColumnDetail(
                sprintf('<fg=yellow>%s</>', $namespace),
                implode(', ', $classes)
            );
        }
    }
}
