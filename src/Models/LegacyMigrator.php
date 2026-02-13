<?php
namespace Mreycode\LegacyMigrator\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyMigrator extends Model
{
    protected $table = 'legacy_migrator';

    protected $fillable = [
        'migrate',
        'status',
        'batch',
        'total_migrated',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
