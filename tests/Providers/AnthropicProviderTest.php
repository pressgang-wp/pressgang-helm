<?php

namespace PressGang\Helm\Tests\Providers;

use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Providers\AnthropicProvider;
use PressGang\Helm\Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    protected function makeProvider(TransportContract $transport, array $config = []): AnthropicProvider
    {
        return new AnthropicProvider($transport, array_merge([
            'api_key' => 'sk-ant-test-123',
        ], $config));
    }

    protected function makeRequest(array $overrides = []): ChatRequest
    {
        return new ChatRequest(
            messages: $overrides['messages'] ?? [
                new Message(role: 'system', content: 'Be concise.'),
                new Message(role: 'user', content: 'Hello'),
            ],
            model: $overrides['model'] ?? 'claude-sonnet-4-20250514',
            temperature: $overrides['temperature'] ?? null,
            tools: $overrides['tools'] ?? [],
            schema: $overrides['schema'] ?? null,
        );
    }

    protected function fakeTransport(array $responseData): TransportContract
    {
        $transport = $this->createMock(TransportContract::class);
        $transport->method('send')->willReturn($responseData);

        return $transport;
    }

    protected function anthropicResponse(string $content = 'Hello!'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $content],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ];
    }

    protected function anthropicToolResponse(): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_123',
                    'name' => 'get_weather',
                    'input' => ['city' => 'London'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 15, 'output_tokens' => 25],
        ];
    }

    public function test_chat_returns_response_with_content(): void
    {
        $transport = $this->fakeTransport($this->anthropicResponse('The sky is blue.'));
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('The sky is blue.', $response->content);
    }

    public function test_chat_preserves_raw_response(): void
    {
        $raw = $this->anthropicResponse('Test');
        $transport = $this->fakeTransport($raw);
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertSame($raw, $response->raw);
    }

    public function test_chat_extracts_system_as_top_level_parameter(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    return $body['system'] === 'Be concise.'
                        && !in_array('system', array_column($body['messages'], 'role'));
                }),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_omits_system_when_no_system_message(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('system', $body)),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [new Message(role: 'user', content: 'Hello')],
        ]));
    }

    public function test_chat_maps_user_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    return count($body['messages']) === 1
                        && $body['messages'][0]['role'] === 'user'
                        && $body['messages'][0]['content'] === 'Hello';
                }),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_sends_model_in_body(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => $body['model'] === 'claude-haiku-3-5-20241022'),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['model' => 'claude-haiku-3-5-20241022']));
    }

    public function test_chat_sends_max_tokens(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => $body['max_tokens'] === 4096),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_uses_custom_max_tokens(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => $body['max_tokens'] === 8192),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport, [
            'anthropic' => ['max_tokens' => 8192],
        ]);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_sends_temperature_when_set(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => $body['temperature'] === 0.5),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['temperature' => 0.5]));
    }

    public function test_chat_sends_correct_headers(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $headers) {
                    return $headers['x-api-key'] === 'sk-ant-test-123'
                        && $headers['anthropic-version'] === '2023-06-01';
                }),
                $this->anything(),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_sends_to_correct_endpoint(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://api.anthropic.com/v1/messages',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_uses_custom_base_url(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://proxy.example.com/v1/messages',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport, [
            'anthropic' => ['base_url' => 'https://proxy.example.com/v1'],
        ]);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_sends_tools_in_anthropic_format(): void
    {
        $tools = [
            [
                'name' => 'get_weather',
                'description' => 'Get the weather',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ];

        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    $tool = $body['tools'][0] ?? [];

                    return $tool['name'] === 'get_weather'
                        && $tool['description'] === 'Get the weather'
                        && isset($tool['input_schema'])
                        && !isset($tool['parameters']);
                }),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['tools' => $tools]));
    }

    public function test_chat_parses_tool_use_response(): void
    {
        $transport = $this->fakeTransport($this->anthropicToolResponse());
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);

        $toolCall = $response->toolCalls[0];
        $this->assertInstanceOf(ToolCall::class, $toolCall);
        $this->assertSame('toolu_123', $toolCall->id);
        $this->assertSame('get_weather', $toolCall->name);
        $this->assertSame(['city' => 'London'], $toolCall->arguments);
    }

    public function test_chat_extracts_text_alongside_tool_use(): void
    {
        $transport = $this->fakeTransport($this->anthropicToolResponse());
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertSame('Let me check.', $response->content);
    }

    public function test_chat_returns_no_tool_calls_for_text_only_response(): void
    {
        $transport = $this->fakeTransport($this->anthropicResponse('Just text.'));
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertFalse($response->hasToolCalls());
        $this->assertNull($response->toolCalls);
    }

    public function test_chat_maps_assistant_tool_call_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    $assistantMsg = $body['messages'][1] ?? [];

                    return $assistantMsg['role'] === 'assistant'
                        && is_array($assistantMsg['content'])
                        && $assistantMsg['content'][0]['type'] === 'tool_use'
                        && $assistantMsg['content'][0]['id'] === 'toolu_1'
                        && $assistantMsg['content'][0]['name'] === 'search';
                }),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Search for cats'),
                new Message(role: 'assistant', content: '', toolCalls: [
                    new ToolCall(id: 'toolu_1', name: 'search', arguments: ['q' => 'cats']),
                ]),
                new Message(role: 'tool', content: '{"results": []}', toolCallId: 'toolu_1'),
            ],
        ]));
    }

    public function test_chat_maps_tool_result_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    $toolMsg = $body['messages'][2] ?? [];

                    return $toolMsg['role'] === 'user'
                        && is_array($toolMsg['content'])
                        && $toolMsg['content'][0]['type'] === 'tool_result'
                        && $toolMsg['content'][0]['tool_use_id'] === 'toolu_1';
                }),
            )
            ->willReturn($this->anthropicResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Search for cats'),
                new Message(role: 'assistant', content: '', toolCalls: [
                    new ToolCall(id: 'toolu_1', name: 'search', arguments: ['q' => 'cats']),
                ]),
                new Message(role: 'tool', content: '{"results": []}', toolCallId: 'toolu_1'),
            ],
        ]));
    }

    public function test_chat_throws_provider_exception_on_transport_error(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $transport->method('send')->willThrowException(new ProviderException('Connection refused'));

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Connection refused');

        $provider->chat($this->makeRequest());
    }

    public function test_chat_redacts_api_key_from_exception_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $transport->method('send')->willThrowException(
            new ProviderException('Error with key sk-ant-test-123 in request'),
        );

        $provider = $this->makeProvider($transport);

        try {
            $provider->chat($this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('sk-ant-test-123', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_chat_throws_on_malformed_tool_use_payload(): void
    {
        $transport = $this->fakeTransport([
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'search',
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Malformed tool call in Anthropic response');

        $provider->chat($this->makeRequest());
    }

    public function test_chat_throws_on_malformed_tool_use_arguments_payload(): void
    {
        $transport = $this->fakeTransport([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'search',
                    'input' => 'not-an-array',
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Malformed tool call arguments in Anthropic response');

        $provider->chat($this->makeRequest());
    }

    public function test_constructor_throws_when_api_key_is_missing(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('requires a non-empty api_key');

        new AnthropicProvider($transport, []);
    }
}
