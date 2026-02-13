<?php

namespace Mreycode\LegacyMigrator;

use Mreycode\LegacyMigrator\Console\LegacyMigrator as LegacyMigratorCommand;
use Illuminate\Support\Facades\DB;
use Mreycode\LegacyMigrator\Enums\MigratorStatus;
use Mreycode\LegacyMigrator\Models\LegacyMigrator as LegacyMigratorModel;
use Mreycode\LegacyMigrator\Concerns\HasMigrationLegacy;
use RuntimeException;
use Throwable;

abstract class AbstractLegacyMigrator
{
    use HasMigrationLegacy;

    protected $groupName = null;
    protected $cacheStats = true;
    protected $queueIndex = null;
    // This will identify whether to keep this migration on the stack as pending,
    // even if there is no source data from the legacy app. This allows new data
    // to be migrated from legacy to new later on.
    protected $keepOnRunning = false;
    // Property to determine if migration should keep running until a certain totalSize is reached
    protected $keepOnUntilTotalSize = false;
    protected $dbConnection = 'flai';

    private static $sourceConnection = null;
    private $migrationOptions;
    private $batch = 1;
    private $currentClass;
    private $activeMigration;

    private $params;

    /**
     * Get the source data from the legacy database.
     *
     * @return mixed
     */
    abstract public function sourceData();

    /**
     * Handle the migration process.
     *
     * @return void
     */
    abstract public function handle($params = null);

    public function __construct()
    {
        $this->loadOptions();
        
        $connection = $this->dbConnection ?? config('legacy-migrator.source_connection', 'legacy');
        self::$sourceConnection = DB::connection($connection);

        if(strtolower($this->groupName) === strtolower(LegacyMigratorCommand::GROUP_SPECIAL_KEYWORD)) {
            throw new RuntimeException("Group name cannot be set to the special keyword '" . LegacyMigratorCommand::GROUP_SPECIAL_KEYWORD . "' in a migrator.");
        }
    }

    /**
     * Execute the migration for a specific LegacyMigration record.
     *
     * @param LegacyMigratorModel $legacyMigrator
     * @return void
     */
    public function run(LegacyMigratorModel $legacyMigrator)
    {
        try {
            DB::beginTransaction();
            $this->printMigrationStatus("Migration started.");

            $result = $this->process($legacyMigrator);

            if($result['size'] == 0 && $this->shouldKeepOnUntilTotalSize()) {
                $this->markAsSuccess($legacyMigrator, $result->toArray());
                $this->newPendingMigration($legacyMigrator);
                $this->printMigrationStatus("No data to migrate, new pending migration.");
            } else {
                if($result['size'] == 0) {
                    if($this->shouldKeepOnRunning()) {
                        $legacyMigrator->status = MigratorStatus::PENDING->value;
                        $legacyMigrator->save();
                        $this->printMigrationStatus("No data to migrate, keeping migration pending.");
                    } else {
                        $this->markAsDone($legacyMigrator);
                        $this->printMigrationStatus("No data to migrate.");
                    }
                } else {
                    $this->markAsSuccess($legacyMigrator, $result->toArray());
                    $this->newPendingMigration($legacyMigrator);
                    $this->printMigrationStatus("Migration succeeded.");
                }
            }

            DB::commit();
        } catch (Throwable $throwable) {
            DB::rollBack();
            $this->printMigrationStatus("Migration error: " . $throwable->getMessage());
            $this->printMigrationStatus("Trace: " . $throwable->getTraceAsString());
            $this->markAsFailed($legacyMigrator, $throwable->getMessage());
            throw $throwable;
        }

        $this->printMigrationStatus("Migration saved.");
    }

