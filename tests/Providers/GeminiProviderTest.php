<?php

namespace PressGang\Helm\Tests\Providers;

use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Providers\GeminiProvider;
use PressGang\Helm\Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    protected function makeProvider(TransportContract $transport, array $config = []): GeminiProvider
    {
        return new GeminiProvider($transport, array_merge([
            'api_key' => 'gemini-test-key-123',
        ], $config));
    }

    protected function makeRequest(array $overrides = []): ChatRequest
    {
        return new ChatRequest(
            messages: $overrides['messages'] ?? [
                new Message(role: 'system', content: 'Be concise.'),
                new Message(role: 'user', content: 'Hello'),
            ],
            model: $overrides['model'] ?? 'gemini-2.5-flash',
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

    protected function geminiResponse(string $content = 'Hello!'): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => $content],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
        ];
    }

    protected function geminiFunctionCallResponse(): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['text' => 'Let me check.'],
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 15,
                'candidatesTokenCount' => 25,
                'totalTokenCount' => 40,
            ],
        ];
    }

    public function test_chat_returns_response_with_content(): void
    {
        $transport = $this->fakeTransport($this->geminiResponse('The sky is blue.'));
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('The sky is blue.', $response->content);
    }

    public function test_chat_preserves_raw_response(): void
    {
        $raw = $this->geminiResponse('Test');
        $transport = $this->fakeTransport($raw);
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertSame($raw, $response->raw);
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
                    return count($body['contents']) === 1
                        && $body['contents'][0]['role'] === 'user'
                        && $body['contents'][0]['parts'][0]['text'] === 'Hello';
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_extracts_system_as_system_instruction(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    return $body['systemInstruction']['parts'][0]['text'] === 'Be concise.'
                        && !in_array('system', array_column($body['contents'], 'role'));
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_omits_system_instruction_when_no_system_message(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('systemInstruction', $body)),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [new Message(role: 'user', content: 'Hello')],
        ]));
    }

    public function test_chat_maps_assistant_role_as_model(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    return $body['contents'][0]['role'] === 'user'
                        && $body['contents'][1]['role'] === 'model'
                        && $body['contents'][1]['parts'][0]['text'] === 'Hi there';
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Hello'),
                new Message(role: 'assistant', content: 'Hi there'),
            ],
        ]));
    }

    public function test_chat_sends_model_in_url_not_body(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                $this->stringContains('models/gemini-2.5-flash:generateContent'),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('model', $body)),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_sends_temperature_in_generation_config(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => $body['generationConfig']['temperature'] === 0.7),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['temperature' => 0.7]));
    }

    public function test_chat_omits_generation_config_when_defaults(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('generationConfig', $body)),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['temperature' => null, 'schema' => null]));
    }

    public function test_chat_sends_tools_in_gemini_format(): void
    {
        $normalizedTools = [
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
                    $declarations = $body['tools'][0]['functionDeclarations'] ?? [];

                    return count($declarations) === 1
                        && $declarations[0]['name'] === 'get_weather'
                        && $declarations[0]['description'] === 'Get the weather'
                        && isset($declarations[0]['parameters']);
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['tools' => $normalizedTools]));
    }

    public function test_chat_omits_tools_when_empty(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('tools', $body)),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['tools' => []]));
    }

    public function test_chat_normalizes_empty_tool_properties_as_object(): void
    {
        $normalizedTools = [
            [
                'name' => 'ping',
                'description' => 'No-op tool',
                'parameters' => ['type' => 'object', 'properties' => []],
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
                    $parameters = $body['tools'][0]['functionDeclarations'][0]['parameters'] ?? null;

                    return is_array($parameters)
                        && isset($parameters['properties'])
                        && $parameters['properties'] instanceof \stdClass;
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['tools' => $normalizedTools]));
    }

    public function test_chat_maps_schema_to_generation_config(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) use ($schema) {
                    $gc = $body['generationConfig'] ?? [];

                    return $gc['responseMimeType'] === 'application/json'
                        && $gc['responseSchema'] === $schema;
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['schema' => $schema]));
    }

    public function test_chat_omits_schema_when_null(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    if (!array_key_exists('generationConfig', $body)) {
                        return true;
                    }

                    $gc = $body['generationConfig'];

                    return !array_key_exists('responseMimeType', $gc)
                        && !array_key_exists('responseSchema', $gc);
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['schema' => null]));
    }

    public function test_chat_sends_api_key_header(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $headers) => $headers['x-goog-api-key'] === 'gemini-test-key-123'),
                $this->anything(),
            )
            ->willReturn($this->geminiResponse());

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
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->geminiResponse());

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
                'https://proxy.example.com/v1beta/models/gemini-2.5-flash:generateContent',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport, [
            'gemini' => ['base_url' => 'https://proxy.example.com/v1beta'],
        ]);
        $provider->chat($this->makeRequest());
    }

    public function test_chat_strips_trailing_slash_from_base_url(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://proxy.example.com/v1beta/models/gemini-2.5-flash:generateContent',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport, [
            'gemini' => ['base_url' => 'https://proxy.example.com/v1beta/'],
        ]);
        $provider->chat($this->makeRequest());
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
            new ProviderException('Error with key gemini-test-key-123 in request'),
        );

        $provider = $this->makeProvider($transport);

        try {
            $provider->chat($this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('gemini-test-key-123', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_chat_returns_empty_content_when_missing(): void
    {
        $transport = $this->fakeTransport([
            'candidates' => [['finishReason' => 'STOP']],
        ]);

        $provider = $this->makeProvider($transport);
        $response = $provider->chat($this->makeRequest());

        $this->assertSame('', $response->content);
    }

    public function test_chat_parses_function_call_response(): void
    {
        $transport = $this->fakeTransport([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);
        $response = $provider->chat($this->makeRequest());

        $this->assertTrue($response->hasToolCalls());
        $this->assertCount(1, $response->toolCalls);

        $toolCall = $response->toolCalls[0];
        $this->assertInstanceOf(ToolCall::class, $toolCall);
        $this->assertSame('get_weather', $toolCall->name);
        $this->assertSame(['city' => 'London'], $toolCall->arguments);
    }

    public function test_chat_generates_synthetic_tool_call_ids(): void
    {
        $transport = $this->fakeTransport([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['city' => 'London'],
                                ],
                            ],
                            [
                                'functionCall' => [
                                    'name' => 'get_time',
                                    'args' => ['zone' => 'UTC'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);
        $response = $provider->chat($this->makeRequest());

        $this->assertSame('gemini_get_weather_0', $response->toolCalls[0]->id);
        $this->assertSame('gemini_get_time_1', $response->toolCalls[1]->id);
    }

    public function test_chat_extracts_text_alongside_function_call(): void
    {
        $transport = $this->fakeTransport($this->geminiFunctionCallResponse());
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertSame('Let me check.', $response->content);
        $this->assertTrue($response->hasToolCalls());
    }

    public function test_chat_returns_no_tool_calls_for_text_only_response(): void
    {
        $transport = $this->fakeTransport($this->geminiResponse('Just text.'));
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertFalse($response->hasToolCalls());
        $this->assertNull($response->toolCalls);
    }

    public function test_chat_maps_assistant_function_call_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    $modelMsg = $body['contents'][1] ?? [];

                    return $modelMsg['role'] === 'model'
                        && is_array($modelMsg['parts'])
                        && isset($modelMsg['parts'][0]['functionCall'])
                        && $modelMsg['parts'][0]['functionCall']['name'] === 'search'
                        && $modelMsg['parts'][0]['functionCall']['args'] === ['q' => 'cats'];
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Search for cats'),
                new Message(role: 'assistant', content: '', toolCalls: [
                    new ToolCall(id: 'gemini_search_0', name: 'search', arguments: ['q' => 'cats']),
                ]),
                new Message(role: 'tool', content: '{"results": []}', toolCallId: 'gemini_search_0'),
            ],
        ]));
    }

    public function test_chat_maps_function_response_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $body) {
                    $toolMsg = $body['contents'][2] ?? [];

                    return $toolMsg['role'] === 'user'
                        && is_array($toolMsg['parts'])
                        && isset($toolMsg['parts'][0]['functionResponse'])
                        && $toolMsg['parts'][0]['functionResponse']['name'] === 'search'
                        && $toolMsg['parts'][0]['functionResponse']['response'] === ['results' => []];
                }),
            )
            ->willReturn($this->geminiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Search for cats'),
                new Message(role: 'assistant', content: '', toolCalls: [
                    new ToolCall(id: 'gemini_search_0', name: 'search', arguments: ['q' => 'cats']),
                ]),
                new Message(role: 'tool', content: '{"results": []}', toolCallId: 'gemini_search_0'),
            ],
        ]));
    }

    public function test_chat_throws_when_tool_result_references_unknown_tool_call_id(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $transport->expects($this->never())->method('send');

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('unknown tool_call_id');

        $provider->chat($this->makeRequest([
            'messages' => [
                new Message(role: 'user', content: 'Search for cats'),
                new Message(role: 'tool', content: '{"results": []}', toolCallId: 'missing-id'),
            ],
        ]));
    }

    public function test_chat_throws_on_malformed_function_call_payload(): void
    {
        $transport = $this->fakeTransport([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            ['functionCall' => ['args' => ['q' => 'cats']]],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Malformed function call in Gemini response');

        $provider->chat($this->makeRequest());
    }

    public function test_constructor_throws_when_api_key_is_missing(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('requires a non-empty api_key');

        new GeminiProvider($transport, []);
    }
}
