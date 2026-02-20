<?php

namespace PressGang\Helm\Tests\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\StructuredResponse;
use PressGang\Helm\Exceptions\SchemaValidationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Tests\TestCase;

class RepairRetryTest extends TestCase
{
    protected array $schema = [
        'type' => 'object',
        'required' => ['score', 'feedback'],
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'feedback' => ['type' => 'string'],
        ],
    ];

    public function test_repair_retries_on_invalid_json_and_succeeds(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                new Response(content: 'not json', raw: []),
                new Response(content: json_encode(['score' => 7, 'feedback' => 'Fixed']), raw: []),
            );

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->repair(1)
            ->send();

        $this->assertInstanceOf(StructuredResponse::class, $response);
        $this->assertSame(7, $response['score']);
    }

    public function test_repair_retries_on_schema_mismatch_and_succeeds(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                new Response(content: json_encode(['score' => 'bad']), raw: []),
                new Response(content: json_encode(['score' => 5, 'feedback' => 'Fixed']), raw: []),
            );

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->repair(1)
            ->send();

        $this->assertInstanceOf(StructuredResponse::class, $response);
        $this->assertSame(5, $response['score']);
    }

    public function test_repair_exhausts_attempts_then_throws(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(3)) // initial + 2 retries
            ->method('chat')
            ->willReturn(new Response(content: 'still broken', raw: []));

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('not valid JSON');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->repair(2)
            ->send();
    }

    public function test_repair_sends_error_context_in_retry_message(): void
    {
        $capturedRequests = [];

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function (ChatRequest $request) use (&$capturedRequests) {
                $capturedRequests[] = $request;

                if (count($capturedRequests) === 1) {
                    return new Response(content: json_encode(['score' => 'wrong']), raw: []);
                }

                return new Response(
                    content: json_encode(['score' => 5, 'feedback' => 'OK']),
                    raw: [],
                );
            });

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->repair(1)
            ->send();

        // Second request should contain the assistant's failed response and validation error feedback
        $secondRequest = $capturedRequests[1];
        $messages = $secondRequest->messages;

        // Second-to-last: assistant's failed response
        $assistantMessage = $messages[count($messages) - 2];
        $this->assertSame('assistant', $assistantMessage->role);
        $this->assertStringContainsString('wrong', $assistantMessage->content);

        // Last: user error feedback
        $lastMessage = end($messages);
        $this->assertSame('user', $lastMessage->role);
        $this->assertStringContainsString('validation errors', $lastMessage->content);
    }

    public function test_repair_zero_means_no_retries(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->willReturn(new Response(content: 'not json', raw: []));

        $this->expectException(SchemaValidationException::class);

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->repair(0)
            ->send();
    }

    public function test_no_repair_by_default(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->willReturn(new Response(content: 'not json', raw: []));

        $this->expectException(SchemaValidationException::class);

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->send();
    }

    public function test_repair_caps_at_configured_attempts(): void
    {
        $callCount = 0;

        $provider = $this->createMock(ProviderContract::class);
        $provider->method('chat')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;

                return new Response(content: 'invalid', raw: []);
            });

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->repair(3)
                ->send();
        } catch (SchemaValidationException) {
            // expected
        }

        // 1 initial + 3 retries = 4 total
        $this->assertSame(4, $callCount);
    }

    public function test_repair_rejects_negative_attempts(): void
    {
        $provider = $this->createMock(ProviderContract::class);

        $this->expectException(\PressGang\Helm\Exceptions\ConfigurationException::class);
        $this->expectExceptionMessage('zero or greater');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->repair(-1);
    }
}
