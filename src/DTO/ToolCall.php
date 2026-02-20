<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a tool call requested by the model.
 *
 * Returned inside a Response when the provider indicates the model wants
 * to invoke a tool. Contains the provider-assigned ID, tool name, and
 * parsed arguments. Used by the tool execution loop in ChatBuilder.
 */
class ToolCall
{
    /**
     * @param string               $id        Provider-assigned tool call ID.
     * @param string               $name      The tool name to invoke.
     * @param array<string, mixed> $arguments Parsed arguments for the tool.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}

    /**
     * Convert to array representation.
     *
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
