<?php

namespace Mreycode\LegacyMigrator\Concerns;

use Mreycode\LegacyMigrator\Models\MigrationLegacy;

trait HasMigrationLegacy
{
    public $migrationLegacySourceName = null;
    public $migrationLegacyMigrationTableName = null;
    
    public function migrationLegacy($sourceData, $legacyId, $pk = 'id')
    {
        $sourceName = $this->getMigrationLegacySourceName();
        $migrationTableName = $this->getMigrationLegacyMigrationTableName();

        $forMigrationLegacy = $sourceData->map(function($item) use($sourceName, $migrationTableName, $legacyId, $pk) {
            return [
                'source_name' => $sourceName,
                'legacy_id' => $item[$legacyId],
                'pk_id' => $item[$pk],
                'migration_table_name' => $migrationTableName,
            ];
        });

        MigrationLegacy::upsert($forMigrationLegacy->toArray(), ['source_name', 'migration_table_name', 'legacy_id'], ['pk_id']);

        return $sourceData->map(function($item) use($legacyId) {
            return collect($item)->except([$legacyId])->toArray();
        });
    }

    public function getMigrationLegacySource()
    {
        $sourceName = $this->getMigrationLegacySourceName();
        $migrationTableName = $this->getMigrationLegacyMigrationTableName();

        return MigrationLegacy::where('source_name', $sourceName)
            ->where('migration_table_name', $migrationTableName);
    }

    public function getMigrationLegacy(array $legacyIds)
    {
        return $this->getMigrationLegacySource()
            ->whereIn('legacy_id', $legacyIds)
            ->get();
    }

    public function countMigrated()
    {
        return $this->getMigrationLegacySource()->count();
    }

    public function getMigrationLegacySourceName()
    {
        return $this->migrationLegacySourceName ?? static::class;
    }

    public function getMigrationLegacyMigrationTableName()
    {
        return $this->migrationLegacyMigrationTableName ?? static::class;
    }
}