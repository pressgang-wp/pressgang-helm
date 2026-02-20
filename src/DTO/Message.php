<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a single message in a chat sequence.
 *
 * Messages carry a role (system, user, assistant, tool), content string,
 * optional tool calls (on assistant messages), and optional tool call ID
 * (on tool result messages). Once constructed, a Message cannot be modified.
 */
class Message
{
    /**
     * @param string                    $role       The message role: system, user, assistant, or tool.
     * @param string                    $content    The message content.
     * @param array<int, ToolCall>|null $toolCalls  Tool calls from assistant (for tool loop re-send).
     * @param string|null               $toolCallId The tool call ID this message responds to (role: tool).
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?array $toolCalls = null,
        public readonly ?string $toolCallId = null,
    ) {}

    /**
     * Convert the message to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->toolCalls !== null) {
            $data['tool_calls'] = array_map(
                fn (ToolCall $tc) => $tc->toArray(),
                $this->toolCalls,
            );
        }

        if ($this->toolCallId !== null) {
            $data['tool_call_id'] = $this->toolCallId;
        }

        return $data;
    }
}
