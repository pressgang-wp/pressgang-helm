<?php

namespace PressGang\Helm\Tests\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Exceptions\SchemaValidationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Tests\TestCase;

class RetryIntegrationTest extends TestCase
{
    protected function mockProvider(array $responses): ProviderContract
    {
        $provider = $this->createMock(ProviderContract::class);
        $callIndex = 0;

        $provider->method('chat')
            ->willReturnCallback(function () use (&$callIndex, $responses) {
                $result = $responses[$callIndex] ?? throw new \LogicException('Unexpected call');
                $callIndex++;

                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });

        return $provider;
    }

    protected function textResponse(string $content = 'OK'): Response
    {
        return new Response(content: $content, raw: []);
    }

    protected function toolCallResponse(array $toolCalls): Response
    {
        return new Response(
            content: '',
            raw: [],
            toolCalls: $toolCalls,
        );
    }

    protected function makeTool(string $name, array $result = []): ToolContract
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("A {$name} tool");
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $tool->method('handle')->willReturn($result);

        return $tool;
    }

    public function test_retries_on_transient_failure(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Server error', 500),
            $this->textResponse('Retry OK'),
        ]);

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->retries(1)
            ->user('Hello')
            ->send();

        $this->assertSame('Retry OK', $response->content);
    }

    public function test_fallback_provider_on_failure(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Primary down', 500),
        ]);
        $backup = $this->mockProvider([$this->textResponse('Backup OK')]);

        $response = Helm::make($primary, ['model' => 'gpt-4o'])
            ->chat()
            ->fallbackProviders([$backup])
            ->user('Hello')
            ->send();

        $this->assertSame('Backup OK', $response->content);
    }

    public function test_combined_retries_and_fallback(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Fail 1', 500),
            new ProviderException('Fail 2', 500),
        ]);
        $backup = $this->mockProvider([
            new ProviderException('Backup fail 1', 502),
            $this->textResponse('Eventually OK'),
        ]);

        $response = Helm::make($primary, ['model' => 'gpt-4o'])
            ->chat()
            ->retries(1)
            ->fallbackProviders([$backup])
            ->user('Hello')
            ->send();

        $this->assertSame('Eventually OK', $response->content);
    }

    public function test_tool_loop_retries_each_iteration(): void
    {
        $tool = $this->makeTool('search', ['result' => 'found']);

        $provider = $this->mockProvider([
            // First call: returns tool call
            $this->toolCallResponse([
                new ToolCall(id: 'tc1', name: 'search', arguments: []),
            ]),
            // Second call (after tool execution): transient failure, then success
            new ProviderException('Transient', 500),
            $this->textResponse('Final answer'),
        ]);

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->retries(1)
            ->tools([$tool])
            ->user('Search something')
            ->send();

        $this->assertSame('Final answer', $response->content);
    }

    public function test_schema_validation_errors_not_retried_by_retry_handler(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
        ];

        // Provider returns invalid JSON — this triggers SchemaValidationException,
        // not ProviderException, so RetryHandler should not catch it
        $provider = $this->mockProvider([
            $this->textResponse('not json'),
        ]);

        $this->expectException(SchemaValidationException::class);

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->retries(1)
            ->jsonSchema($schema)
            ->user('Give me data')
            ->send();
    }

    public function test_config_driven_retries_applied_as_defaults(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Transient', 500),
            $this->textResponse('OK'),
        ]);

        $response = Helm::make($provider, ['model' => 'gpt-4o', 'retries' => 1])
            ->chat()
            ->user('Hello')
            ->send();

        $this->assertSame('OK', $response->content);
    }

    public function test_builder_retries_overrides_config_default(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Transient', 500),
        ]);

        // Config sets retries=1, but builder overrides to 0
        $this->expectException(ProviderException::class);

        Helm::make($provider, ['model' => 'gpt-4o', 'retries' => 1])
            ->chat()
            ->retries(0)
            ->user('Hello')
            ->send();
    }

    public function test_negative_retries_throws_configuration_exception(): void
    {
        $provider = $this->createMock(ProviderContract::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('zero or greater');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->retries(-1);
    }

    public function test_invalid_fallback_provider_entry_throws_configuration_exception(): void
    {
        $provider = $this->createMock(ProviderContract::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must implement ProviderContract');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->fallbackProviders(['not-a-provider']);
    }

    public function test_zero_retries_no_fallback_passes_through_directly(): void
    {
        $provider = $this->mockProvider([$this->textResponse('Direct')]);

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Hello')
            ->send();

        $this->assertSame('Direct', $response->content);
    }
}
