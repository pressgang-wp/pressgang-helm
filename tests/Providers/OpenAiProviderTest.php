<?php

namespace PressGang\Helm\Tests\Providers;

use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Providers\OpenAiProvider;
use PressGang\Helm\Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    protected function makeProvider(TransportContract $transport, array $config = []): OpenAiProvider
    {
        return new OpenAiProvider($transport, array_merge([
            'api_key' => 'sk-test-key-123',
        ], $config));
    }

    protected function makeRequest(array $overrides = []): ChatRequest
    {
        return new ChatRequest(
            messages: $overrides['messages'] ?? [
                new Message(role: 'system', content: 'Be concise.'),
                new Message(role: 'user', content: 'Hello'),
            ],
            model: $overrides['model'] ?? 'gpt-4o',
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

    protected function openAiResponse(string $content = 'Hello!'): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ];
    }

    public function test_chat_returns_response_with_content(): void
    {
        $transport = $this->fakeTransport($this->openAiResponse('The sky is blue.'));
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('The sky is blue.', $response->content);
    }

    public function test_chat_preserves_raw_response(): void
    {
        $raw = $this->openAiResponse('Test');
        $transport = $this->fakeTransport($raw);
        $provider = $this->makeProvider($transport);

        $response = $provider->chat($this->makeRequest());

        $this->assertSame($raw, $response->raw);
    }

    public function test_chat_maps_system_and_user_messages(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://api.openai.com/v1/chat/completions',
                $this->anything(),
                $this->callback(function (array $body) {
                    return $body['messages'][0]['role'] === 'system'
                        && $body['messages'][0]['content'] === 'Be concise.'
                        && $body['messages'][1]['role'] === 'user'
                        && $body['messages'][1]['content'] === 'Hello';
                }),
            )
            ->willReturn($this->openAiResponse());

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
                $this->callback(fn (array $body) => $body['model'] === 'gpt-4o-mini'),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['model' => 'gpt-4o-mini']));
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
                $this->callback(fn (array $body) => $body['temperature'] === 0.7),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['temperature' => 0.7]));
    }

    public function test_chat_omits_temperature_when_null(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('temperature', $body)),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['temperature' => null]));
    }

    public function test_chat_sends_tools_in_openai_format(): void
    {
        $normalizedTools = [
            [
                'name' => 'get_weather',
                'description' => 'Get the weather',
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
                    $tool = $body['tools'][0] ?? [];

                    return $tool['type'] === 'function'
                        && $tool['function']['name'] === 'get_weather'
                        && $tool['function']['description'] === 'Get the weather'
                        && isset($tool['function']['parameters']);
                }),
            )
            ->willReturn($this->openAiResponse());

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
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['tools' => []]));
    }

    public function test_chat_maps_schema_to_response_format(): void
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
                    return $body['response_format']['type'] === 'json_schema'
                        && $body['response_format']['json_schema']['schema'] === $schema
                        && $body['response_format']['json_schema']['strict'] === true;
                }),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['schema' => $schema]));
    }

    public function test_chat_maps_schema_envelope_without_leaking_name_or_strict_into_schema_body(): void
    {
        $schema = [
            'name' => 'product_summary',
            'strict' => false,
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
                $this->callback(function (array $body): bool {
                    $jsonSchema = $body['response_format']['json_schema'] ?? [];
                    $schemaBody = $jsonSchema['schema'] ?? [];

                    return ($jsonSchema['name'] ?? null) === 'product_summary'
                        && ($jsonSchema['strict'] ?? null) === false
                        && !array_key_exists('name', $schemaBody)
                        && !array_key_exists('strict', $schemaBody)
                        && ($schemaBody['type'] ?? null) === 'object';
                }),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['schema' => $schema]));
    }

    public function test_chat_omits_response_format_when_no_schema(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $body) => !array_key_exists('response_format', $body)),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport);
        $provider->chat($this->makeRequest(['schema' => null]));
    }

    public function test_chat_sends_authorization_header(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $transport->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $headers) => $headers['Authorization'] === 'Bearer sk-test-key-123'),
                $this->anything(),
            )
            ->willReturn($this->openAiResponse());

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
                'https://api.openai.com/v1/chat/completions',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->openAiResponse());

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
                'https://my-proxy.example.com/v1/chat/completions',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport, [
            'openai' => ['base_url' => 'https://my-proxy.example.com/v1'],
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
                'https://my-proxy.example.com/v1/chat/completions',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->openAiResponse());

        $provider = $this->makeProvider($transport, [
            'openai' => ['base_url' => 'https://my-proxy.example.com/v1/'],
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
            new ProviderException('Error with key sk-test-key-123 in request'),
        );

        $provider = $this->makeProvider($transport);

        try {
            $provider->chat($this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('sk-test-key-123', $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_chat_returns_empty_content_when_missing(): void
    {
        $transport = $this->fakeTransport([
            'choices' => [['message' => ['role' => 'assistant'], 'finish_reason' => 'stop']],
        ]);

        $provider = $this->makeProvider($transport);
        $response = $provider->chat($this->makeRequest());

        $this->assertSame('', $response->content);
    }

    public function test_chat_throws_on_malformed_tool_call_payload(): void
    {
        $transport = $this->fakeTransport([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [
                            ['function' => ['arguments' => '{}']],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = $this->makeProvider($transport);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Malformed tool call in OpenAI response');

        $provider->chat($this->makeRequest());
    }

    public function test_constructor_throws_when_api_key_is_missing(): void
    {
        $transport = $this->createMock(TransportContract::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('requires a non-empty api_key');

        new OpenAiProvider($transport, []);
    }
}
