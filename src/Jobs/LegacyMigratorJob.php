<?php

namespace Mreycode\LegacyMigrator\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Mreycode\LegacyMigrator\Models\LegacyMigrator as LegacyMigratorModel;
use ReflectionClass;
use Throwable;

class LegacyMigratorJob implements ShouldQueue
{
    use Queueable;
    protected $tries = 5;
    protected $timeout = 1000000;
    protected ?LegacyMigratorModel $legacyMigration;
    /**
     * Create a new job instance.
     */
    public function __construct(LegacyMigratorModel $legacyMigration)
    {
        $this->legacyMigration = $legacyMigration;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $legacyMigration = $this->legacyMigration;
        $migrateClass = new ReflectionClass($legacyMigration->migrate);
        $migrator = $migrateClass->newInstance();

        try {
            $migrator->run($legacyMigration);
        } catch (Throwable $throwable) {
            if ($this->attempts() >= $this->tries) {
                $message = "[LegacyMigrator failed]: {$throwable->getMessage()}\nTrace:\n{$throwable->getTraceAsString()}";
                $migrator->markAsFailed($legacyMigration, $message);
            } else {
                $this->release(5);
            }
        }
    }
}
