<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Migration Sequence
    |--------------------------------------------------------------------------
    |
    | Here you can define the order in which your migration classes should run.
    | Migrations listed here will be executed first, in the order specified,
    | before any other discovered migrations.
    |
    */

    'sequence' => [],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Specify the default database connection name for your legacy source.
    | This can be overridden within individual migrator classes.
    |
    */

    'source_connection' => env('LEGACY_DB_CONNECTION', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the status monitoring and watcher commands.
    |
    | interval: Seconds between refreshes in --continues-stats mode.
    |
    */

    'monitoring' => [
        'interval' => 30,
    ],
];
