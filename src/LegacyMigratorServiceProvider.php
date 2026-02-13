<?php

namespace Mreycode\LegacyMigrator;

use Illuminate\Support\ServiceProvider;
use Mreycode\LegacyMigrator\Console\LegacyMigrator;
use Mreycode\LegacyMigrator\Console\LegacyMigratorWorker;
use Mreycode\LegacyMigrator\Console\MakeLegacyMigrator;

class LegacyMigratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/legacy-migrator.php',
            'legacy-migrator'
        );
    }

    public function boot(): void
    {
        $this->publishesFiles();

        if ($this->app->runningInConsole()) {
            $this->commands([
                LegacyMigrator::class,
                LegacyMigratorWorker::class,
                MakeLegacyMigrator::class
            ]);
        }
        
    }

    public function publishesFiles()
    {
        // Publish config
        $this->publishesMigrations([
            __DIR__ . '/../database/migrations/' => database_path('migrations')
        ], 'legacy-migrator-migrations');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/legacy-migrator.php' => config_path('legacy-migrator.php'),
        ], 'legacy-migrator-config');
    }
}
