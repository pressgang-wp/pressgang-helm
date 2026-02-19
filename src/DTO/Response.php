<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a normalised provider response.
 *
 * Contains both the extracted content string and the raw provider output
 * for debugging and advanced use cases.
 */
class Response
{
    /**
     * @param string              $content The extracted response content.
     * @param array<string, mixed> $raw     The raw, unmodified provider response.
     */
    public function __construct(
        public readonly string $content,
        public readonly array $raw,
    ) {}

    /**
     * Convert the response to an array representation.
     *
     * @return array{content: string, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'raw' => $this->raw,
        ];
    }
}
