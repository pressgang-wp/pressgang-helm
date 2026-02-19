<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a single message in a chat sequence.
 *
 * Messages carry a role (system, user, assistant, tool), content string,
 * and an optional tool call payload. Once constructed, a Message cannot be modified.
 */
class Message
{
    /**
     * @param string                    $role     The message role: system, user, assistant, or tool.
     * @param string                    $content  The message content.
     * @param array<string, mixed>|null $toolCall Optional tool call data from an assistant response.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?array $toolCall = null,
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

        if ($this->toolCall !== null) {
            $data['tool_call'] = $this->toolCall;
        }

        return $data;
    }
}
