<?php

namespace PressGang\Helm\Tests\WP;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Tests\Stubs\WpHooks;
use PressGang\Helm\Tests\TestCase;
use PressGang\Helm\WP\HookAwareProvider;

class HookAwareProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpHooks::reset();
    }

    protected function makeRequest(): ChatRequest
    {
        return new ChatRequest(
            messages: [new Message(role: 'user', content: 'Hello')],
            model: 'gpt-4o',
        );
    }

    protected function makeResponse(string $content = 'Hi!'): Response
    {
        return new Response(content: $content, raw: []);
    }

    public function test_delegates_to_wrapped_provider(): void
    {
        $expected = $this->makeResponse('Delegated.');

        $inner = $this->createMock(ProviderContract::class);
        $inner->expects($this->once())
            ->method('chat')
            ->willReturn($expected);

        $provider = new HookAwareProvider($inner);
        $response = $provider->chat($this->makeRequest());

        $this->assertSame($expected, $response);
    }

    public function test_fires_request_action_before_chat(): void
    {
        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willReturn($this->makeResponse());

        $provider = new HookAwareProvider($inner);
        $request = $this->makeRequest();
        $provider->chat($request);

        $this->assertArrayHasKey('pressgang_helm_request', WpHooks::$dispatched);
        $this->assertSame($request, WpHooks::$dispatched['pressgang_helm_request'][0][0]);
    }

    public function test_fires_response_action_after_chat(): void
    {
        $expected = $this->makeResponse('Done.');

        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willReturn($expected);

        $provider = new HookAwareProvider($inner);
        $provider->chat($this->makeRequest());

        $this->assertArrayHasKey('pressgang_helm_response', WpHooks::$dispatched);
        $this->assertSame($expected, WpHooks::$dispatched['pressgang_helm_response'][0][0]);
    }

    public function test_fires_error_action_on_exception(): void
    {
        $exception = new ProviderException('Connection refused');

        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willThrowException($exception);

        $provider = new HookAwareProvider($inner);

        try {
            $provider->chat($this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertArrayHasKey('pressgang_helm_error', WpHooks::$dispatched);
        $this->assertSame($exception, WpHooks::$dispatched['pressgang_helm_error'][0][0]);
    }

    public function test_rethrows_exception_after_error_hook(): void
    {
        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willThrowException(new ProviderException('fail'));

        $provider = new HookAwareProvider($inner);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('fail');

        $provider->chat($this->makeRequest());
    }

    public function test_response_action_includes_request_context(): void
    {
        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willReturn($this->makeResponse());

        $provider = new HookAwareProvider($inner);
        $request = $this->makeRequest();
        $provider->chat($request);

        // Second arg to response action is the request
        $this->assertSame($request, WpHooks::$dispatched['pressgang_helm_response'][0][1]);
    }

    public function test_error_action_includes_request_context(): void
    {
        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willThrowException(new ProviderException('boom'));

        $provider = new HookAwareProvider($inner);
        $request = $this->makeRequest();

        try {
            $provider->chat($request);
        } catch (ProviderException) {
            // expected
        }

        // Second arg to error action is the request
        $this->assertSame($request, WpHooks::$dispatched['pressgang_helm_error'][0][1]);
    }

    public function test_fires_error_action_for_non_provider_exceptions(): void
    {
        $inner = $this->createMock(ProviderContract::class);
        $inner->method('chat')->willThrowException(new \RuntimeException('unexpected'));

        $provider = new HookAwareProvider($inner);
        $request = $this->makeRequest();

        try {
            $provider->chat($request);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('unexpected', $e->getMessage());
        }

        $this->assertArrayHasKey('pressgang_helm_error', WpHooks::$dispatched);
        $this->assertInstanceOf(\RuntimeException::class, WpHooks::$dispatched['pressgang_helm_error'][0][0]);
        $this->assertSame($request, WpHooks::$dispatched['pressgang_helm_error'][0][1]);
    }
}
