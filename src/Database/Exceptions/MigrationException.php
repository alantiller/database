<?php declare(strict_types=1);

namespace Alantiller\Database\Exceptions;

/** Migration integrity, execution, locking or ledger failure; inspect the previous cause privately. */
class MigrationException extends BaseException
{
    protected const ERROR_CODE = 'migration_error';
}
