<?php declare(strict_types=1);

namespace Alantiller;

use Alantiller\Database\Exceptions\ConfigurationException;
use Alantiller\Database\Exceptions\MigrationException;
use Alantiller\Database\MigrationManager;
use Medoo\Medoo;
use PDO;
use PDOException;
use PDOStatement;

/** Medoo query API with explicit connection configuration and opt-in migrations. */
class Database extends Medoo
{
    private MigrationManager $migration_manager;
    private bool $migration_active = false;

    /**
     * Connect through Medoo or reuse an application-owned PDO connection.
     *
     * Construction never creates tables or runs migrations. Exception error mode
     * is mandatory. Medoo's own connection setup still applies (including MySQL
     * ANSI_QUOTES); configure timezone and SQLite foreign keys in the application.
     *
     * @param array<string, mixed>|PDO $options Medoo options or an existing PDO instance.
     * @param array<string, mixed> $migration_config Optional path, table and lock_timeout (seconds).
     * @throws ConfigurationException If wrapper configuration is invalid.
     * @throws \InvalidArgumentException If Medoo rejects its connection options.
     * @throws PDOException If connection setup fails.
     */
    public function __construct(array|PDO $options, array $migration_config = [])
    {
        if ($options instanceof PDO) {
            $options = ['pdo' => $options, 'type' => $options->getAttribute(PDO::ATTR_DRIVER_NAME)];
        }
        if (($options['testMode'] ?? false) !== false) {
            throw new ConfigurationException('Medoo testMode cannot be used with this wrapper.');
        }
        $prefix = $options['prefix'] ?? '';
        if (!is_string($prefix) || ($prefix !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $prefix) !== 1)) {
            throw new ConfigurationException('The table prefix must be a simple SQL identifier.');
        }
        // Validate migration configuration before opening a connection.
        MigrationManager::validate_config($migration_config, $prefix);
        $options['error'] = PDO::ERRMODE_EXCEPTION;
        parent::__construct($options);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migration_manager = new MigrationManager($this, $migration_config, $prefix);
    }

    /**
     * List physical table names in the current database, including any prefix.
     *
     * SQLite internal tables and views are excluded. No schema is changed.
     * @return list<string> Table names in ascending order.
     * @throws ConfigurationException If the driver is not SQLite or MySQL.
     * @throws PDOException If metadata cannot be read.
     */
    public function get_tables(): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\_%' ESCAPE '\\' ORDER BY name";
        } elseif ($driver === 'mysql') {
            $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
        } else {
            throw new ConfigurationException('Table inspection supports SQLite and MySQL only.');
        }
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new PDOException('Could not read database tables.');
        }
        /** @var list<string> $tables */
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
        return $tables;
    }

    /**
     * Check a logical Medoo table name, applying the configured prefix once.
     * @param string $table Unqualified table name without a prefix.
     * @return bool Whether a base table with this name exists.
     * @throws ConfigurationException If the table name or driver is unsupported.
     * @throws PDOException If metadata cannot be read.
     */
    public function table_exists(string $table): bool
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
            throw new ConfigurationException('The table name must be a simple SQL identifier.');
        }
        return in_array(($this->prefix ?? '') . $table, $this->get_tables(), true);
    }

    /**
     * Apply every unrecorded migration in ascending version order as a new batch.
     * @return list<string> Applied 14-digit versions; empty when already current.
     * @throws ConfigurationException If the path, driver or transaction state is invalid.
     * @throws MigrationException If locking, validation, migration code or persistence fails.
     */
    public function migration_run(): array
    {
        return $this->execute_migration(false);
    }

    /**
     * Reverse the latest batch in descending version order.
     * @return list<string> Reverted versions; empty when there is no applied batch.
     * @throws ConfigurationException If the path, driver or transaction state is invalid.
     * @throws MigrationException If locking, validation, migration code or persistence fails.
     */
    public function migration_rollback(): array
    {
        return $this->execute_migration(true);
    }

    /**
     * Preserve the original runner's boolean result without framework output.
     * @return bool True on success (including no work), false on a migration failure.
     * @throws ConfigurationException If migration configuration or execution context is invalid.
     * @deprecated Use migration_run() for applied versions and diagnostic exceptions.
     */
    public function migrationRun(): bool
    {
        try {
            $this->migration_run();
            return true;
        } catch (MigrationException) {
            return false;
        }
    }

    /**
     * Preserve the original rollback's boolean result without framework output.
     * @return bool True on success (including no work), false on a migration failure.
     * @throws ConfigurationException If migration configuration or execution context is invalid.
     * @deprecated Use migration_rollback() for reverted versions and diagnostic exceptions.
     */
    public function migrationRollback(): bool
    {
        try {
            $this->migration_rollback();
            return true;
        } catch (MigrationException) {
            return false;
        }
    }

    /**
     * Preserve the original status method name with the new integrity-aware result.
     * @return array{applied: list<array{version: string, batch: int, checksum: string, created_at: string}>, pending: list<string>, missing: list<string>, changed: list<string>, total_migrations: int}
     * @throws ConfigurationException If migration configuration is invalid.
     * @throws MigrationException If migration files or the ledger cannot be read.
     * @deprecated Use migration_status().
     */
    public function migrationStatus(): array
    {
        return $this->migration_status();
    }

    /**
     * Execute Medoo SQL while preventing debug mode from recording unapplied migrations.
     *
     * Outside migrations this preserves Medoo's debug and execution behaviour.
     * The camelCase method/property names in this override are inherited Medoo contracts.
     * @param string $statement SQL statement generated by Medoo or supplied by the caller.
     * @param array<string, array{mixed, int}> $map Medoo parameter value/type bindings.
     * @param callable(PDOStatement): void|null $callback Optional inherited statement callback.
     * @return PDOStatement|null Executed statement or Medoo's normal debug/failure result.
     * @throws MigrationException If migration SQL would be skipped by a debug mode.
     * @throws \Throwable If PDO, Medoo or the callback fails.
     */
    public function exec(string $statement, array $map = [], ?callable $callback = null): ?PDOStatement
    {
        if ($this->migration_active && ($this->debugMode || $this->testMode)) {
            throw new MigrationException('Medoo debug/test modes cannot execute migrations.');
        }
        return parent::exec($statement, $map, $callback);
    }

    /**
     * Mark migration execution so nested runs and debug-only SQL cannot corrupt the ledger.
     * @param bool $rollback Whether to reverse the latest batch.
     * @return list<string> Completed migration versions.
     * @throws ConfigurationException If a migration is active or Medoo debug mode is enabled.
     * @throws MigrationException If the migration manager cannot complete the batch.
     */
    private function execute_migration(bool $rollback): array
    {
        if ($this->migration_active || $this->debugMode || $this->testMode) {
            throw new ConfigurationException('Migrations require normal execution mode and cannot run recursively.');
        }
        $this->migration_active = true;
        try {
            return $rollback ? $this->migration_manager->rollback() : $this->migration_manager->run();
        } finally {
            $this->migration_active = false;
        }
    }

    /**
     * Read ledger state and compare migration file checksums without executing PHP files.
     *
     * This method does not create the ledger or acquire a lock; its snapshot may
     * become stale during a deployment. Applied files that are missing or changed
     * are reported and prevent subsequent run/rollback operations.
     * @return array{applied: list<array{version: string, batch: int, checksum: string, created_at: string}>, pending: list<string>, missing: list<string>, changed: list<string>, total_migrations: int}
     * @throws ConfigurationException If the path or driver is invalid.
     * @throws MigrationException If files or ledger cannot be read.
     */
    public function migration_status(): array
    {
        return $this->migration_manager->status();
    }

    /**
     * Write a new anonymous migration skeleton without overwriting an existing file.
     * @param string $version Exactly 14 digits, conventionally a UTC YYYYMMDDHHMMSS timestamp.
     * @return string Path of the new file; the configured directory must already exist.
     * @throws ConfigurationException If the path or version is invalid.
     * @throws MigrationException If the file exists or cannot be written.
     */
    public function migration_create(string $version): string
    {
        return $this->migration_manager->create($version);
    }
}