    public function shouldTerminate()
    {
       return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->whereIn('status', [MigratorStatus::DONE->value, MigratorStatus::FAILED->value])
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Show the current migration progress/status.
     *
     * Calls displayMigrationProgress to output progress information to the user.
     */
    public function showStats(bool $truth = false)
    {
        $this->displayMigrationProgress($truth);
    }

    /**
     * Count the number of migrations by status.
     *
     * @return array
     */
    public function countStatus(): array
    {
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    public function restart()
    {
        $restart = null;
        if($this->onRestart()) {
            $restart = $this->markAllAsRestart();
            $this->markFirstBatchAsPending();

        }

        return $restart;
    }

    public function pause()
    {
        $this->markAsPaused();
    }

    public function resume()
    {
        // Find the most recent migration for this migrator, regardless of current status
        $lastMigration = LegacyMigratorModel::where('migrate', $this->currentClass)
            ->orderByDesc('batch')
            ->first();

        if ($lastMigration) {
            $this->markAsPending($lastMigration);
            $this->printMigrationStatus("Resumed migration batch {$lastMigration->batch}: status set to pending.");
        } else {
            $this->printMigrationStatus("No previous migration found to resume.");
        }
    }
    
    public function migrate()
    {   
        $currentClass = static::class;
        $datetime = now()->toDateTimeString();
        $this->printMigrationStatus("[" . $datetime . "] Processing migration: {$currentClass}");
        $this->createPendingMigration();
        $this->printMigrationStatus("[" . $datetime . "] INFO  Queued migration: {$currentClass}");
    }

    /**
     * Print a reusable migration status message with called class.
     *
     * @param string $message
     * @param string|null $color
     * @return void
     */
    protected function printMigrationStatus(string $message): void
    {
        $datetime = now()->toDateTimeString();
        $plainMessage = "[" . get_called_class() . "][$datetime] {$message}\n";
        print($plainMessage);
    }

    /**
     * Determine if this migration should remain pending ("keep on running").
     *
     * @return bool
     */
    protected function shouldKeepOnRunning(): bool
    {
        return (bool) $this->keepOnRunning;
    }

    /**
     * Determine if this migration should remain pending until the true totalSize is reached.
     *
     * @return bool
     */
    protected function shouldKeepOnUntilTotalSize(): bool
    {
        if (!$this->keepOnUntilTotalSize) {
            return false;
        }

        return $this->totalMigrated() < $this->totalSize();
    }

    protected function getMeta()
    {
        return [ 'options' => $this->getOptions() ];
    }

    protected function getOptions()
    {
        return $this->migrationOptions;
    }

    protected function buildOptions($params = [])
    {
        return [];
    }

    public function getSourceConnetion()
    {
        return static::$sourceConnection ?? null;
    }

    protected function transformSourceData()
    {
        return $this->sourceData();
    }

    protected function countSourceData($data = null): int
    {
        $data ??= $this->transformSourceData();

        if (is_array($data)) {
            return count($data);
        }

        // Check for Laravel/Eloquent Collection or Arrayable instances
        if (is_object($data)) {
            // If it's a Laravel Collection
            if (method_exists($data, 'count')) {
                return $data->count();
            }
            // If it can be converted to array
            if (method_exists($data, 'toArray')) {
                return count($data->toArray());
            }
        }

        // As fallback, try to cast and count
        return is_countable($data) ? count($data) : 0;
    }

    public function markFirstBatchAsPending()
    {
        $firstMigration = LegacyMigratorModel::where('migrate', $this->currentClass)
            ->orderBy('batch', 'asc')
            ->first();

        if ($firstMigration) {
            $firstMigration->status = MigratorStatus::PENDING->value;
            $firstMigration->meta = $this->getMeta();
            $firstMigration->save();
            return $firstMigration;
        }

        return null;
    }

    public function markAsPending(LegacyMigratorModel $legacyMigrator)
    {
        $legacyMigrator->status = MigratorStatus::PENDING->value;
        $legacyMigrator->save();
        return $legacyMigrator;
    }

    public function markAsDone(LegacyMigratorModel $legacyMigrator)
    {
        $legacyMigrator->status = MigratorStatus::DONE->value;
        $legacyMigrator->save();
        return $legacyMigrator;
    }

    public function markAsPaused()
    {
        $firstMigration = LegacyMigratorModel::where('migrate', $this->currentClass)
            ->orderBy('id', 'desc')
            ->first();

        if ($firstMigration) {
            $firstMigration->status = MigratorStatus::PAUSED->value;
            $firstMigration->save();
            return $firstMigration;
        }

        return null;
    }

    protected function markAllAsRestart()
    {
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->update(['status' => MigratorStatus::RESTART->value]);
    }

    protected function markAsSuccess(LegacyMigratorModel $legacyMigrator, array $result)
    {
        $legacyMigrator->status = MigratorStatus::SUCCESS->value;
        $legacyMigrator->total_migrated = $result['size'];
        $legacyMigrator->save();
        return $legacyMigrator;
    }

    public function markAsFailed(LegacyMigratorModel $legacyMigrator, $message)
    {
        // Trim the message if it's too long (e.g., max 1024 chars)
        $maxMsgLen = 1024;
        $trimmedMsg = (is_string($message) && strlen($message) > $maxMsgLen)
            ? substr($message, 0, $maxMsgLen) . '...'
            : $message;

        $legacyMigrator->message = $trimmedMsg;
        $legacyMigrator->status = MigratorStatus::FAILED->value;

        $legacyMigrator->save();
        return $legacyMigrator;
    }

    protected function createPendingMigration()
    {
        $existing = $this->activeMigration;

        if($existing && in_array($existing?->status, $this->getActiveMigrationStatuses())) {
            throw new RuntimeException(sprintf(
                'Cannot create migration: found existing migration (status: %s, batch: %s) for [%s].',
                $existing->status ?? 'unknown',
                $existing->batch ?? 'n/a',
                $this->currentClass
            ));
        }

        // Get the last successful batch using this class's method.
        $lastSuccessBatch = $this->getLastBatchSuccessMigration();
        $this->batch = ($lastSuccessBatch?->batch + 0) + 1;

        $options = $lastSuccessBatch?->meta['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        $this->migrationOptions = $this->buildOptions($options);
     
        $this->storeLegacyMigrations(
            new LegacyMigratorDto(
                $this->currentClass,
                MigratorStatus::PENDING,
                $this->batch,
                meta: $this->getMeta()
            )
        );
    }

    protected function newPendingMigration($legacyMigrator)
    {
        $this->batch = ($legacyMigrator?->batch ?? 0) + 1;

        $existingRetryLegacyMigration = $this->getFirstRestartMigration();

        if($existingRetryLegacyMigration) {
            $existingRetryLegacyMigration->status = MigratorStatus::PENDING->value;
            $existingRetryLegacyMigration->meta = $this->getMeta();
            $existingRetryLegacyMigration->save();
        } else {
            $this->storeLegacyMigrations(
                new LegacyMigratorDto(
                    $this->currentClass,
                    MigratorStatus::PENDING,
                    $this->batch,
                    meta: $this->getMeta()
                )
            );
        }
    }

    public function getFirstRestartMigration()
    {
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::RESTART->value)
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Returns the total number of items available for migration.
     * 
     * By default, returns 0. Override in subclasses to provide accurate counts.
     *
     * @return int
     */
    protected function totalSize()
    {
        // NOTE: Subclasses should override this to return the actual legacy data size.
        return 0;
    }

    /**
     * Returns the actual number of successfully migrated items.
     *
     * By default, returns 0. Subclasses should override this for real logic.
     *
     * @return int
     */
    protected function actualMigrated()
    {
        // NOTE: Subclasses should override this to return how many items have been migrated.
        return $this->countMigrated();
    }

    /**
     * Returns the total number of items that have been migrated so far.
     *
     * By default, returns 0. Override in subclasses for actual progress tracking.
     *
     * @return int
     */
    protected function totalMigrated()
    {
        // NOTE: Subclasses should override this to return how many items have been migrated.
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::SUCCESS->value)
            ->sum('total_migrated');
    }

    public function getMigrationStats(bool $truth = false)
    {
        $cacheKey = $this->getTotalMigratedCacheKey();

        $migrationStats = function () {
            $totalSize = $this->totalSize();
            $actualMigrated = $this->actualMigrated();
            $totalMigrated = $this->totalMigrated();
            $remaining = $totalSize - ($actualMigrated == 0 ? $totalMigrated : $actualMigrated);
            $statusCounts = $this->countStatus();

            return [
                'total_size' => $totalSize,
                'actual_migrated' => $actualMigrated,
                'total_migrated' => $totalMigrated,
                'remaining' => $remaining,
                'status_counts' => $statusCounts,
            ];
        };

        if(!$this->cacheStats || $truth) return $migrationStats();

        return cache()->remember($cacheKey, LegacyMigratorCommand::WATCHER_INTERVAL, callback: $migrationStats);
    }

    public function getQueueIndex()
    {
        return $this->queueIndex;
    }

    public function getGroupName()
    {
        return $this->groupName;
    }

    protected function throwIfCountMismatch($sourceDataCount)
    {
        // If a closure is provided, execute it to get the stored count for comparison
        $storedCount = $this->getStoredData();

        // Only check if $storedCount is set and is numeric
        if ($storedCount !== null && is_numeric($storedCount)) {
            if ($sourceDataCount !== $storedCount) {
                $errorMessage = "Migration count mismatch: source data count ({$sourceDataCount}) does not match stored count ({$storedCount})";
                $this->findUnsavedData($errorMessage);
                throw new RuntimeException($errorMessage);
            }
        }
    }


    protected function findUnsavedData($message)
    {
        return null;
    }

    protected function getParams()
    {
        return $this->params;
    }

    protected function getStoredData()
    {
        return null;
    }

    protected function onRestart(): bool
    {
        return true;
    }

 
    private function storeLegacyMigrations(?LegacyMigratorDto $legacyMigratorDto)
    {
        $payload = $legacyMigratorDto->toArray();
        $migrationIdentifier = [
            'migrate' => $payload['migrate'],
            'batch' => $payload['batch']
        ];

        if ($legacyMigratorDto?->id) {
            $migrationIdentifier['id'] = $legacyMigratorDto->id;
        }

        return LegacyMigratorModel::updateOrCreate(
            $migrationIdentifier,
            $payload
        );
    }

    private function getTotalMigratedCacheKey()
    {
        return 'legacy_migrated_total:' . $this->currentClass;
    }

    private function displayMigrationProgress(bool $truth = false)
    {
        printf("\n%-18s: %s\n%-18s: %s\n", "Migration Class", get_class($this), "Report Date", date('Y-m-d H:i:s'));
        $stats = $this->getMigrationStats($truth);

        $divider = str_repeat('-', 39) . "\n";
        print($divider);
        print("STATS\n");
        print($divider);

        printf(
            "Recorded Migrated : %d\nActual Migrated   : %d\nTotal             : %d\nRemaining         : %d\n",
            $stats['total_migrated'],
            $stats['actual_migrated'],
            $stats['total_size'],
            $stats['remaining']
        );

        // Show memory usage
        $memUsage = memory_get_usage(true);
        $memPeak = memory_get_peak_usage(true);

        // Inline function to format bytes to human-readable string
        $formatBytes = function ($bytes) {
            if ($bytes < 1024) {
                return $bytes . ' B';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            $exp = (int) (log($bytes) / log(1024));
            return round($bytes / (1024 ** $exp), 2) . ' ' . $units[$exp];
        };

        printf("\nMemory Usage      : %s\nMemory Peak       : %s\n",
            $formatBytes($memUsage),
            $formatBytes($memPeak)
        );

        print($divider);
        print("STATUS\n");
        print($divider);

        foreach ($stats['status_counts'] as $status => $count) {
            printf("%-14s: %d\n", ucfirst($status), $count);
        }

        print($divider);
        print("Note: These numbers may not be perfectly precise due to differences in migration logic, caches, or source data updates.\n");
    }

    private function loadOptions()
    {
        $this->migrationOptions ??= $this->buildOptions();
        $this->currentClass = get_class($this);
        $this->activeMigration = $this->getActiveLegacyMigration();
    }

    private function process(LegacyMigratorModel $legacyMigrator)
    {
        $meta = $legacyMigrator->meta;
        $this->migrationOptions = $meta['options'];
        $params = $this->params = $this->buildHandleParams();
        if($params['size'] > 0 || $this->shouldKeepOnUntilTotalSize()) {
            $this->handle($params);
            $this->throwIfCountMismatch($params['size'] ?? 0);
            $this->migrationOptions = $this->buildOptions($meta['options']);
        }

        return $params;
    }

    /**
     * Build data to be passed into the handle() method as params.
     * You may override this in subclasses for custom behavior.
     */
    private function buildHandleParams()
    {
        $sourceData = $this->transformSourceData();
        return collect([ 
            'sourceData' => $sourceData,
            'size' => $this->countSourceData($sourceData)
        ]);
    }

    private function getActiveLegacyMigration()
    {
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->whereIn('status', $this->getActiveMigrationStatuses())
            ->orderByDesc('batch')
            ->first();
    }

    private function getLastBatchSuccessMigration()
    {
        return LegacyMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::SUCCESS->value)
            ->orderByDesc('batch')
            ->first();
    }

    /**
     * Get all statuses that represent a migration still pending resolution.
     *
     * @return array
     */
    private function getActiveMigrationStatuses(): array
    {
        return [
            MigratorStatus::PENDING->value,
            MigratorStatus::ONGOING->value,
            MigratorStatus::FAILED->value,
        ];
    }

}