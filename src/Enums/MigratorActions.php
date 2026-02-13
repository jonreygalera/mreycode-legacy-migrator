<?php

namespace Mreycode\LegacyMigrator\Enums;

enum MigratorActions: string
{
    case STATS = 'stats';
    case RESTART = 'restart';
    case RESUME = 'resume';
    case PAUSE = 'pause';
    case MIGRATE = 'migrate';
    case CONTINUES_STATS = 'continues-stats';
    case EXIT = 'exit';

}