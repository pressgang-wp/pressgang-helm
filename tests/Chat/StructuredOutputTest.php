<?php

namespace PressGang\Helm\Tests\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\StructuredResponse;
use PressGang\Helm\Exceptions\SchemaValidationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Tests\TestCase;

class StructuredOutputTest extends TestCase
{
    protected array $schema = [
        'type' => 'object',
        'required' => ['score', 'feedback'],
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'feedback' => ['type' => 'string'],
        ],
    ];

    protected function fakeProvider(string $content): ProviderContract
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->method('chat')->willReturn(
            new Response(content: $content, raw: ['choices' => []]),
        );

        return $provider;
    }

    public function test_returns_structured_response_for_valid_json(): void
    {
        $json = json_encode(['score' => 8, 'feedback' => 'Well done']);
        $provider = $this->fakeProvider($json);

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->send();

        $this->assertInstanceOf(StructuredResponse::class, $response);
        $this->assertSame(8, $response['score']);
        $this->assertSame('Well done', $response['feedback']);
    }

    public function test_throws_on_invalid_json(): void
    {
        $provider = $this->fakeProvider('not json at all');

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('not valid JSON');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->send();
    }

    public function test_throws_on_schema_mismatch(): void
    {
        $json = json_encode(['score' => 'not-an-integer', 'feedback' => 'Good']);
        $provider = $this->fakeProvider($json);

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->send();

            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertSame('Response does not match schema.', $e->getMessage());
            $this->assertNotEmpty($e->validationErrors);
            $this->assertSame($json, $e->rawOutput);
            $this->assertNotEmpty($e->requestContext);
        }
    }

    public function test_throws_on_missing_required_property(): void
    {
        $json = json_encode(['score' => 5]);
        $provider = $this->fakeProvider($json);

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->send();

            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('feedback', $e->validationErrors[0]);
        }
    }

    public function test_exception_carries_raw_output(): void
    {
        $provider = $this->fakeProvider('garbage');

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->send();

            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertSame('garbage', $e->rawOutput);
        }
    }

    public function test_exception_carries_request_context(): void
    {
        $json = json_encode(['wrong' => 'shape']);
        $provider = $this->fakeProvider($json);

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->send();

            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('model', $e->requestContext);
            $this->assertArrayHasKey('messages', $e->requestContext);
        }
    }

    public function test_schema_passes_through_to_request(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn (ChatRequest $req) => $req->schema === $this->schema))
            ->willReturn(new Response(
                content: json_encode(['score' => 5, 'feedback' => 'OK']),
                raw: [],
            ));

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Rate this.')
            ->jsonSchema($this->schema)
            ->send();
    }

    public function test_returns_plain_response_without_schema(): void
    {
        $provider = $this->fakeProvider('Just text');

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Hello')
            ->send();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNotInstanceOf(StructuredResponse::class, $response);
    }

    public function test_validates_number_range_constraints(): void
    {
        $json = json_encode(['score' => 15, 'feedback' => 'Over max']);
        $provider = $this->fakeProvider($json);

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Rate this.')
                ->jsonSchema($this->schema)
                ->send();

            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertStringContainsString('maximum', $e->validationErrors[0]);
        }
    }

    public function test_returns_structured_response_for_array_schema(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'integer'],
        ];

        $provider = $this->fakeProvider(json_encode([1, 2, 3]));

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Return a numeric list.')
            ->jsonSchema($schema)
            ->send();

        $this->assertInstanceOf(StructuredResponse::class, $response);
        $this->assertSame([1, 2, 3], $response->structured);
        $this->assertNull($response['score']);
    }
}
