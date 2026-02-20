<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a normalised provider response.
 *
 * Contains the extracted content string, the raw provider output for
 * debugging, and optional tool calls when the model requests tool use.
 * The tool execution loop in ChatBuilder checks hasToolCalls() to decide
 * whether to continue the agentic loop.
 */
class Response
{
    /**
     * @param string                    $content   The extracted response content.
     * @param array<string, mixed>      $raw       The raw, unmodified provider response.
     * @param array<int, ToolCall>|null $toolCalls Tool calls requested by the model, or null.
     */
    public function __construct(
        public readonly string $content,
        public readonly array $raw,
        public readonly ?array $toolCalls = null,
    ) {}

    /**
     * Whether the model requested tool calls in this response.
     *
     * @return bool
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== null && $this->toolCalls !== [];
    }

    /**
     * Convert the response to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'content' => $this->content,
            'raw' => $this->raw,
        ];

        if ($this->toolCalls !== null) {
            $data['tool_calls'] = array_map(
                fn (ToolCall $tc) => $tc->toArray(),
                $this->toolCalls,
            );
        }

        return $data;
    }
}
