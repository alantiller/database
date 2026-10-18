<?php declare(strict_types=1);

namespace Alantiller\Database\Exceptions;

use RuntimeException;
use Throwable;

/** Base wrapper fault; inherited Medoo/PDO operations keep their own exceptions. */
class BaseException extends RuntimeException
{
    protected const ERROR_CODE = 'database_error';
    public readonly string $error_code;

    /**
     * Describe a wrapper fault without copying SQL or credentials into the message.
     * @param string $message Diagnostic intended for trusted application code.
     * @param int $code Native numeric exception code.
     * @param Throwable|null $previous Original cause, which can contain sensitive SQL or values.
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->error_code = static::ERROR_CODE;
    }
}
