<?php

namespace Mreycode\LegacyMigrator\Console;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Mreycode\LegacyMigrator\Enums\MigratorActions;
use Mreycode\LegacyMigrator\Enums\MigratorStatus;
use Mreycode\LegacyMigrator\Models\LegacyMigrator as LegacyMigratorModel;
use RuntimeException;

class LegacyMigrator extends Command
{
    const GROUP_SPECIAL_KEYWORD = 'show';

    const WATCHER_INTERVAL = 30;
    protected $signature = 'legacy:migrate
        {--r|retry : Retry failed or pending migrations}
        {--c|class= : The migration class to run (e.g., FlaiUserMigrate), default is none}
        {--s|stats : Display status information for one or all migration classes}
        {--C|continues-stats : Continuously display stats until interrupted}
        {--R|restart : If set, completely restart migrations for the selected class or all classes}
        {--u|resume : Continue migration from last "done" status}
        {--i|interactive : Show list of migration classes and select by index}
        {--g|group= : The migration group to run (e.g., users, posts), default is none}
        {--L|last-run : Show the last migrated class}
        {--p|pause : Pause an ongoing migration}'
    ;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run legacy migration classes to import data from the old database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('retry')) {
            $this->retryFailedMigrations();
            return;
        }

        $groupOption = $this->option('group');
        $classOption = $this->option('class');
        $interactiveOption = $this->option('interactive');
        $lastRun = $this->option('last-run');

        if($lastRun) {
            $this->getLastRunMigrationClass();
            return;
        }

        $this->getLastRunMigrationClass();

        // Action - Options
        $possibleActions = $this->getActions()
            ->keys()
            ->filter(function ($key) {
                return !in_array($key, [MigratorActions::EXIT->value, MigratorActions::MIGRATE->value]);
            })
            ->values()
            ->toArray();

        $action = MigratorActions::MIGRATE->value;
        foreach ($possibleActions as $a) {
            if ($this->option($a)) {
                $action = $a;
                break;
            }
        }
       
        $migrations = $this->getMigrationFiles();
        if (empty($migrations)) {
            $this->error('No migration classes found.');
            return;
        }

        if($groupOption) {
            $this->runGroup($groupOption);
            return;
        }

        if($interactiveOption) {
            $this->interactiveCommand($migrations);
            return;
        }

        if ($classOption) {
            // If --class is specified, only run that migration if it's in the allowed set
            $migrationClass = collect($migrations)
                ->first(fn($m) => class_basename($m) === $classOption);

            if (!$migrationClass) {
                $this->error("Migration class '{$classOption}' not found in allowed migrations.");
                return;
            }

            $migrator = new $migrationClass();
            $this->handleMigratorAction($migrator, action: $action);
            $this->storeLastRunMigrationClass($migrationClass);

        } else {
            // Show migration list to be executed, from first to last
            $migrationNames = collect($migrations)
                ->map(function($class, $idx) {
                    $name = class_basename($class);
                    if (method_exists($class, 'getDescription')) {
                        $desc = (new $class)->getDescription();
                        return $desc ? "{$name} - {$desc}" : $name;
                    }
                    return $name;
                })
                ->values()
                ->toArray();
            
            $this->info("The following migrations will be executed (from first to last):");
            foreach ($migrationNames as $index => $name) {
                $this->line("[".($index+1)."] {$name}");
            }

            // Confirm with the user before proceeding
            if (!$this->confirm('Do you wish to run ALL of the above migrations?')) {
                $this->info('Aborting migrations.');
                return;
            }
   
            // Run all migrations
            foreach ($migrations as $migrationClass) {
                $migrator = new $migrationClass();
                $this->handleMigratorAction($migrator, $action);
                $this->storeLastRunMigrationClass($migrationClass);
            }
        }
    }

    public function retryFailedMigrations()
    {
        $affected = LegacyMigratorModel::where('status', MigratorStatus::FAILED->value)
            ->update([
                'status' => MigratorStatus::PENDING->value,
                'message' => null
            ]);

        $this->info("Retried {$affected} failed migration(s) by setting their status to pending.");
    }

    protected function interactiveCommand(array $migrations)
    {
           // Provide an interactive CLI menu to select and run migrations
           $choiceMap = [];
           foreach ($migrations as $index => $class) {
               $instance = new $class();
               $desc = method_exists($instance, 'getDescription') 
                   ? $instance->getDescription() 
                   : (method_exists($instance, 'description') ? $instance->description : '');
               $name = class_basename($class);
               $choiceMap["{$index}"] = "{$name}" . ($desc ? " - {$desc}" : "");
           }

           $selected = $this->choice(
               'Select the legacy migration to run',
               array_values($choiceMap),
               0,
               null,
               false
           );

           // Find selected class
           $selectedIndex = array_search($selected, array_values($choiceMap));
           $selectedClass = $migrations[$selectedIndex] ?? null;

           if (!$selectedClass) {
               $this->error("Invalid selection.");
               return;
           }
           $migrator = new $selectedClass();
           $actions = $this->getActions();

           $action = $this->choice(
               "What do you want to do with '{$selected}'?",
               $actions->values()->toArray(),
               0,
               null,
               false
           );
           
           // Show the corresponding artisan command for user's reference
           $actionKey = $actions->search($action);
           $selectedClassName = class_basename($selectedClass);
           $actionCommand = $actionKey === 'migrate' ? "--class={$selectedClassName}"  : "--class={$selectedClassName} --{$actionKey}";
           $this->storeLastRunMigrationClass($selectedClass);
           // $actions find by value and return key
           $this->handleMigratorAction($migrator, $actionKey);
           if(!in_array($actionKey, [MigratorActions::EXIT->value])) {
               $this->displayShowCommand($actionCommand);
           }
           return;
    }

    protected function displayShowCommand($actionCommand = '')
    {
        $commandBase = 'php artisan legacy:migrate';
        $this->info("Command: {$commandBase} {$actionCommand}");
    }

    protected function getMigrationFiles()
    {
        $migrationDir = app_path('LegacyMigrators');
        
        if (!is_dir($migrationDir)) {
            return [];
        }

        $migrationFiles = scandir($migrationDir);

        $configSequence = $this->getSequenceFromConfig(); // Already in desired sequence

        $configPriority = [];
        $highPriority = [];
        $lowPriority = [];

        foreach ($migrationFiles as $file) {
            if (!preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', $file)) {
                continue;
            }

            $className = pathinfo($file, PATHINFO_FILENAME);
            $fullyQualifiedClass = "App\\LegacyMigrators\\{$className}";

            if (!class_exists($fullyQualifiedClass)) {
                continue;
            }

            // If class is in the config sequence, push it to configPriority
            if (in_array($className, $configSequence, true)) {
                $configPriority[$className] = $fullyQualifiedClass; // Use className as key to preserve order later
                continue;
            }

            $instance = new $fullyQualifiedClass();
            $queueIndex = method_exists($instance, 'getQueueIndex') ? $instance->getQueueIndex() : null;

            if ($queueIndex === null) {
                $lowPriority[] = $fullyQualifiedClass;
            } else {
                // Avoid collisions in highPriority
                // If the index already exists, find next available index after
                $insertIndex = $queueIndex - 1;
                while (isset($highPriority[$insertIndex])) {
                    $insertIndex++;
                }
                $highPriority[$insertIndex] = $fullyQualifiedClass;
            }
        }

        ksort($highPriority); // Sort queueIndex
        // Preserve the sequence from configSequence exactly
        $orderedConfigPriority = [];
        foreach ($configSequence as $name) {
            if (isset($configPriority[$name])) {
                $orderedConfigPriority[] = $configPriority[$name];
            }
        }

        $migrations = [...$orderedConfigPriority, ...array_values($highPriority), ...$lowPriority];

        return $migrations;
    }


    protected function watchStats($migrator)
    {
        $this->info("Watching migration stats. Press Ctrl+C to stop.");

        // Use ctrl-c handler if possible
        declare(ticks = 1);
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function() {
            $this->info("\nStopped watching stats.");
            exit(0);
        });

        // Avoid unnecessary clearing if not running in terminal
        $isCli = php_sapi_name() === 'cli' && function_exists('posix_isatty') && posix_isatty(STDOUT);

        while (true) {
            if ($isCli) {
                if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
                    // On Windows, use `cls`
                    if (function_exists('system')) {
                        system('cls');
                    }
                } else {
                    // On *nix, use ANSI escape
                    echo "\033c";
                }
            }
            $migrator->showStats();
            
            $interval = config('legacy-migrator.monitoring.interval', self::WATCHER_INTERVAL);
            sleep($interval);
            if($legacyData = $migrator->shouldTerminate()) {
                if ($legacyData->status === MigratorStatus::FAILED->value) {
                    $message = $legacyData->message ?? 'Migration failed with no additional message.';
                    throw new RuntimeException("Migration failed: {$message}");
                }
                $migrator->showStats(true);
                $this->info("\nMigration is done. Exiting stats watcher.");
                exit(0);
            }
        }
    }

    protected function handleMigratorAction($migrator, $action)
    {
        switch ($action) {
            case MigratorActions::STATS->value :
                $this->showStatsAction($migrator);
                break;
            case MigratorActions::RESTART->value:
                $this->restartAction($migrator);
                break;
            case MigratorActions::RESUME->value:
                $this->resumeAction($migrator);
                break;
            case MigratorActions::PAUSE->value:
                $this->pauseAction($migrator);
                break;
            case MigratorActions::MIGRATE->value:
                $this->migrateAction($migrator);
                break;
            case MigratorActions::CONTINUES_STATS->value:
                $this->watchStats($migrator);
                break;
            case MigratorActions::EXIT->value:
                $this->info('Exiting migration command.');
                return;
            default:
                throw new InvalidArgumentException("Unknown action: {$action}");
        }
    }

    protected function showStatsAction($migrator)
    {
        $this->comment('Displaying migration status...');
        $migrator->showStats();
    }

    protected function restartAction($migrator)
    {
        $count = $migrator->restart();
        $this->comment("Restarting migration... ({$count} rows affected)");
    }

    protected function resumeAction($migrator)
    {
        $this->info('Resuming migration...');
        $migrator->resume();
    }

    protected function pauseAction($migrator)
    {
        $this->info('Pausing migration...');
        $migrator->pause();
    }

    protected function migrateAction($migrator)
    {
        $migrator->migrate();
    }

    protected function getActions()
    {
        return collect([
            MigratorActions::MIGRATE->value => 'Migrate',
            MigratorActions::STATS->value => 'Show Migration Stats',
            MigratorActions::RESTART->value => 'Restart Migration',
            MigratorActions::RESUME->value => 'Resume Migration',
            MigratorActions::PAUSE->value => 'Pause Migration',
            MigratorActions::CONTINUES_STATS->value => 'Continuously Show Stats',
            MigratorActions::EXIT->value => 'Exit'
        ]);
    }

    protected function getLastRunMigrationClass()
    {
        if($lastClass = cache()->get('legacy-migrate:last_run_migration_class')) {
            $this->info("Last migrated class: {$lastClass}");
        }
    }

    protected  function storeLastRunMigrationClass($classOption)
    {
        cache()->forever('legacy-migrate:last_run_migration_class', $classOption);
    }

    protected function runGroup(string $groupName)
    {

        $selectedGroup = $this->getGroup();
        if (blank($selectedGroup)) {
            throw new Exception("No group found");
        }

        if($groupName === self::GROUP_SPECIAL_KEYWORD) {
            // Elegant display of available groups (no table)
            $this->info("Available Migration Groups:\n");

            foreach ($selectedGroup as $group => $migrations) {
                $this->comment($group);
                foreach ($migrations as $migrationClass) {
                    $classShort = class_basename($migrationClass);
                    $desc = '';
                    if (method_exists($migrationClass, 'getDescription')) {
                        $descVal = (new $migrationClass())->getDescription();
                        if ($descVal) {
                            $desc = " - {$descVal}";
                        }
                    }
                    $this->line("    - {$classShort}{$desc}");
                }
                $this->line(""); // Add space between groups
            }

            exit(0);
        }

        if (!isset($selectedGroup[$groupName])) {
            $groupNames = array_keys($selectedGroup);
            $groupList = implode(", ", $groupNames);
            $this->error("Group '{$groupName}' not found. Available groups are: {$groupList}");
            return;
        }
        $migrations = $selectedGroup[$groupName];

        $this->info("Preparing to run migrations for the group: \"{$groupName}\".\n\nThe following migrations will be executed in order:");
        foreach ($migrations as $index => $name) {
            $this->line("[".($index+1)."] {$name}");
        }

        $actions = $this->getActions()->except([MigratorActions::CONTINUES_STATS->value]);

        $action = $this->choice(
            "What do you want to do with the group: '{$groupName}'?",
            $actions->values()->toArray(),
            0,
            null,
            false
        );

        $actionKey = $actions->search($action);
        
        foreach ($migrations as $migrationClass) 
        {
            $selectedClassName = class_basename($migrationClass);
            $migrator = new $migrationClass();
            $actionCommand = $actionKey === 'migrate' ? "--class={$selectedClassName}"  : "--class={$selectedClassName} --{$actionKey}";
  
            $this->storeLastRunMigrationClass($migrationClass);
            $this->handleMigratorAction($migrator, $actionKey);
            if(!in_array($actionKey, [MigratorActions::EXIT->value])) {
                $this->displayShowCommand($actionCommand);
            }
        }

        $this->info("✅ Group '{$groupName}' has been successfully executed.");
    }

    protected function getGroup()
    {
        $instances = $this->getMigrationFiles();
        $groupMigrations = [];

        foreach ($instances as $instanceClass) {
            $instance = new $instanceClass();
            $groupName = method_exists($instance, 'getGroupName') && !empty($instance->getGroupName())
                ? $instance->getGroupName()
                : 'default';

            $groupMigrations[$groupName][] = $instanceClass;
        }
        return $groupMigrations;
    }

    private function getSequenceFromConfig()
    {
        return config('legacy-migrator.sequence', []);
    }
}
