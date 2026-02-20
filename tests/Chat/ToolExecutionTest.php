<?php

namespace PressGang\Helm\Tests\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Exceptions\ToolExecutionException;
use PressGang\Helm\Helm;
use PressGang\Helm\Tests\TestCase;

class ToolExecutionTest extends TestCase
{
    protected function makeTool(string $name, array $result = []): ToolContract
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("A {$name} tool");
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $tool->method('handle')->willReturn($result);

        return $tool;
    }

    protected function toolCallResponse(array $toolCalls): Response
    {
        return new Response(
            content: '',
            raw: ['choices' => []],
            toolCalls: $toolCalls,
        );
    }

    protected function textResponse(string $content = 'Final answer.'): Response
    {
        return new Response(content: $content, raw: []);
    }

    public function test_tool_loop_executes_tool_and_returns_final_response(): void
    {
        $weatherTool = $this->createMock(ToolContract::class);
        $weatherTool->method('name')->willReturn('get_weather');
        $weatherTool->method('description')->willReturn('Get weather');
        $weatherTool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $weatherTool->expects($this->once())
            ->method('handle')
            ->with(['city' => 'London'])
            ->willReturn(['temp' => 20, 'condition' => 'cloudy']);

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->toolCallResponse([
                    new ToolCall(id: 'call_1', name: 'get_weather', arguments: ['city' => 'London']),
                ]),
                $this->textResponse('It is 20C and cloudy in London.'),
            );

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('What is the weather in London?')
            ->tools([$weatherTool])
            ->send();

        $this->assertSame('It is 20C and cloudy in London.', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test_tool_loop_handles_multiple_tool_calls(): void
    {
        $search = $this->makeTool('search', ['results' => ['cats are great']]);
        $weather = $this->makeTool('get_weather', ['temp' => 15]);

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->toolCallResponse([
                    new ToolCall(id: 'call_1', name: 'search', arguments: ['q' => 'cats']),
                    new ToolCall(id: 'call_2', name: 'get_weather', arguments: ['city' => 'Paris']),
                ]),
                $this->textResponse('Here are your results.'),
            );

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Search cats and check Paris weather')
            ->tools([$search, $weather])
            ->send();

        $this->assertSame('Here are your results.', $response->content);
    }

    public function test_tool_loop_supports_multi_step(): void
    {
        $tool = $this->makeTool('think', ['thought' => 'step done']);

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(3))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->toolCallResponse([
                    new ToolCall(id: 'call_1', name: 'think', arguments: ['step' => 1]),
                ]),
                $this->toolCallResponse([
                    new ToolCall(id: 'call_2', name: 'think', arguments: ['step' => 2]),
                ]),
                $this->textResponse('Done thinking.'),
            );

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Think carefully.')
            ->tools([$tool])
            ->maxSteps(5)
            ->send();

        $this->assertSame('Done thinking.', $response->content);
    }

    public function test_tool_loop_terminates_at_max_steps(): void
    {
        $tool = $this->makeTool('loop', ['again' => true]);

        // Provider always returns tool calls — should stop after maxSteps
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(3)) // initial + 2 steps
            ->method('chat')
            ->willReturn($this->toolCallResponse([
                new ToolCall(id: 'call_x', name: 'loop', arguments: []),
            ]));

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Loop forever')
            ->tools([$tool])
            ->maxSteps(2)
            ->send();

        // Returns the last response (which still has tool calls, but we stopped)
        $this->assertTrue($response->hasToolCalls());
    }

    public function test_tool_loop_throws_when_tool_not_found(): void
    {
        $tool = $this->makeTool('search', []);

        $provider = $this->createMock(ProviderContract::class);
        $provider->method('chat')->willReturn(
            $this->toolCallResponse([
                new ToolCall(id: 'call_1', name: 'unknown_tool', arguments: []),
            ]),
        );

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Tool not found: unknown_tool');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Use a tool')
            ->tools([$tool])
            ->send();
    }

    public function test_tool_loop_appends_tool_results_to_messages(): void
    {
        $tool = $this->makeTool('search', ['found' => 'data']);

        $capturedRequests = [];

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->exactly(2))
            ->method('chat')
            ->willReturnCallback(function (ChatRequest $request) use (&$capturedRequests) {
                $capturedRequests[] = $request;

                if (count($capturedRequests) === 1) {
                    return $this->toolCallResponse([
                        new ToolCall(id: 'call_1', name: 'search', arguments: ['q' => 'test']),
                    ]);
                }

                return $this->textResponse('Found it.');
            });

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Search for test')
            ->tools([$tool])
            ->send();

        // Second request should have: user msg + assistant msg (with tool calls) + tool result msg
        $secondRequest = $capturedRequests[1];
        $this->assertCount(3, $secondRequest->messages);

        // First message: original user message
        $this->assertSame('user', $secondRequest->messages[0]->role);
        $this->assertSame('Search for test', $secondRequest->messages[0]->content);

        // Second message: assistant with tool calls
        $this->assertSame('assistant', $secondRequest->messages[1]->role);
        $this->assertNotNull($secondRequest->messages[1]->toolCalls);
        $this->assertSame('call_1', $secondRequest->messages[1]->toolCalls[0]->id);

        // Third message: tool result
        $this->assertSame('tool', $secondRequest->messages[2]->role);
        $this->assertSame('call_1', $secondRequest->messages[2]->toolCallId);
        $this->assertSame('{"found":"data"}', $secondRequest->messages[2]->content);
    }

    public function test_no_tool_loop_when_no_tools_registered(): void
    {
        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->willReturn($this->toolCallResponse([
                new ToolCall(id: 'call_1', name: 'search', arguments: []),
            ]));

        // No tools() call — should return the tool call response as-is
        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Search')
            ->send();

        $this->assertTrue($response->hasToolCalls());
    }

    public function test_response_without_tool_calls_skips_loop(): void
    {
        $tool = $this->makeTool('search', []);

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->willReturn($this->textResponse('No tools needed.'));

        $response = Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Just answer directly.')
            ->tools([$tool])
            ->send();

        $this->assertSame('No tools needed.', $response->content);
    }

    public function test_tool_loop_wraps_tool_exceptions(): void
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn('explode');
        $tool->method('description')->willReturn('Throws');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $tool->method('handle')->willThrowException(new \RuntimeException('boom'));

        $provider = $this->createMock(ProviderContract::class);
        $provider->method('chat')->willReturn(
            $this->toolCallResponse([
                new ToolCall(id: 'call_1', name: 'explode', arguments: []),
            ]),
        );

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Tool execution failed: explode');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->user('Run explode')
            ->tools([$tool])
            ->send();
    }

    public function test_tools_throws_on_duplicate_tool_names(): void
    {
        $first = $this->makeTool('search', ['a' => 1]);
        $second = $this->makeTool('search', ['b' => 2]);

        $provider = $this->createMock(ProviderContract::class);

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Duplicate tool name: search');

        Helm::make($provider, ['model' => 'gpt-4o'])
            ->chat()
            ->tools([$first, $second]);
    }

    public function test_tool_loop_throws_when_result_cannot_be_json_encoded(): void
    {
        $handle = fopen('php://temp', 'r');
        $tool = $this->makeTool('bad_result', ['resource' => $handle]);

        $provider = $this->createMock(ProviderContract::class);
        $provider->expects($this->once())
            ->method('chat')
            ->willReturn(
                $this->toolCallResponse([
                    new ToolCall(id: 'call_1', name: 'bad_result', arguments: []),
                ]),
            );

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('could not be JSON encoded');

        try {
            Helm::make($provider, ['model' => 'gpt-4o'])
                ->chat()
                ->user('Run bad_result')
                ->tools([$tool])
                ->send();
        } finally {
            fclose($handle);
        }
    }
}
