<?php declare(strict_types=1);

namespace Alantiller\Database\Tests;

use Alantiller\Database;
use Alantiller\Database\Exceptions\ConfigurationException;
use Alantiller\Database\Exceptions\MigrationException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real PDO/Medoo integration coverage with isolated files and table prefixes. */
final class DatabaseTest extends TestCase
{
    private string $directory;
    private string $prefix;
    private Database $database;
    private bool $mysql;

    /**
     * Create a private fixture directory and an isolated connection/table prefix.
     * @return void
     * @throws \Throwable If fixtures or the explicitly configured test database fail.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/database_test_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->prefix = 'dtest_' . bin2hex(random_bytes(6)) . '_';
        $dsn = getenv('DATABASE_TEST_MYSQL_DSN');
        $this->mysql = is_string($dsn) && $dsn !== '';
        $pdo = $this->mysql
            ? new PDO((string) $dsn, getenv('DATABASE_TEST_MYSQL_USER') ?: '', getenv('DATABASE_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite::memory:');
        if (!$this->mysql) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        $this->database = new Database(['pdo' => $pdo, 'type' => $this->mysql ? 'mysql' : 'sqlite', 'prefix' => $this->prefix], ['path' => $this->directory]);
    }

    /**
     * Remove only randomly prefixed fixture tables and private temporary files.
     * @return void
     * @throws \Throwable If fixture cleanup fails.
     */
    protected function tearDown(): void
    {
        if (isset($this->database)) {
            if ($this->database->pdo->inTransaction()) {
                $this->database->pdo->rollBack();
            }
            foreach ($this->database->get_tables() as $table) {
                if (str_starts_with($table, $this->prefix)) {
                    $this->database->drop(substr($table, strlen($this->prefix)));
                }
            }
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * Write a trusted anonymous fixture migration with caller-selected callback bodies.
     * @param string $version Unique 14-digit fixture version.
     * @param string $up PHP statements for the up callback.
     * @param string $down PHP statements for the down callback.
     * @return string Full fixture path.
     */
    private function write_migration(string $version, string $up = '', string $down = ''): string
    {
        $path = $this->directory . '/Version' . $version . '.php';
        $template = <<<'PHP'
<?php declare(strict_types=1);
return new class implements \Alantiller\Database\Migration {
    /** Apply fixture statements. @param \Alantiller\Database $database Test connection. @return void */
    public function up(\Alantiller\Database $database): void { UP_BODY }
    /** Undo fixture statements. @param \Alantiller\Database $database Test connection. @return void */
    public function down(\Alantiller\Database $database): void { DOWN_BODY }
};
PHP;
        file_put_contents($path, str_replace(['UP_BODY', 'DOWN_BODY'], [$up, $down], $template));
        return $path;
    }

    /** Construction and status inspection must not create the ledger. @return void */
    public function test_constructor_and_status_do_not_create_tables(): void
    {
        self::assertFalse($this->database->table_exists('migrations'));
        self::assertSame(['applied' => [], 'pending' => [], 'missing' => [], 'changed' => [], 'total_migrations' => 0], $this->database->migration_status());
        self::assertFalse($this->database->table_exists('migrations'));
    }

    /** The full inherited CRUD API and prefixed schema management remain usable. @return void */
    public function test_medoo_crud_and_table_management(): void
    {
        $this->database->create('items', ['name' => ['VARCHAR(40)', 'PRIMARY KEY']]);
        self::assertTrue($this->database->table_exists('items'));
        self::assertContains($this->prefix . 'items', $this->database->get_tables());
        $this->database->insert('items', ['name' => 'first']);
        self::assertTrue($this->database->has('items', ['name' => 'first']));
        $this->database->update('items', ['name' => 'updated'], ['name' => 'first']);
        self::assertSame('updated', $this->database->get('items', 'name', ['name' => 'updated']));
        $this->database->delete('items', ['name' => 'updated']);
        self::assertSame(0, $this->database->count('items'));
        $this->database->drop('items');
        self::assertFalse($this->database->table_exists('items'));
    }

    /** PDO can be shared directly with auth/permission without opening another connection. @return void */
    public function test_existing_pdo_connection_is_reused(): void
    {
        $client = new Database($this->database->pdo);
        self::assertSame($this->database->pdo, $client->pdo);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $client->pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** Fresh SQLite options connect without an application framework. @return void */
    public function test_array_connection_options(): void
    {
        $client = new Database(['type' => 'sqlite', 'database' => ':memory:']);
        self::assertSame([], $client->get_tables());
    }

    /** Unsorted files apply deterministically, silently and only once. @return void */
    public function test_run_orders_versions_and_is_idempotent(): void
    {
        $this->write_migration('20261018000002', '$database->insert("items", ["name" => "second"]);', '$database->delete("items", ["name" => "second"]);');
        $this->write_migration('20261018000001', '$database->create("items", ["name" => ["VARCHAR(40)", "PRIMARY KEY"]]);', '$database->drop("items");');
        $this->expectOutputString('');
        self::assertSame(['20261018000001', '20261018000002'], $this->database->migration_run());
        self::assertSame([], $this->database->migration_run());
        $status = $this->database->migration_status();
        self::assertCount(2, $status['applied']);
        self::assertSame([1, 1], array_column($status['applied'], 'batch'));
        self::assertSame([], $status['pending']);
        self::assertSame('second', $this->database->get('items', 'name'));
    }

    /** An older version introduced later must still be applied by exact membership. @return void */
    public function test_backfilled_older_versions_are_pending(): void
    {
        $this->write_migration('20261018000002');
        $this->database->migration_run();
        $this->write_migration('20261018000001');
        self::assertSame(['20261018000001'], $this->database->migration_status()['pending']);
        self::assertSame(['20261018000001'], $this->database->migration_run());
        self::assertSame([2, 1], array_column($this->database->migration_status()['applied'], 'batch'));
        self::assertSame(['20261018000001'], $this->database->migration_rollback());
        self::assertSame(['20261018000002'], array_column($this->database->migration_status()['applied'], 'version'));
    }

    /** Rollback reverses dependent migrations and permits reapplication. @return void */
    public function test_rollback_reverses_latest_batch_only(): void
    {
        $this->write_migration('20261018000001');
        $this->database->migration_run();
        $this->write_migration('20261018000002', '$database->create("items", ["name" => "VARCHAR(40)"]);', '$database->drop("items");');
        $this->write_migration('20261018000003', '$database->insert("items", ["name" => "test"]);', '$database->delete("items", ["name" => "test"]);');
        $this->database->migration_run();
        self::assertSame(['20261018000003', '20261018000002'], $this->database->migration_rollback());
        self::assertFalse($this->database->table_exists('items'));
        self::assertSame(['20261018000002', '20261018000003'], $this->database->migration_status()['pending']);
        self::assertSame(['20261018000002', '20261018000003'], $this->database->migration_run());
    }

    /** Empty run/rollback calls return useful empty results. @return void */
    public function test_empty_directory_and_ledger(): void
    {
        self::assertSame([], $this->database->migration_rollback());
        self::assertSame([], $this->database->migration_run());
        self::assertSame([], $this->database->migration_status()['applied']);
    }

    /** SQLite failures undo the entire batch; MySQL retains prior successful entries. @return void */
    public function test_failed_run_stops_and_preserves_the_cause(): void
    {
        $this->write_migration('20261018000001', '$database->create("items", ["name" => "VARCHAR(40)"]);', '$database->drop("items");');
        $this->write_migration('20261018000002', 'throw new \RuntimeException("private details");');
        $this->write_migration('20261018000003');
        try {
            $this->database->migration_run();
            self::fail('Expected migration failure.');
        } catch (MigrationException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertStringNotContainsString('private details', $exception->getMessage());
            self::assertSame('migration_error', $exception->error_code);
        }
        self::assertSame($this->mysql, $this->database->table_exists('items'));
        self::assertCount($this->mysql ? 1 : 0, $this->database->migration_status()['applied']);
        self::assertFalse($this->database->pdo->inTransaction());
    }

    /** A failed down callback retains its own ledger entry and stops lower versions. @return void */
    public function test_failed_rollback_stops_at_the_failed_version(): void
    {
        $this->write_migration('20261018000001', '', 'throw new \RuntimeException("cannot reverse");');
        $this->write_migration('20261018000002');
        $this->database->migration_run();
        try {
            $this->database->migration_rollback();
            self::fail('Expected rollback failure.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('20261018000001', $exception->getMessage());
        }
        self::assertCount($this->mysql ? 1 : 2, $this->database->migration_status()['applied']);
    }

    /** Data-only migration changes roll back on both supported engines. @return void */
    public function test_failed_data_migration_does_not_leave_rows(): void
    {
        $this->database->create('items', ['name' => ['VARCHAR(40)', 'PRIMARY KEY']], $this->mysql ? 'ENGINE=InnoDB' : null);
        $this->write_migration('20261018000001', '$database->insert("items", ["name" => "test"]); throw new \RuntimeException("fail");');
        try {
            $this->database->migration_run();
            self::fail('Expected migration failure.');
        } catch (MigrationException) {
            self::assertSame(0, $this->database->count('items'));
        }
    }

    /** All files are preflighted before callbacks, avoiding an avoidable partial batch. @return void */
    public function test_invalid_return_is_rejected_before_first_callback(): void
    {
        $this->write_migration('20261018000001', '$database->create("items", ["name" => "VARCHAR(40)"]);');
        file_put_contents($this->directory . '/Version20261018000002.php', '<?php return null;');
        try {
            $this->database->migration_run();
            self::fail('Expected migration validation failure.');
        } catch (MigrationException) {
            self::assertFalse($this->database->table_exists('items'));
        }
    }

    /** Status lists files without requiring or executing their PHP. @return void */
    public function test_status_does_not_execute_migration_files(): void
    {
        file_put_contents($this->directory . '/Version20261018000001.php', '<?php throw new \RuntimeException("must not run");');
        self::assertSame(['20261018000001'], $this->database->migration_status()['pending']);
    }

    /** Changed applied migrations are reported and block further migrations. @return void */
    public function test_changed_applied_file_is_rejected(): void
    {
        $path = $this->write_migration('20261018000001');
        $this->database->migration_run();
        file_put_contents($path, "\n// changed\n", FILE_APPEND);
        self::assertSame(['20261018000001'], $this->database->migration_status()['changed']);
        $this->expectException(MigrationException::class);
        $this->database->migration_run();
    }

    /** Missing applied files are reported and block rollback before any callback. @return void */
    public function test_missing_applied_file_is_rejected(): void
    {
        $path = $this->write_migration('20261018000001');
        $this->database->migration_run();
        unlink($path);
        self::assertSame(['20261018000001'], $this->database->migration_status()['missing']);
        $this->expectException(MigrationException::class);
        $this->database->migration_rollback();
    }

    /** Bad migration filenames fail explicitly rather than being silently skipped. @return void */
    public function test_malformed_php_filename_is_rejected(): void
    {
        file_put_contents($this->directory . '/Version_bad.php', '<?php return null;');
        $this->expectException(MigrationException::class);
        $this->database->migration_status();
    }

    /** Migration commands never commit or roll back a caller-owned transaction. @return void */
    public function test_outer_transaction_is_rejected_without_touching_it(): void
    {
        $this->database->pdo->beginTransaction();
        try {
            $this->database->migration_run();
            self::fail('Expected active transaction rejection.');
        } catch (ConfigurationException) {
            self::assertTrue($this->database->pdo->inTransaction());
            self::assertFalse($this->database->table_exists('migrations'));
        }
    }

    /** An unconfigured migration path leaves ordinary Medoo usage available. @return void */
    public function test_migrations_require_an_explicit_directory(): void
    {
        $database = new Database($this->database->pdo);
        $this->expectException(ConfigurationException::class);
        $database->migration_status();
    }

    /** New migration skeletons are valid and exclusive creation prevents overwrite. @return void */
    public function test_generator_refuses_to_overwrite(): void
    {
        $path = $this->database->migration_create('20261018000001');
        self::assertFileExists($path);
        self::assertStringContainsString('implements Migration', (string) file_get_contents($path));
        self::assertSame(['20261018000001'], $this->database->migration_status()['pending']);
        $this->expectException(MigrationException::class);
        $this->database->migration_create('20261018000001');
    }

    /** Skeletons fail explicitly until implemented instead of recording an empty change. @return void */
    public function test_generated_skeleton_cannot_be_applied_unimplemented(): void
    {
        $this->database->migration_create('20261018000001');
        $this->expectException(MigrationException::class);
        $this->database->migration_run();
    }

    /** Version input cannot escape the configured directory. @return void */
    public function test_generator_rejects_invalid_versions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->database->migration_create('../unsafe');
    }

    /** Migration table input cannot become executable SQL. @return void */
    public function test_invalid_table_configuration_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Database($this->database->pdo, ['table' => 'migrations; DROP TABLE users']);
    }

    /** Unknown configuration keys catch typos before connecting. @return void */
    public function test_unknown_configuration_key_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Database($this->database->pdo, ['paths' => $this->directory]);
    }

    /** Invalid timeout types cannot reach advisory-lock SQL. @return void */
    public function test_invalid_lock_timeout_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Database($this->database->pdo, ['lock_timeout' => -1]);
    }

