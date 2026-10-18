<?php declare(strict_types=1);

namespace Alantiller\Database\Exceptions;

/** Invalid connection/migration configuration or unsupported operation context. */
class ConfigurationException extends BaseException
{
    protected const ERROR_CODE = 'configuration_error';
}
