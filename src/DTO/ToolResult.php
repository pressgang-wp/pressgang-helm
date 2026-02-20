<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing the result of executing a tool.
 *
 * Produced by the tool execution loop after invoking a ToolContract.
 * Contains the original tool call ID (for provider correlation),
 * the tool name, and the JSON-safe result array.
 */
class ToolResult
{
    /**
     * @param string               $toolCallId The ID of the tool call this result answers.
     * @param string               $name       The tool name that was executed.
     * @param array<string, mixed> $result     JSON-serialisable result from the tool.
     */
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly array $result,
    ) {}

    /**
     * Convert to array representation.
     *
     * @return array{tool_call_id: string, name: string, result: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'name' => $this->name,
            'result' => $this->result,
        ];
    }
}