    /** Unsafe prefixes cannot reach ledger quoting or metadata lookup. @return void */
    public function test_invalid_prefix_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Database(['pdo' => $this->database->pdo, 'type' => 'sqlite', 'prefix' => 'bad"prefix']);
    }

    /** Medoo test mode must not silently record unexecuted migrations. @return void */
    public function test_medoo_test_mode_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new Database(['testMode' => true]);
    }

    /** Compatibility aliases retain boolean success/failure without Flight output. @return void */
    public function test_original_method_aliases(): void
    {
        $this->write_migration('20261018000001');
        self::assertTrue($this->database->migrationRun());
        self::assertSame($this->database->migration_status(), $this->database->migrationStatus());
        self::assertTrue($this->database->migrationRollback());
        $this->write_migration('20261018000002', 'throw new \RuntimeException("fail");');
        self::assertFalse($this->database->migrationRun());
    }

    /** Existing Medoo debug mode is rejected before creating or changing the ledger. @return void */
    public function test_debug_mode_cannot_run_migrations(): void
    {
        $this->database->beginDebug();
        $this->expectException(ConfigurationException::class);
        $this->database->migration_run();
    }

    /** A callback cannot quietly skip its SQL by switching Medoo into debug mode. @return void */
    public function test_callback_cannot_record_debug_only_changes(): void
    {
        $this->write_migration('20261018000001', '$database->debug()->create("items", ["name" => "VARCHAR(40)"]);');
        try {
            $this->database->migration_run();
            self::fail('Expected debug-mode rejection.');
        } catch (MigrationException) {
            self::assertFalse($this->database->table_exists('items'));
            self::assertSame([], $this->database->migration_status()['applied']);
        } finally {
            $this->database->debugLog();
        }
    }

    /** MySQL advisory locks prevent overlapping runners; SQLite uses its write transaction. @return void */
    public function test_concurrent_runner_is_rejected(): void
    {
        if (!$this->mysql) {
            $path = $this->directory . '/locked.sqlite';
            $first = new PDO('sqlite:' . $path);
            $second = new PDO('sqlite:' . $path);
            $second->exec('PRAGMA busy_timeout = 1');
            $first->exec('CREATE TABLE held (id INT)');
            $first->beginTransaction();
            $first->exec('INSERT INTO held VALUES (1)');
            $runner = new Database($second, ['path' => $this->directory]);
            try {
                $runner->migration_run();
                self::fail('Expected SQLite write lock failure.');
            } catch (MigrationException) {
                self::assertFalse($second->inTransaction());
            } finally {
                $first->rollBack();
            }
            return;
        }
        $holder = new PDO((string) getenv('DATABASE_TEST_MYSQL_DSN'), getenv('DATABASE_TEST_MYSQL_USER') ?: '', getenv('DATABASE_TEST_MYSQL_PASSWORD') ?: '');
        $statement = $holder->query('SELECT DATABASE()');
        self::assertNotFalse($statement);
        $name = hash('sha256', (string) $statement->fetchColumn() . ':' . $this->prefix . 'migrations');
        $statement = $holder->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$name]);
        self::assertSame(1, (int) $statement->fetchColumn());
        $runner = new Database(['pdo' => $this->database->pdo, 'type' => 'mysql', 'prefix' => $this->prefix], ['path' => $this->directory, 'lock_timeout' => 0]);
        try {
            $runner->migration_run();
            self::fail('Expected advisory lock failure.');
        } catch (MigrationException) {
            self::assertFalse($runner->table_exists('migrations'));
        } finally {
            $statement = $holder->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
        }
    }
}
