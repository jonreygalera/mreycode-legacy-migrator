<?php

namespace Mreycode\LegacyMigrator\Enums;

enum MigratorStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case ONGOING = 'ongoing';
    case FAILED = 'failed';
    case RESTART = 'restart';
    case DONE = 'done';
    case PAUSED = 'paused';

    public static function statusTypes(): array
    {
        return [
            self::ACTIVE->value => 'Active',
            self::INACTIVE->value => 'Inactive',
        ];
    }
}
