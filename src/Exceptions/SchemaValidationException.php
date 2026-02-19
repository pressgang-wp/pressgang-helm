<?php

namespace PressGang\Helm\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when structured output fails JSON Schema validation.
 *
 * Carries validation errors, the raw model output, and request context
 * so callers can inspect what went wrong without re-running the request.
 */
class SchemaValidationException extends RuntimeException
{
    /**
     * @param string               $message          The exception message.
     * @param array<int, string>   $validationErrors List of validation error descriptions.
     * @param string               $rawOutput        The raw model output that failed validation.
     * @param array<string, mixed> $requestContext   The request context for debugging.
     * @param int                  $code             The exception code.
     * @param Throwable|null       $previous         The previous exception for chaining.
     */
    public function __construct(
        string $message,
        public readonly array $validationErrors = [],
        public readonly string $rawOutput = '',
        public readonly array $requestContext = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
