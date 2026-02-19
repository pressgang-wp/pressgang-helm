<?php

namespace PressGang\Helm\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider or transport operation fails.
 *
 * Includes enough context for debugging without leaking secrets.
 */
class ProviderException extends RuntimeException
{
}
