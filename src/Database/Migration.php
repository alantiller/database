<?php declare(strict_types=1);

namespace Alantiller\Database;

use Alantiller\Database;

/** Application-owned schema/data change returned by a VersionYYYYMMDDHHMMSS.php file. */
interface Migration
{
    /**
     * Apply this migration using the runner's connection.
     *
     * Do not start, commit or roll back transactions, change connections, or run
     * migration commands here. MySQL DDL may commit implicitly; see README.
     * @param Database $database Active connection with the application's Medoo prefix.
     * @return void
     * @throws \Throwable If the change cannot be completed; the runner stops immediately.
     */
    public function up(Database $database): void;

    /**
     * Undo this migration using the runner's connection.
     *
     * Restore the previous schema where practical. The application owns backups
     * for data that cannot be reconstructed. Transaction rules match up().
     * @param Database $database Active connection with the application's Medoo prefix.
     * @return void
     * @throws \Throwable If reversal fails; the ledger entry is retained where possible.
     */
    public function down(Database $database): void;
}
