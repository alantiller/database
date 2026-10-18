<?php declare(strict_types=1);

namespace Alantiller\Database;

use Alantiller\Database;
use Alantiller\Database\Exceptions\ConfigurationException;
use Alantiller\Database\Exceptions\MigrationException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Internal migration orchestration; applications use the Database facade.
 *
 * @phpstan-type Record array{version: string, batch: int, checksum: string, created_at: string}
 * @phpstan-type File array{path: string, checksum: string}
 * @phpstan-type Status array{applied: list<Record>, pending: list<string>, missing: list<string>, changed: list<string>, total_migrations: int}
 */
final class MigrationManager
{
    private ?string $path;
    private string $table;
    private string $physical_table;
    private int $lock_timeout;

    /**
     * Store already-validated configuration without changing schema.
     * @param Database $database Connection used for every migration and ledger operation.
     * @param array<string, mixed> $config Validated optional path, table and lock_timeout.
     * @param string $prefix Validated Medoo prefix.
     */
    public function __construct(private Database $database, array $config, string $prefix)
    {
        /** @var string|null $path */
        $path = $config['path'] ?? null;
        /** @var string $table */
        $table = $config['table'] ?? 'migrations';
        /** @var int $lock_timeout */
        $lock_timeout = $config['lock_timeout'] ?? 10;
        $this->path = $path;
        $this->table = $table;
        $this->physical_table = $prefix . $table;
        $this->lock_timeout = $lock_timeout;
    }

    /**
     * Reject unknown keys and unsafe identifiers before connecting.
     * @param array<string, mixed> $config Migration options; path may be null to disable migrations.
     * @param string $prefix Validated prefix included in the physical ledger identifier.
     * @return void
     * @throws ConfigurationException If any key, type, identifier or timeout is invalid.
     */
    public static function validate_config(array $config, string $prefix): void
    {
        if (array_diff(array_keys($config), ['path', 'table', 'lock_timeout']) !== []) {
            throw new ConfigurationException('Unknown migration configuration key.');
        }
        $path = $config['path'] ?? null;
        $table = $config['table'] ?? 'migrations';
        $timeout = $config['lock_timeout'] ?? 10;
        if ($path !== null && (!is_string($path) || trim($path) === '' || str_contains($path, "\0"))) {
            throw new ConfigurationException('Migration path must be a non-empty directory path or null.');
        }
        if (!is_string($table) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1 || strlen($prefix . $table) > 64) {
            throw new ConfigurationException('Migration table must be a simple identifier of at most 64 bytes including its prefix.');
        }
        if (!is_int($timeout) || $timeout < 0 || $timeout > 3600) {
            throw new ConfigurationException('Migration lock_timeout must be an integer from 0 to 3600 seconds.');
        }
    }

    /**
     * Apply all pending versions, including versions older than the highest applied one.
     * @return list<string> Applied versions in execution order.
     * @throws ConfigurationException If connection/path/transaction state is unsupported.
     * @throws MigrationException If migration integrity or execution fails.
     */
    public function run(): array
    {
        return $this->execute_batch(false);
    }

    /**
     * Revert only the latest recorded batch, highest version first.
     * @return list<string> Reverted versions in execution order.
     * @throws ConfigurationException If connection/path/transaction state is unsupported.
     * @throws MigrationException If migration integrity or execution fails.
     */
    public function rollback(): array
    {
        return $this->execute_batch(true);
    }

    /**
     * Compare file hashes to the ledger without requiring migration PHP files.
     * @return Status Current snapshot; this read does not lock or create the ledger.
     * @throws ConfigurationException If the driver or directory is invalid.
     * @throws MigrationException If files or ledger cannot be read.
     */
    public function status(): array
    {
        $this->driver();
        try {
            return $this->compare($this->files(), $this->records());
        } catch (MigrationException|ConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MigrationException('Could not read migration status.', 0, $exception);
        }
    }

