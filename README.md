# Legacy Migrator for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mreycode/legacy-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/legacy-migrator)
[![Total Downloads](https://img.shields.io/packagist/dt/mreycode/legacy-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/legacy-migrator)
[![License](https://img.shields.io/packagist/l/mreycode/legacy-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/legacy-migrator)

A production-grade Laravel package designed to handle complex, large-scale database migrations. Unlike simple migration scripts, this package provides a **resumable, monitored, and batch-aware** framework for moving data from legacy systems to modern Laravel applications.

---

## 🚀 Key Value Proposition

- **🛡️ Robustness:** Automatic database transactions per batch—if one record fails, the batch rolls back gracefully.
- **📊 Monitoring:** Built-in dashboard and console tracking for real-time migration stats (percentage complete, memory usage, etc.).
- **🔄 Resumability:** Interrupted migrations pick up exactly where they left off, preventing duplicate records.
- **📦 Queue-First:** Designed to scale using Laravel Queues, allowing you to offload heavy migrations to background workers.
- **🧩 State Persistence:** Maintain state between batches easily via persistent metadata.

---

## 🛠️ Installation

```bash
composer require mreycode/legacy-migrator

# Publish migrations and config
php artisan vendor:publish --provider="Mreycode\LegacyMigrator\LegacyMigratorServiceProvider"

# Run the package migrations
php artisan migrate
```

---

## 📖 How It Works

The core of the package is the `AbstractLegacyMigrator`. You create a class for each entity (e.g., `UserMigrator`, `ProductMigrator`) that defines two main methods:

1.  `sourceData()`: Fetches the next batch of raw data from the legacy DB.
2.  `handle()`: Processes and maps that data into your new system.

### Example Implementation

```php
namespace App\Migrations;

use Mreycode\LegacyMigrator\AbstractLegacyMigrator;
use App\Models\User;

class UserMigrator extends AbstractLegacyMigrator
{
    /**
     * Define where the data comes from.
     * This query is executed per batch.
     */
    public function sourceData()
    {
        // Use the built-in source connection to fetch legacy data
        return $this->getSourceConnetion()
            ->table('old_users')
            ->where('migrated', 0)
            ->limit(100)
            ->get();
    }

    /**
     * Define how to save the data.
     * $params contains 'sourceData' and 'size'.
     */
    public function handle($params = null)
    {
        foreach ($params['sourceData'] as $row) {
            $user = User::create([
                'name'  => $row->full_name,
                'email' => $row->email_address,
                'password' => bcrypt('secret'),
            ]);

            // Track the migration to ensure it's not repeated
            $this->migrationLegacy(collect([$user]), 'legacy_id', 'id');

            // Mark as migrated in source DB (optional)
            $this->getSourceConnetion()
                ->table('old_users')
                ->where('id', $row->id)
                ->update(['migrated' => 1]);
        }
    }
}
```

---

## 🖥️ Console Commands

The package provides a sophisticated CLI suite to manage, monitor, and scale your migrations.

### 1. The Migration Orchestrator

The `legacy:migrate` command is your primary interface. It can be run interactively or automated via flags.

```bash
# General Syntax
php artisan legacy:migrate [options]
```

| Flag | Full Option         | Description                                                             |
| :--- | :------------------ | :---------------------------------------------------------------------- |
| `-i` | `--interactive`     | **Recommended.** Opens a menu to select a specific migrator and action. |
| `-c` | `--class=Name`      | Run a specific migrator class directly (e.g., `--class=UserMigrator`).  |
| `-g` | `--group=Name`      | Run a specific group of migrators defined in your classes.              |
| `-s` | `--stats`           | Display a detailed snapshot of migration progress and memory usage.     |
| `-C` | `--continues-stats` | Real-time monitoring "dashboard" that auto-refreshes every 30 seconds.  |
| `-u` | `--resume`          | Resume the latest batch for a migrator from where it stopped.           |
| `-r` | `--retry`           | Batch-retry all failed migrations by resetting their status to pending. |
| `-R` | `--restart`         | Wipe progress for a class and start from batch #1.                      |
| `-p` | `--pause`           | Gracefully signal an ongoing migration to stop after the current batch. |
| `-L` | `--last-run`        | Quickly identify which migration class was executed last.               |

---

### 2. The Background Worker

For large datasets, use the dedicated worker. It’s designed to be memory-safe and signal-aware (graceful shutdowns).

```bash
php artisan legacy:worker --max-jobs=100 --memory=1024
```

- **`--max-jobs`**: Prevents "leaky" processes by restarting the worker after X jobs.
- **`--memory`**: Hard limit (in MB). The worker will exit cleanly if exceeded, preventing OOM errors on your server.

---

### 3. Group Operations

Management at scale. You can group related migrations (e.g., `core`, `billing`, `inventory`) and execute them as a unit.

```bash
# List all available groups and their assigned classes
php artisan legacy:migrate --group=show

# Execute all migrators in the 'users' group
php artisan legacy:migrate --group=users
```

---

### 4. Developer Scaffolding

Quickly generate standardized migrator classes.

```bash
php artisan legacy:make-migrator ProductMigrator
```

> [!IMPORTANT]
> To be automatically discovered by the `legacy:migrate` command, all migrator classes **must** be located in the `app/LegacyMigrators` directory. This is the default location for the scaffolding tool.

---

---

## ⚙️ Configuration

After publishing, the configuration file is located at `config/legacy-migrator.php`.

```php
return [
    // Define the execution order of your migrator classes
    'sequence' => [
        'UserMigrator',
        'ProfileMigrator',
    ],

    // Global default connection for legacy data
    'source_connection' => env('LEGACY_DB_CONNECTION', 'legacy'),

    // Monitoring settings for console commands
    'monitoring' => [
        'interval' => 30, // Refresh rate for --continues-stats
    ],
];
```

### Advanced Class-Level Overrides

You can still customize behavior per migrator class by overriding these properties:

```php
protected $groupName = 'users';        // Group migrators for bulk actions — optional
protected $dbConnection = 'legacy';    // Specify which connection to use — optional
protected $keepOnRunning = true;       // Keep the migrator active for incoming data — optional
protected $cacheStats = true;          // Performance optimization for large datasets — optional
```

---

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
