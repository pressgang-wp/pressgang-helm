<?php

namespace PressGang\Helm\Exceptions;

use RuntimeException;

/**
 * Thrown when a tool fails during execution or receives invalid input.
 *
 * Includes the tool name and enough context for debugging
 * without leaking secrets or internal state.
 */
class ToolExecutionException extends RuntimeException
{
}
