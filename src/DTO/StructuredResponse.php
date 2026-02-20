<?php

namespace PressGang\Helm\DTO;

use ArrayAccess;

/**
 * Response containing validated structured output.
 *
 * Extends the base Response with decoded validated data and ArrayAccess.
 * For object-shaped data, callers can access fields directly: $response['score'].
 *
 * @implements ArrayAccess<mixed, mixed>
 */
class StructuredResponse extends Response implements ArrayAccess
{
    /**
     * @param mixed                     $structured The decoded and validated data.
     * @param string                    $content    The raw JSON content string.
     * @param array<string, mixed>      $raw        The raw provider response.
     * @param array<int, ToolCall>|null $toolCalls  Tool calls (typically null for structured output).
     */
    public function __construct(
        public readonly mixed $structured,
        string $content,
        array $raw,
        ?array $toolCalls = null,
    ) {
        parent::__construct($content, $raw, $toolCalls);
    }

    /**
     * Check whether a key exists in the structured data.
     *
     * @param mixed $offset The key to check.
     *
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        if (!is_array($this->structured)) {
            return false;
        }

        return array_key_exists($offset, $this->structured);
    }

    /**
     * Retrieve a value from the structured data by key.
     *
     * @param mixed $offset The key to retrieve.
     *
     * @return mixed The value, or null if the key does not exist.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (!is_array($this->structured)) {
            return null;
        }

        return $this->structured[$offset] ?? null;
    }

    /**
     * Prevent mutation — StructuredResponse is immutable.
     *
     * @param mixed $offset The key.
     * @param mixed $value  The value.
     *
     * @throws \LogicException Always.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('StructuredResponse is immutable.');
    }

    /**
     * Prevent mutation — StructuredResponse is immutable.
     *
     * @param mixed $offset The key.
     *
     * @throws \LogicException Always.
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('StructuredResponse is immutable.');
    }
}
