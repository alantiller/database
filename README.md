# alantiller/database

A PHP database library built around **`new Database($connection, $migration_config)`**. It extends [Medoo](https://medoo.in/api/new), retaining its query and schema API, and adds versioned application migrations, batch rollback, integrity checks, table inspection and a CLI.

The public class is **`Alantiller\Database`**. Migration contracts and exceptions live under `Alantiller\Database\`. There is no Flight dependency, global configuration, logger or automatic migration on connection.

## Installation

Requires PHP 8.1+, PDO, and the PDO driver for your database. Migration management and table inspection support SQLite and MySQL 8.0+ with InnoDB. Other Medoo backends remain available for inherited query methods but are not supported by the migration runner. MariaDB is not separately verified.

Once published to a Composer repository:

```sh
composer require alantiller/database
```

Until publication, use a Composer path repository in your application:

```json
{
    "repositories": [{"type": "path", "url": "../database"}],
    "require": {"alantiller/database": "@dev"}
}
```

For development in this checkout, run `composer install`. Production consumers can install resolved dependencies with `composer install --no-dev`.

## Connect and query

```php
<?php declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Alantiller\Database;

$database = new Database([
    'type' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'my_app',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'command' => ["SET time_zone = '+00:00'"],
], [
    'path' => __DIR__ . '/migrations',
]);

// The application has already installed its contacts schema through migrations.
$database->insert('contacts', ['email' => 'example@example.com']);
$contact = $database->get('contacts', '*', ['email' => 'example@example.com']);
$exists = $database->table_exists('contacts');
$tables = $database->get_tables();
```

The first argument accepts [Medoo connection options](https://medoo.in/api/new). The wrapper forces exception error mode and rejects `testMode`. Medoo's connection setup still applies, including enabling ANSI_QUOTES on MySQL; it does not preserve the connection's original SQL mode. Your application configures timezone, connection timeouts, foreign keys and any additional SQL modes it requires. Credentials and configuration values are not stored in wrapper exception messages.

SQLite example:

```php
$database = new Database([
    'type' => 'sqlite',
    'database' => __DIR__ . '/app.sqlite',
    'command' => ['PRAGMA foreign_keys = ON', 'PRAGMA busy_timeout = 5000'],
], ['path' => __DIR__ . '/migrations']);
```

SQLite connection creation can create its database file; it never installs application tables implicitly. The configured migration directory must already exist. Use absolute paths based on your application's `__DIR__` so deployment commands do not depend on the working directory.

### Share PDO with auth and permission

```php
use Alantiller\Auth;
use Alantiller\Database;
use Alantiller\Permission;

$pdo = new PDO('sqlite:' . __DIR__ . '/app.sqlite');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');

$database = new Database($pdo, ['path' => __DIR__ . '/migrations']);
$auth = new Auth($database->pdo, $auth_config);
$permissions = new Permission($database->pdo, $permission_config);
```

`$auth_config` and `$permission_config` are application-owned configuration arrays for those libraries. They are independent packages, not dependencies of this wrapper. The same connection is reused and PDO error mode becomes exceptions. With an existing PDO, configure connection commands yourself before constructing the wrapper. Supply `['pdo' => $pdo, 'type' => 'mysql', 'prefix' => 'app_']` when you also need Medoo options.

Migrations should run in a trusted deployment process with a dedicated connection, before serving requests. Auth and permission table SQL belongs in your application's migrations; this package does not install either library's schema automatically.

## Database and table management

Medoo's `create()`, `drop()`, `query()`, `action()` and CRUD/aggregation methods remain inherited. See the [Medoo API](https://medoo.in/api/new) for their contracts. The wrapper adds:

| Method | Behaviour |
| --- | --- |
| `get_tables(): array` | Sorted list of physical base-table names in the current database, including prefixes; excludes views and SQLite internal tables. |
| `table_exists(string $table): bool` | Checks an unqualified logical table name, applying Medoo's prefix once. |
| `migration_create(string $version): string` | Exclusively writes `Version{version}.php` in the configured directory; returns its absolute path. |
| `migration_run(): array` | Applies pending migrations in ascending version order; returns completed version strings. |
| `migration_rollback(): array` | Reverses only the latest batch in descending version order; returns reverted version strings. |
| `migration_status(): array` | Returns ledger rows, pending versions, integrity differences and file count without executing migration PHP. |

Ordinary Medoo operations do not create a migration ledger. Database-server provisioning, database deletion, backup/restore, HTTP administration, seeding and application authorization remain outside this package. Destructive schema changes are explicit Medoo calls or application migrations; there is no automatic reset/drop-all operation.

## Write a migration

Each PHP file in the configured directory must be named `Version` followed by exactly 14 digits and `.php`. Use a unique UTC `YYYYMMDDHHMMSS` timestamp by convention. Non-PHP files are ignored; malformed PHP filenames cause an error. Only the top level is scanned.

Each file returns an object implementing `Alantiller\Database\Migration`. Anonymous classes permit repeated loading in a long-running process:

```php
<?php declare(strict_types=1);

use Alantiller\Database;
use Alantiller\Database\Migration;

return new class implements Migration {
    /**
     * Install the application's contact table.
     * @param Database $database Runner-owned connection.
     * @return void
     * @throws \PDOException If schema creation fails.
     */
    public function up(Database $database): void
    {
        $database->create('contacts', [
            'email' => ['VARCHAR(254)', 'NOT NULL', 'PRIMARY KEY'],
        ]);
    }

    /**
     * Remove the contacts table, permanently deleting its data.
     * @param Database $database Runner-owned connection.
     * @return void
     * @throws \PDOException If schema removal fails.
     */
    public function down(Database $database): void
    {
        $database->drop('contacts');
    }
};
```

Save this as `migrations/Version20261018000000.php`.

Migration files are trusted executable application code. Keep file-level code limited to returning the migration object; status never requires the files, while execution loads all selected files before calling the first migration. Do not declare reusable named classes/functions in repeatedly loaded files; put helpers in application autoloaded code instead. Do not replace files while a runner is active.

Generate a skeleton through `$database->migration_create('20261018000001')` or the CLI. Generated callbacks throw until implemented, preventing accidental recording of an unfinished migration. Generation never overwrites an existing file or creates a directory.

## Run, inspect and roll back

```php
$applied = $database->migration_run();       // ['20261018000000'], or [] when current
$status = $database->migration_status();
$reverted = $database->migration_rollback(); // Latest batch only; [] when empty
```

Every successful run with pending migrations assigns one batch number. Versions are tracked individually; a newly introduced older version is pending even if a newer version has already run. Repeated runs are safe when the files and ledger agree. Rollback reverses the latest **batch**, then leaves those versions pending for a subsequent run. Batch numbers can be reused after a full rollback of the latest batch.

Status returns:

```php
[
    'applied' => [
        [
            'version' => '20261018000000',
            'batch' => 1,
            'checksum' => '...sha256 of the original file...',
            'created_at' => '2026-10-18 00:00:00', // UTC
        ],
    ],
    'pending' => [],   // Version strings without a ledger row
    'missing' => [],   // Applied versions whose files are absent
    'changed' => [],   // Applied versions whose SHA-256 differs
    'total_migrations' => 1, // Number of valid migration files currently present
]
```

Applied rows are ordered by version; all version lists are sequential arrays. Status is read-only and does not acquire a deployment lock, so it can become stale while another runner executes. Its `applied` list comes from the ledger; it does not prove that the live schema has never been altered externally.

Applied migration files are immutable. Missing or changed applied files block both run and rollback before any callbacks execute. Restore the original files and put further changes in a new version. Keep application releases, ledger backups and database backups together. There is no automatic checksum repair, baseline import or history deletion command.

The runner creates its ledger only during an explicit run/rollback. Columns are `version` (primary key), `batch`, `checksum` and `created_at` (UTC). Do not use an application table name for the ledger.

### Configuration

The optional second constructor argument accepts only:

| Key | Default | Meaning |
| --- | --- | --- |
| `path` | `null` | Existing readable local directory. Null disables migration operations until configured. |
| `table` | `'migrations'` | Simple unqualified logical ledger name; prefix applied once. Physical name must be at most 64 bytes. |
| `lock_timeout` | `10` | MySQL advisory-lock wait in seconds, integer 0–3600. SQLite uses the connection's `busy_timeout` instead. |

Table/prefix identifiers use ASCII letters, digits and underscores, starting with a letter or underscore. Prefixes are optional. Medoo's query API retains its own identifier rules; wrapper table inspection and ledger names use the stricter rules above.

### Transactions, locking and failure recovery

- **SQLite:** one write transaction covers the complete batch, its schema/data changes and ledger updates. A failure rolls the whole batch back. Other writers may wait or receive a busy error according to `busy_timeout`.
- **MySQL:** one advisory lock, keyed by database and physical ledger name, serializes this runner. Each migration starts a transaction. Transactional DML and its ledger change commit together while that transaction remains active. Completed migrations remain recorded if a later migration fails.
- **MySQL DDL:** statements such as CREATE/ALTER/DROP may commit implicitly. Changes and subsequent writes may therefore survive a callback or ledger failure without an applied record. The runner cannot guarantee atomic schema changes. Use InnoDB, take backups and inspect/repair the affected schema before retrying. Do not treat a failed migration as automatically safe to rerun.

Run and rollback reject caller-owned transactions. Callbacks must use the supplied connection and must not manage transactions, enable Medoo debug/test modes, recursively run migrations, modify the ledger, or replace/close the connection. Raw PDO and external side effects remain application responsibilities. Filesystem/email/network changes cannot be rolled back with SQL. Medoo debug mode is rejected for migrations because it skips execution; it remains available for ordinary queries.

A failed invocation stops immediately and throws; it does not return a partial completion list. Inspect `migration_status()` after failure. MySQL locks are released on exit; SQLite releases its write lock when the transaction ends. Advisory locks coordinate this package's runners, not unrelated administration SQL or writers using a different ledger name. Use a single stable ledger configuration for each application schema.

## CLI

Create an application-owned PHP configuration file returning `connection` and optional `migrations` options:

```php
<?php declare(strict_types=1);

return [
    'connection' => [
        'type' => 'sqlite',
        'database' => __DIR__ . '/app.sqlite',
        'command' => ['PRAGMA foreign_keys = ON', 'PRAGMA busy_timeout = 5000'],
    ],
    'migrations' => ['path' => __DIR__ . '/migrations'],
];
```

```sh
vendor/bin/database status /absolute/path/to/database-config.php
vendor/bin/database make /absolute/path/to/database-config.php 20261018000001
vendor/bin/database migrate /absolute/path/to/database-config.php
vendor/bin/database rollback /absolute/path/to/database-config.php
vendor/bin/database tables /absolute/path/to/database-config.php
```

In this checkout use `php bin/database` instead of `vendor/bin/database`. Commands print JSON on success. Exit statuses: `0` success, `1` configuration/execution failure, `2` invalid command/arguments. Failures go to stderr. `status` exits successfully when it can report state, even if it reports missing/changed files; deployment automation can inspect its JSON.

The configuration file is trusted PHP executed by the CLI. Credentials belong outside the package and source control. Keep this executable out of public web routes. The application/deployment process authorizes destructive operations; rollback runs the application's `down()` code immediately.

## Errors

`ConfigurationException` and `MigrationException` extend `Alantiller\Database\Exceptions\BaseException`. Their readonly `error_code` values are `configuration_error` and `migration_error`. Migration failures retain the original exception as `getPrevious()` without copying its message to the wrapper message.

Inherited Medoo/PDO operations and connection setup keep their native exception contracts. Previous exceptions and query/debug logs can contain sensitive SQL/values; inspect them privately. The PHP API does not print progress or invoke a logger. Your application decides how to display results and log failures. The CLI omits underlying exception details.

## Upgrade from the original Database.php

This is an extraction from an application service, not a drop-in constructor replacement:

1. Install/autoload this package and replace the OpenBookingSystem namespace import with `use Alantiller\Database;`.
2. Replace `new Database($basePath)` with explicit Medoo/PDO options and an absolute migration path. Translate Flight configuration in the application, not inside the library.
3. Convert each `Version{version}.php` to return a migration object implementing the documented interface. Remove assumptions about the OpenBookingSystem migration namespace and Flight's global services.
4. Use `migration_run()`, `migration_rollback()` and `migration_status()`. The first two return version lists and throw on failure. Route logging and progress output through your application.
5. Plan adoption of existing migration history separately. The old UUID/version/batch ledger has no checksums and is incompatible with this ledger. Do not point this runner at an already-migrated production schema with a new empty ledger: it would try to run all files again. Use a fresh database for initial adoption, or explicitly reconcile and import verified versions/checksums during a controlled deployment with backups.

Deprecated `migrationRun()` and `migrationRollback()` aliases preserve boolean success/failure for migration execution errors, without printing output. Configuration/context errors still throw. `migrationStatus()` delegates to the new status shape, whose applied rows include checksums rather than the old UUID column. These aliases do not restore the old constructor, namespace or file/ledger format.

## Development

```sh
composer install
composer check
```

`composer check` runs PHPStan level 8 and PHPUnit. Tests use in-memory SQLite and temporary directories/files by default, including CLI subprocess tests. PHPStan targets PHP 8.1 syntax. In restricted environments that block PHPStan's local worker socket, run `vendor/bin/phpstan analyse --no-progress --debug` to analyse in one process, followed by `composer test`.

For the optional MySQL integration backend, use a **disposable test database** and a user allowed to create/drop tables and acquire advisory locks:

```sh
DATABASE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=database_test;charset=utf8mb4' \
DATABASE_TEST_MYSQL_USER='test_user' \
DATABASE_TEST_MYSQL_PASSWORD='test_password' \
composer test
```

Integration tables use random `dtest_` prefixes and cleanup targets only that test's prefix. CLI tests remain SQLite-based. Never point test configuration at production. See [Development notes](#development-notes) below for implementation decisions, verification and release work.

## Development notes

### 2026-10-18 — Extract alantiller/database

#### Purpose and package conventions

Converted the application-specific root Database.php into a Composer library. Inspected the local alantiller/auth and alantiller/permission repositories for their actual conventions: PHP >=8.1, public classes under Alantiller, PSR-4 source, MIT metadata, readonly machine-readable exception categories, PHPUnit 10.5, PHPStan 2 at level 8 and Composer test/analyse/check scripts. Medoo remains the runtime query/schema dependency; application-specific Flight and UUID utility dependencies are removed.

Public entry point: `Alantiller\Database` in `src/Database.php`. Migration contract/internal manager/exceptions use `Alantiller\Database\`. New project-defined methods/properties use snake_case; deprecated original methods and inherited Medoo overrides retain public compatibility names.

#### Implementation decisions

- Keep Medoo inheritance so CRUD, raw queries, schema management, action transactions and PDO access remain familiar. Connection options or a preconfigured PDO are explicit constructor dependencies.
- Construction creates no tables and runs no migrations. SQLite may create a database file through normal connection establishment. Timezone/foreign keys are application-owned configuration.
- Add table existence/list inspection, migration skeleton generation, run, latest-batch rollback, read-only status and a JSON CLI.
- Migration files return interface implementations (prefer anonymous classes). They are application-owned trusted PHP. Status hashes files without requiring them. Selected migration files are preflighted before callbacks.
- Track exact version membership instead of treating every version below MAX(version) as applied. This fixes skipped out-of-order versions in the original service.
- Ledger stores version, batch, file SHA-256 and UTC creation time. Missing/modified applied files block further mutations. No automatic repair/import is provided.
- SQLite acquires a write lock before reading batch state and executes a whole batch atomically. MySQL serializes this runner with a database/ledger-specific advisory lock and uses a transaction per migration while DDL has not implicitly committed it. MySQL DDL is explicitly not atomic with the ledger.
- Migrations reject outer transactions, nested execution and debug-only Medoo SQL. Callbacks must not manage transactions or replace the supplied connection. The application owns authorisation, deployment serialization beyond this runner, retries, backups and recovery from partially committed MySQL DDL.
- No destructive reset, server provisioning, backup/restore, web administration or implicit auth/permission schema installation is included. Table create/drop/query management stays in Medoo and application migrations.
- Keep original migrationRun/migrationRollback boolean aliases and migrationStatus alias as deprecated bridges. The old namespace, Flight constructor, migration class loading and UUID ledger cannot be retained as a framework-independent contract; the README documents adoption steps and production-history reconciliation requirements.

#### Verification

- Composer dependencies installed, including Medoo 2.6.0, PHPUnit 10.5.66 and PHPStan 2.3.1.
- `composer validate --strict` and PHP syntax checks for the facade, runner and CLI passed.
- `composer test` passed: 32 tests, 86 assertions on PHP 8.5.11 with real SQLite PDO/Medoo and separate CLI processes.
- `composer check` passed with the local PHPStan worker socket permitted: PHPStan level 8 (PHP 8.1 target) and the complete PHPUnit suite. The single-process fallback `vendor/bin/phpstan analyse --no-progress --debug` also passed in the restricted environment.
- Coverage includes inherited CRUD/schema methods, shared PDO, deterministic migration order, exact-membership backfills, repeated runs, reversed latest-batch rollback, failure recovery, checksum/missing-file checks, invalid migration contracts/configuration, caller transactions, skeleton exclusivity, debug mode and lock contention, plus CLI lifecycle/exit statuses using private test fixtures.
- No MySQL test server/DSN was supplied, so MySQL integration has not been run locally. The test suite supports an explicit disposable MySQL DSN and includes its advisory-lock contention path. PHP 8.1 runtime has not been run locally; syntax/API targeting is checked through PHPStan. No GitHub Actions configuration is included.

#### Release work

This directory started without Git metadata. No repository, release tag or Packagist publication was created. Before release, execute the MySQL integration backend, test supported PHP runtimes, review the documented API and publish through the normal Composer repository process. The composer.lock/vendor/cache files are local development artifacts excluded from version control like the sibling libraries.

### 2026-10-18 — Repository layout

Removed the examples and .github directories. All documentation, including development notes and CLI configuration, is maintained in this README. CLI integration tests generate their own private fixtures and do not depend on example files.
