<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;

class ClearCacheCommand extends Command
{
    protected $signature = 'eloquent-schema:clear
                            {--schema : Also clear individual model schema caches}';

    protected $description = 'Clear the Eloquent schema discovery cache';

    public function handle(ModelDiscoveryService $discoveryService): int
    {
        $this->components->info('Clearing Eloquent schema cache...');

        // Get models before clearing (so we know which schema caches to clear)
        $models = [];
        if ($this->option('schema')) {
            $models = $discoveryService->getModels(true);
        }

        // Clear model discovery cache
        $discoveryService->clearCache();
        $this->components->twoColumnDetail('Model discovery cache', 'Cleared');

        // Clear individual schema caches if requested
        if ($this->option('schema') && ! empty($models)) {
            $count = 0;
            foreach ($models as $model) {
                // Clear cache for depths 1, 2, and 3
                for ($depth = 1; $depth <= 3; $depth++) {
                    $cacheKey = 'eloquent_schema_'.md5($model.'_'.$depth);
                    if (Cache::forget($cacheKey)) {
                        $count++;
                    }
                }
            }
            $this->components->twoColumnDetail('Schema caches cleared', (string) $count);
        }

        $this->newLine();
        $this->components->success('Eloquent schema cache cleared successfully.');

        return self::SUCCESS;
    }
}