    /**
     * Create a migration skeleton using exclusive file creation.
     * @param string $version Exactly 14 digits; choose a unique UTC timestamp by convention.
     * @return string Newly created absolute file path.
     * @throws ConfigurationException If version or migration directory is invalid.
     * @throws MigrationException If file creation or writing fails, including an existing file.
     */
    public function create(string $version): string
    {
        if (preg_match('/\A[0-9]{14}\z/', $version) !== 1) {
            throw new ConfigurationException('Migration version must contain exactly 14 digits.');
        }
        $path = $this->directory() . '/Version' . $version . '.php';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new MigrationException('Could not exclusively create migration Version' . $version . '.');
        }
        $template = <<<'PHP'
<?php declare(strict_types=1);

use Alantiller\Database;
use Alantiller\Database\Migration;

return new class implements Migration {
    /**
     * Apply the application schema/data change without managing transactions.
     * @param Database $database Runner-owned connection.
     * @return void
     * @throws \Throwable If the change fails.
     */
    public function up(Database $database): void
    {
        throw new \LogicException('Implement this migration before running it.');
    }

    /**
     * Restore the previous schema/data without managing transactions.
     * @param Database $database Runner-owned connection.
     * @return void
     * @throws \Throwable If reversal fails.
     */
    public function down(Database $database): void
    {
        throw new \LogicException('Implement the rollback before running it.');
    }
};

PHP;
        try {
            if (fwrite($handle, $template) !== strlen($template)) {
                throw new MigrationException('Could not write the complete migration skeleton.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw new MigrationException('Could not write migration skeleton.', 0, $exception);
        }
        fclose($handle);
        return $path;
    }

    /**
     * Execute one batch while owning the SQLite write transaction or MySQL advisory lock.
     *
     * SQLite commits the entire batch atomically. MySQL records each successful
     * migration with transactional DML where possible; DDL may implicitly commit and cannot be made atomic by this runner.
     * All selected files are loaded and checked before the first up/down callback.
     * @param bool $rollback Whether to reverse the latest batch instead of applying pending files.
     * @return list<string> Versions completed by a successful invocation.
     * @throws ConfigurationException If the caller owns a transaction or context is invalid.
     * @throws MigrationException If locking, validation, callbacks or persistence fails.
     */
    private function execute_batch(bool $rollback): array
    {
        $driver = $this->driver();
        $this->directory();
        $pdo = $this->database->pdo;
        if ($this->has_active_transaction()) {
            throw new ConfigurationException('Migrations require a connection without an active transaction.');
        }
        $lock_name = null;
        $version = null;
        $failed = false;
        try {
            if ($driver === 'mysql') {
                $schema = (string) $this->statement('SELECT DATABASE()')->fetchColumn();
                $lock_name = hash('sha256', $schema . ':' . $this->physical_table);
                $locked = $this->statement('SELECT GET_LOCK(:name, :timeout)', ['name' => $lock_name, 'timeout' => $this->lock_timeout])->fetchColumn();
                if ((int) $locked !== 1) {
                    $lock_name = null;
                    throw new MigrationException('Could not acquire the migration lock.');
                }
            } else {
                $pdo->beginTransaction();
            }
            $this->ensure_ledger();
            if ($driver === 'sqlite') {
                // Even an empty ledger must acquire the write lock before reading batch state.
                $this->statement('UPDATE ' . $this->quoted_table() . ' SET batch = batch WHERE 1 = 0');
            }
            $files = $this->files();
            $records = $this->records();
            $status = $this->compare($files, $records);
            if ($status['missing'] !== [] || $status['changed'] !== []) {
                throw new MigrationException('Applied migration files are missing or changed; restore the original files before continuing.');
            }
            $batch = 0;
            foreach ($records as $record) {
                $batch = max($batch, $record['batch']);
            }
            $versions = $status['pending'];
            if ($rollback) {
                $versions = [];
                foreach (array_reverse($records) as $record) {
                    if ($record['batch'] === $batch) {
                        $versions[] = $record['version'];
                    }
                }
            } else {
                ++$batch;
            }
            $migrations = [];
            foreach ($versions as $version) {
                $migrations[$version] = $this->load($files[$version]);
            }
            foreach ($versions as $version) {
                // Keep error mode predictable even if application code modified the public PDO handle.
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if ($driver === 'mysql') {
                    $pdo->beginTransaction();
                }
                if ($rollback) {
                    $migrations[$version]->down($this->database);
                } else {
                    $migrations[$version]->up($this->database);
                }
                if ($driver === 'sqlite' && !$this->has_active_transaction()) {
                    throw new MigrationException('Migration code ended the runner-owned transaction.');
                }
                if ($rollback) {
                    $this->statement('DELETE FROM ' . $this->quoted_table() . ' WHERE version = :version', ['version' => $version]);
                } else {
                    $this->statement('INSERT INTO ' . $this->quoted_table() . ' (version, batch, checksum, created_at) VALUES (:version, :batch, :checksum, :created_at)', [
                        'version' => $version, 'batch' => $batch,
                        'checksum' => $files[$version]['checksum'], 'created_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
                if ($driver === 'mysql' && $this->has_active_transaction()) {
                    $pdo->commit();
                }
            }
            if ($driver === 'sqlite') {
                $pdo->commit();
            }
            return $versions;
        } catch (Throwable $exception) {
            $failed = true;
            if ($this->has_active_transaction()) {
                $pdo->rollBack();
            }
            $context = $version === null ? '' : ' at Version' . $version;
            throw new MigrationException('Migration ' . ($rollback ? 'rollback' : 'run') . ' failed' . $context . '.', 0, $exception);
        } finally {
            if ($lock_name !== null) {
                try {
                    $this->statement('SELECT RELEASE_LOCK(:name)', ['name' => $lock_name]);
                } catch (Throwable $release_error) {
                    // Preserve the original migration fault if the connection also failed during cleanup.
                    if (!$failed) {
                        throw new MigrationException('Migration completed but lock release failed; close this connection.', 0, $release_error);
                    }
                }
            }
        }
    }

    /**
     * Inspect live transaction state after application code or DDL may have changed it.
     * @return bool Whether PDO currently owns an active transaction.
     * @phpstan-impure
     */
    private function has_active_transaction(): bool
    {
        return $this->database->pdo->inTransaction();
    }

    /**
     * Resolve the configured directory at use time; never create it implicitly.
     * @return string Absolute path to a readable local directory.
     * @throws ConfigurationException If migrations are disabled or directory is inaccessible.
     */
    private function directory(): string
    {
        $path = $this->path === null ? false : realpath($this->path);
        if ($path === false || !is_dir($path) || !is_readable($path)) {
            throw new ConfigurationException('Configure an existing readable local migration directory.');
        }
        return $path;
    }

    /**
     * Discover deterministic file versions without executing migration code.
     * @return array<array-key, File> Entries ordered by fixed-width version strings.
     * @throws ConfigurationException If the directory is unavailable.
     * @throws MigrationException If a PHP filename, file type or checksum is invalid.
     */
    private function files(): array
    {
        $directory = $this->directory();
        $names = scandir($directory);
        if ($names === false) {
            throw new MigrationException('Could not scan the migration directory.');
        }
        $files = [];
        foreach ($names as $name) {
            if (pathinfo($name, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            if (preg_match('/\AVersion([0-9]{14})\.php\z/', $name, $matches) !== 1) {
                throw new MigrationException('Migration PHP files must be named Version followed by 14 digits.');
            }
            $path = $directory . '/' . $name;
            if (!is_file($path) || !is_readable($path)) {
                throw new MigrationException('Migration file must be a readable regular file.');
            }
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new MigrationException('Could not hash a migration file.');
            }
            $files[$matches[1]] = ['path' => $path, 'checksum' => $checksum];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Load trusted application PHP in an isolated scope and enforce its return contract.
     * @param File $file Discovered migration path and checksum.
     * @return Migration Returned migration object; anonymous classes allow repeated loading.
     * @throws MigrationException If the return contract or file checksum is invalid.
     * @throws Throwable If application PHP fails while loading.
     */
    private function load(array $file): Migration
    {
        // File code receives no manager object or local ledger state.
        $migration = (static function (string $path): mixed { return require $path; })($file['path']);
        if (!$migration instanceof Migration || hash_file('sha256', $file['path']) !== $file['checksum']) {
            throw new MigrationException('Migration file must remain unchanged and return an implementation of Migration.');
        }
        return $migration;
    }

    /**
     * Read normalized ledger rows; an absent ledger is an empty migration history.
     * @return list<Record> Records ordered by version, with integer batch numbers.
     * @throws \PDOException If schema or ledger access fails.
     * @throws MigrationException If the stored ledger contains invalid records.
     * @throws ConfigurationException If table inspection is unsupported.
     */
    private function records(): array
    {
        if (!$this->database->table_exists($this->table)) {
            return [];
        }
        $rows = $this->statement('SELECT version, batch, checksum, created_at FROM ' . $this->quoted_table() . ' ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $records = [];
        foreach ($rows as $row) {
            if (!is_string($row['version']) || preg_match('/\A[0-9]{14}\z/', $row['version']) !== 1
                || !is_numeric($row['batch']) || (int) $row['batch'] < 1
                || !is_string($row['checksum']) || preg_match('/\A[a-f0-9]{64}\z/', $row['checksum']) !== 1
                || !is_string($row['created_at'])) {
                throw new MigrationException('Migration ledger contains an invalid record.');
            }
            $records[] = ['version' => $row['version'], 'batch' => (int) $row['batch'], 'checksum' => $row['checksum'], 'created_at' => $row['created_at']];
        }
        return $records;
    }

    /**
     * Derive pending versions by exact membership rather than a maximum version cutoff.
     * @param array<array-key, File> $files Discovered files in version order.
     * @param list<Record> $records Applied ledger rows.
     * @return Status Integrity details and all current migration counts.
     */
    private function compare(array $files, array $records): array
    {
        $applied = [];
        $missing = [];
        $changed = [];
        foreach ($records as $record) {
            $version = $record['version'];
            $applied[$version] = true;
            if (!isset($files[$version])) {
                $missing[] = $version;
            } elseif ($files[$version]['checksum'] !== $record['checksum']) {
                $changed[] = $version;
            }
        }
        $pending = [];
        foreach ($files as $version => $file) {
            if (!isset($applied[$version])) {
                $pending[] = (string) $version;
            }
        }
        return ['applied' => $records, 'pending' => $pending, 'missing' => $missing, 'changed' => $changed, 'total_migrations' => count($files)];
    }

    /**
     * Ensure the ledger exists only during an explicitly requested run or rollback.
     * @return void
     * @throws \PDOException If the table cannot be created or already has an incompatible schema.
     * @throws MigrationException If statement execution fails.
     */
    private function ensure_ledger(): void
    {
        $options = $this->driver() === 'mysql' ? ' ENGINE=InnoDB' : '';
        $this->statement('CREATE TABLE IF NOT EXISTS ' . $this->quoted_table() . ' ('
            . 'version VARCHAR(14) NOT NULL PRIMARY KEY, batch INTEGER NOT NULL, '
            . 'checksum VARCHAR(64) NOT NULL, created_at VARCHAR(19) NOT NULL)' . $options);
    }

    /**
     * Quote the validated physical ledger name independently of Medoo query/debug state.
     * @return string Driver-quoted identifier with the configured prefix applied once.
     * @throws ConfigurationException If the driver is unsupported.
     */
    private function quoted_table(): string
    {
        $quote = $this->driver() === 'mysql' ? '`' : '"';
        return $quote . $this->physical_table . $quote;
    }

    /**
     * Validate the actual PDO driver rather than trusting an option string.
     * @return 'mysql'|'sqlite' Supported migration backend.
     * @throws ConfigurationException If migration management is unsupported for this PDO driver.
     */
    private function driver(): string
    {
        $driver = $this->database->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            throw new ConfigurationException('Migration management supports SQLite and MySQL only.');
        }
        return $driver;
    }

    /**
     * Execute parameterized ledger/metadata SQL without Medoo debug side effects.
     * @param string $sql Library-owned SQL containing only validated quoted identifiers.
     * @param array<string, int|string> $parameters Values bound with their native PDO types.
     * @return PDOStatement Executed statement; callers fetch their own result shape.
     * @throws \PDOException If preparation or execution fails.
     * @throws MigrationException If a driver returns a failure without throwing.
     */
    private function statement(string $sql, array $parameters = []): PDOStatement
    {
        $pdo = $this->database->pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new MigrationException('Could not prepare a migration management statement.');
        }
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if (!$statement->execute()) {
            throw new MigrationException('Could not execute a migration management statement.');
        }
        return $statement;
    }
}
