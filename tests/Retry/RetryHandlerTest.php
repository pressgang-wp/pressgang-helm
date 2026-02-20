<?php

namespace PressGang\Helm\Tests\Retry;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Retry\RetryHandler;
use PressGang\Helm\Tests\TestCase;

/**
 * Testable subclass that overrides sleep() to avoid real delays.
 */
class TestableRetryHandler extends RetryHandler
{
    /** @var array<int, int> Recorded sleep durations in milliseconds. */
    public array $sleepCalls = [];

    protected function sleep(int $milliseconds): void
    {
        $this->sleepCalls[] = $milliseconds;
    }
}

class RetryHandlerTest extends TestCase
{
    protected function makeRequest(): ChatRequest
    {
        return new ChatRequest(
            messages: [new Message(role: 'user', content: 'Hello')],
            model: 'gpt-4o',
        );
    }

    protected function makeResponse(string $content = 'OK'): Response
    {
        return new Response(content: $content, raw: []);
    }

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

    public function test_succeeds_on_first_attempt(): void
    {
        $provider = $this->mockProvider([$this->makeResponse('First try')]);
        $handler = new TestableRetryHandler(maxRetries: 2);

        $response = $handler->execute($provider, $this->makeRequest());

        $this->assertSame('First try', $response->content);
        $this->assertSame([], $handler->sleepCalls);
    }

    public function test_retries_on_429_and_succeeds(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Rate limited', 429),
            $this->makeResponse('Retry OK'),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 1);

        $response = $handler->execute($provider, $this->makeRequest());

