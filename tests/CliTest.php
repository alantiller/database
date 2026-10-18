<?php declare(strict_types=1);

namespace Alantiller\Database\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercise the executable against temporary SQLite files in separate PHP processes. */
final class CliTest extends TestCase
{
    private string $directory;
    private string $config;

    /**
     * Write a private migration fixture into a disposable application directory.
     * @return void
     * @throws \Throwable If temporary fixture creation fails.
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/database_cli_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/migrations', 0700);
        $migration = <<<'PHP'
<?php declare(strict_types=1);

use Alantiller\Database;
use Alantiller\Database\Migration;

return new class implements Migration {
    /**
     * Create the fixture contacts table through Medoo's schema API.
     * @param Database $database Runner-owned SQLite/MySQL connection.
     * @return void
     * @throws \PDOException If schema creation fails.
     */
    public function up(Database $database): void
    {
        $database->create('contacts', ['email' => ['VARCHAR(254)', 'NOT NULL', 'PRIMARY KEY']]);
    }

    /**
     * Remove the fixture contacts table and its data.
     * @param Database $database Runner-owned SQLite/MySQL connection.
     * @return void
     * @throws \PDOException If schema removal fails.
     */
    public function down(Database $database): void
    {
        $database->drop('contacts');
    }
};
PHP;
        file_put_contents($this->directory . '/migrations/Version20261018000000.php', $migration);
        $this->config = $this->directory . '/config.php';
        file_put_contents($this->config, '<?php return ' . var_export([
            'connection' => ['type' => 'sqlite', 'database' => $this->directory . '/test.sqlite'],
            'migrations' => ['path' => $this->directory . '/migrations'],
        ], true) . ';');
    }

    /** Remove private fixture files after subprocess connections have closed. @return void */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/migrations/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/migrations');
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    /**
     * Invoke the actual CLI without shell interpolation and collect both output streams.
     * @param list<string> $arguments CLI command and its arguments.
     * @return array{int, string, string} Exit status, stdout and stderr.
     * @throws RuntimeException If process creation or pipe reads fail.
     */
    private function invoke(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/database', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the CLI test subprocess.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not read CLI output.');
        }
        return [$exit_code, $stdout, $stderr];
    }

    /** The CLI supports the complete status/migrate/tables/rollback cycle. @return void */
    public function test_migration_lifecycle(): void
    {
        [$code, $out, $err] = $this->invoke(['status', $this->config]);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('20261018000000', $out);
        [$code, $out, $err] = $this->invoke(['migrate', $this->config]);
        self::assertSame(0, $code, $err);
        self::assertSame(['20261018000000'], json_decode($out, true, 512, JSON_THROW_ON_ERROR));
        [$code, $out, $err] = $this->invoke(['tables', $this->config]);
        self::assertSame(0, $code, $err);
        self::assertSame(['contacts', 'migrations'], json_decode($out, true, 512, JSON_THROW_ON_ERROR));
        [$code, $out, $err] = $this->invoke(['rollback', $this->config]);
        self::assertSame(0, $code, $err);
        self::assertSame(['20261018000000'], json_decode($out, true, 512, JSON_THROW_ON_ERROR));
        [$code, $out, $err] = $this->invoke(['make', $this->config, '20261018000001']);
        self::assertSame(0, $code, $err);
        self::assertFileExists($this->directory . '/migrations/Version20261018000001.php');
    }

    /** Usage errors and configuration faults have distinct nonzero exit statuses. @return void */
    public function test_help_and_failures(): void
    {
        self::assertSame(0, $this->invoke(['--help'])[0]);
        self::assertSame(2, $this->invoke(['unknown', $this->config])[0]);
        self::assertSame(2, $this->invoke(['migrate'])[0]);
        self::assertSame(1, $this->invoke(['status', $this->directory . '/missing.php'])[0]);
        $this->invoke(['make', $this->config, '20261018000001']);
        [$code, $out, $err] = $this->invoke(['migrate', $this->config]);
        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('20261018000001', $err);
    }
}
