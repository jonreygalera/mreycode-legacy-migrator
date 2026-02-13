<?php
namespace Mreycode\LegacyMigrator\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationLegacy extends Model
{
    protected $table = 'migration_legacy';

    protected $fillable = [
        'source_name',
        'legacy_id',
        'pk_id',
        'migration_table_name',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