        $this->assertSame('Retry OK', $response->content);
    }

    public function test_retries_on_500_and_succeeds(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Server error', 500),
            $this->makeResponse('OK'),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 1);

        $response = $handler->execute($provider, $this->makeRequest());

        $this->assertSame('OK', $response->content);
    }

    public function test_retries_on_502_and_succeeds(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Bad gateway', 502),
            $this->makeResponse('OK'),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 1);

        $response = $handler->execute($provider, $this->makeRequest());

        $this->assertSame('OK', $response->content);
    }

    public function test_retries_on_code_zero_and_succeeds(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Timeout', 0),
            $this->makeResponse('OK'),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 1);

        $response = $handler->execute($provider, $this->makeRequest());

        $this->assertSame('OK', $response->content);
    }

    public function test_does_not_retry_on_401(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Unauthorized', 401),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 3);

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(401);

        $handler->execute($provider, $this->makeRequest());
    }

    public function test_does_not_retry_on_403(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Forbidden', 403),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 3);

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(403);

        $handler->execute($provider, $this->makeRequest());
    }

    public function test_does_not_retry_on_400(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Bad request', 400),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 3);

        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(400);

        $handler->execute($provider, $this->makeRequest());
    }

    public function test_exhausts_retries_and_throws_with_attempted_providers(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Error 1', 500),
            new ProviderException('Error 2', 500),
            new ProviderException('Error 3', 500),
        ]);
        $handler = new TestableRetryHandler(maxRetries: 2);

        try {
            $handler->execute($provider, $this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('All providers exhausted', $e->getMessage());
            $this->assertStringContainsString('ProviderContract', $e->getMessage());
        }
    }

    public function test_falls_back_to_second_provider(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Primary down', 500),
        ]);
        $fallback = $this->mockProvider([$this->makeResponse('Fallback OK')]);

        $handler = new TestableRetryHandler(
            maxRetries: 0,
            fallbackProviders: [$fallback],
        );

        $response = $handler->execute($primary, $this->makeRequest());

        $this->assertSame('Fallback OK', $response->content);
    }

    public function test_falls_back_through_multiple_providers(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Primary down', 500),
        ]);
        $fallback1 = $this->mockProvider([
            new ProviderException('Fallback 1 down', 502),
        ]);
        $fallback2 = $this->mockProvider([$this->makeResponse('Fallback 2 OK')]);

        $handler = new TestableRetryHandler(
            maxRetries: 0,
            fallbackProviders: [$fallback1, $fallback2],
        );

        $response = $handler->execute($primary, $this->makeRequest());

        $this->assertSame('Fallback 2 OK', $response->content);
    }

    public function test_all_providers_exhausted_throws_with_chain(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Primary fail', 500),
        ]);
        $fallback = $this->mockProvider([
            new ProviderException('Fallback fail', 502),
        ]);

        $handler = new TestableRetryHandler(
            maxRetries: 0,
            fallbackProviders: [$fallback],
        );

        try {
            $handler->execute($primary, $this->makeRequest());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('All providers exhausted', $e->getMessage());
            $this->assertInstanceOf(ProviderException::class, $e->getPrevious());
            $this->assertSame(502, $e->getPrevious()->getCode());
        }
    }

    public function test_non_retryable_error_skips_to_next_provider(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Auth failed', 401),
        ]);
        $fallback = $this->mockProvider([$this->makeResponse('Fallback OK')]);

        $handler = new TestableRetryHandler(
            maxRetries: 3,
            fallbackProviders: [$fallback],
        );

        $response = $handler->execute($primary, $this->makeRequest());

        $this->assertSame('Fallback OK', $response->content);
    }

    /** @dataProvider retryableCodeProvider */
    public function test_is_retryable_returns_true_for_transient_codes(int $code): void
    {
        $this->assertTrue(RetryHandler::isRetryable(new ProviderException('', $code)));
    }

    public static function retryableCodeProvider(): array
    {
        return [
            'timeout' => [0],
            'rate limit' => [429],
            'internal server error' => [500],
            'bad gateway' => [502],
            'service unavailable' => [503],
            'gateway timeout' => [504],
        ];
    }

    /** @dataProvider nonRetryableCodeProvider */
    public function test_is_retryable_returns_false_for_client_errors(int $code): void
    {
        $this->assertFalse(RetryHandler::isRetryable(new ProviderException('', $code)));
    }

    public static function nonRetryableCodeProvider(): array
    {
        return [
            'bad request' => [400],
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'unprocessable entity' => [422],
        ];
    }

    public function test_zero_retries_with_fallbacks_tries_each_provider_once(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Down', 500),
        ]);
        $fallback = $this->mockProvider([$this->makeResponse('Fallback')]);

        $handler = new TestableRetryHandler(
            maxRetries: 0,
            fallbackProviders: [$fallback],
        );

        $response = $handler->execute($primary, $this->makeRequest());

        $this->assertSame('Fallback', $response->content);
        $this->assertSame([], $handler->sleepCalls);
    }

    public function test_sleep_called_between_retries(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Error', 500),
            new ProviderException('Error', 500),
            $this->makeResponse('OK'),
        ]);
        $handler = new TestableRetryHandler(
            maxRetries: 2,
            baseDelayMs: 100,
            maxDelayMs: 5000,
        );

        $handler->execute($provider, $this->makeRequest());

        $this->assertCount(2, $handler->sleepCalls);
        // Exponential backoff: 100 * 2^0 = 100, 100 * 2^1 = 200
        $this->assertSame(100, $handler->sleepCalls[0]);
        $this->assertSame(200, $handler->sleepCalls[1]);
    }

    public function test_backoff_respects_max_delay(): void
    {
        $provider = $this->mockProvider([
            new ProviderException('Error', 500),
            new ProviderException('Error', 500),
            new ProviderException('Error', 500),
            $this->makeResponse('OK'),
        ]);
        $handler = new TestableRetryHandler(
            maxRetries: 3,
            baseDelayMs: 200,
            maxDelayMs: 500,
        );

        $handler->execute($provider, $this->makeRequest());

        // 200*1=200, 200*2=400, 200*4=800->capped to 500
        $this->assertSame(200, $handler->sleepCalls[0]);
        $this->assertSame(400, $handler->sleepCalls[1]);
        $this->assertSame(500, $handler->sleepCalls[2]);
    }

    public function test_retries_primary_then_falls_back_with_retries(): void
    {
        $primary = $this->mockProvider([
            new ProviderException('Fail 1', 500),
            new ProviderException('Fail 2', 500),
        ]);
        $fallback = $this->mockProvider([
            new ProviderException('Fail 3', 502),
            $this->makeResponse('Eventually OK'),
        ]);

        $handler = new TestableRetryHandler(
            maxRetries: 1,
            fallbackProviders: [$fallback],
        );

        $response = $handler->execute($primary, $this->makeRequest());

        $this->assertSame('Eventually OK', $response->content);
    }
}
