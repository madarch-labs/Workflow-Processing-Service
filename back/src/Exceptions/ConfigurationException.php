<?php

declare(strict_types=1);

namespace Wps\Exceptions;

/**
 * Invalid process definition detected at runtime (unknown operator / action type / change type, bad path...).
 */
final class ConfigurationException extends NonRecoverableException
{
}
