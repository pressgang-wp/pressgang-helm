<?php

namespace PressGang\Helm\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when required configuration is missing or invalid.
 *
 * Covers missing provider, API key, model, or malformed config values.
 */
class ConfigurationException extends InvalidArgumentException
{
}
