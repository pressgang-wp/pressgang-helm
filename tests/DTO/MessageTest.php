<?php

namespace PressGang\Helm\Tests\DTO;

use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Tests\TestCase;

class MessageTest extends TestCase
{
    public function test_to_array_includes_tool_calls_when_present(): void
    {
        $message = new Message(
            role: 'assistant',
            content: '',
            toolCalls: [
                new ToolCall(id: 'call_1', name: 'search', arguments: ['q' => 'cats']),
            ],
        );

        $array = $message->toArray();

        $this->assertArrayHasKey('tool_calls', $array);
        $this->assertSame('call_1', $array['tool_calls'][0]['id']);
    }
}
