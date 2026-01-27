<?php

declare(strict_types=1);

namespace Visualbuilder\EloquentSchema\Console\Commands;

use Illuminate\Console\Command;
use Visualbuilder\EloquentSchema\Services\ModelDiscoveryService;
use Visualbuilder\EloquentSchema\Services\ModelSchemaService;

class CacheModelsCommand extends Command
{
    protected $signature = 'eloquent-schema:cache
                            {--no-schema : Skip preloading individual model schemas}
                            {--max-depth=1 : Maximum depth for relationship exploration when caching schemas}';

    protected $description = 'Warm the Eloquent schema discovery and model schema cache';

    public function handle(ModelDiscoveryService $discoveryService, ModelSchemaService $schemaService): int
    {
        $this->components->info('Warming Eloquent schema cache...');

        $stats = $discoveryService->warmCache();

        $this->components->twoColumnDetail('Application models', (string) $stats['app']);
        $this->components->twoColumnDetail('Vendor models', (string) $stats['vendor']);
        $this->components->twoColumnDetail('Total models', (string) $stats['total']);

        if (! $this->option('no-schema')) {
            $this->newLine();

            $maxDepth = (int) $this->option('max-depth');

            if ($maxDepth > 1) {
                $this->components->warn("Depth {$maxDepth} may use significant memory for large codebases.");
            }

            $this->components->info('Preloading model schemas...');

            $models = $discoveryService->getModels(includeVendor: true);
            $memoryLimit = $this->getMemoryLimitBytes();

            $progressBar = $this->output->createProgressBar(count($models));
            $progressBar->start();

            $cached = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($models as $model) {
                // Check memory usage - stop if approaching limit
                if ($memoryLimit > 0 && memory_get_usage(true) > $memoryLimit * 0.85) {
                    $progressBar->finish();
                    $this->newLine(2);
                    $this->components->error('Approaching memory limit - stopping early. Try --max-depth=1');
                    $skipped = count($models) - $cached - $failed;
                    break;
                }

                try {
                    $schemaService->getSchema($model, $maxDepth);
                    $cached++;
                } catch (\Throwable) {
                    $failed++;
                }

                $progressBar->advance();

                // Free memory between models
                gc_collect_cycles();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->components->twoColumnDetail('Schemas cached', (string) $cached);
            if ($failed > 0) {
                $this->components->twoColumnDetail('Failed', (string) $failed);
            }
            if ($skipped > 0) {
                $this->components->twoColumnDetail('Skipped (memory)', (string) $skipped);
            }
        }

        $this->newLine();
        $this->components->success('Eloquent schema cache warmed successfully.');

        return self::SUCCESS;
    }

    protected function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
