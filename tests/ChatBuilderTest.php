<?php

namespace PressGang\Helm\Tests;

use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\FakeProvider;

class ChatBuilderTest extends TestCase
{
    protected function helm(array $config = []): Helm
    {
        return Helm::make(
            new FakeProvider(),
            array_merge(['model' => 'gpt-4o'], $config),
        );
    }

    public function test_system_and_user_accumulate_messages(): void
    {
        $request = $this->helm()
            ->chat()
            ->system('You are helpful.')
            ->user('Hello')
            ->toRequest();

        $this->assertCount(2, $request->messages);
        $this->assertSame('system', $request->messages[0]->role);
        $this->assertSame('You are helpful.', $request->messages[0]->content);
        $this->assertSame('user', $request->messages[1]->role);
        $this->assertSame('Hello', $request->messages[1]->content);
    }

    public function test_model_sets_model_on_request(): void
    {
        $request = $this->helm()
            ->chat()
            ->model('claude-sonnet-4-20250514')
            ->user('Hi')
            ->toRequest();

        $this->assertSame('claude-sonnet-4-20250514', $request->model);
    }

    public function test_temperature_sets_temperature_on_request(): void
    {
        $request = $this->helm()
            ->chat()
            ->temperature(0.5)
            ->user('Hi')
            ->toRequest();

        $this->assertSame(0.5, $request->temperature);
    }

    public function test_tools_sets_tools_on_request(): void
    {
        $tools = [
            ['name' => 'search', 'description' => 'Search the web'],
        ];

        $request = $this->helm()
            ->chat()
            ->tools($tools)
            ->user('Hi')
            ->toRequest();

        $this->assertSame($tools, $request->tools);
    }

    public function test_json_schema_sets_schema_on_request(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ];

        $request = $this->helm()
            ->chat()
            ->jsonSchema($schema)
            ->user('Hi')
            ->toRequest();

        $this->assertSame($schema, $request->schema);
    }

    public function test_send_returns_response(): void
    {
        $response = $this->helm()
            ->chat()
            ->system('You are helpful.')
            ->user('Hello')
            ->send();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('fake response', $response->content);
        $this->assertSame(['fake' => true], $response->raw);
    }

    public function test_send_calls_provider_with_chat_request(): void
    {
        $provider = $this->createMock(FakeProvider::class);

        $provider->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (ChatRequest $request) {
                return count($request->messages) === 1
                    && $request->messages[0]->role === 'user'
                    && $request->messages[0]->content === 'Test message';
            }))
            ->willReturn(new Response(content: 'mocked', raw: []));

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Test message')
            ->send();

        $this->assertSame('mocked', $response->content);
    }

    public function test_to_request_produces_correct_array(): void
    {
        $request = $this->helm()
            ->chat()
            ->system('Be concise.')
            ->user('Summarise this.')
            ->model('gpt-4o')
            ->temperature(0.7)
            ->toRequest();

        $array = $request->toArray();

        $this->assertSame('gpt-4o', $array['model']);
        $this->assertSame(0.7, $array['temperature']);
        $this->assertCount(2, $array['messages']);
        $this->assertSame('system', $array['messages'][0]['role']);
        $this->assertSame('user', $array['messages'][1]['role']);
        $this->assertArrayNotHasKey('tools', $array);
        $this->assertArrayNotHasKey('schema', $array);
    }

    public function test_config_model_is_used_as_default(): void
    {
        $request = $this->helm(['model' => 'claude-sonnet-4-20250514'])
            ->chat()
            ->user('Hi')
            ->toRequest();

        $this->assertSame('claude-sonnet-4-20250514', $request->model);
    }

    public function test_explicit_model_overrides_config(): void
    {
        $request = $this->helm(['model' => 'claude-sonnet-4-20250514'])
            ->chat()
            ->model('gpt-4o-mini')
            ->user('Hi')
            ->toRequest();

        $this->assertSame('gpt-4o-mini', $request->model);
    }

    public function test_missing_model_throws_configuration_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        Helm::make(new FakeProvider())
            ->chat()
            ->user('Hi')
            ->send();
    }

    public function test_missing_model_on_to_request_throws_configuration_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        Helm::make(new FakeProvider())
            ->chat()
            ->user('Hi')
            ->toRequest();
    }
}
