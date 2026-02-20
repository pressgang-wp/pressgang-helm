<?php

namespace PressGang\Helm\Retry;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ProviderException;

/**
 * Strategy class that wraps provider->chat() calls with retry and failover logic.
 *
 * Retries transient failures (timeouts, rate limits, server errors) with exponential
 * backoff, then falls through to fallback providers when the primary is exhausted.
 * Only ProviderException is caught; all other exception types propagate immediately.
 */
class RetryHandler
{
    /**
     * @param int              $maxRetries        Maximum retry attempts per provider (0 = no retries).
     * @param ProviderContract[] $fallbackProviders Fallback providers tried in order after primary.
     * @param int              $baseDelayMs       Base delay for exponential backoff in milliseconds.
     * @param int              $maxDelayMs        Maximum delay cap in milliseconds.
     */
    public function __construct(
        protected int $maxRetries = 0,
        protected array $fallbackProviders = [],
        protected int $baseDelayMs = 200,
        protected int $maxDelayMs = 5000,
    ) {}

    /**
     * Execute a chat request with retry and failover protection.
     *
     * Tries the primary provider up to maxRetries times, then each fallback
     * provider in order. Throws when all providers are exhausted.
     *
     * @param ProviderContract $primary The primary provider to try first.
     * @param ChatRequest      $request The immutable chat request.
     *
     * @return Response
     *
     * @throws ProviderException When all providers and retries are exhausted.
     */
    public function execute(ProviderContract $primary, ChatRequest $request): Response
    {
        $providers = [$primary, ...$this->fallbackProviders];
        $attemptedProviders = [];
        $lastException = null;

        foreach ($providers as $provider) {
            $providerName = $provider::class;
            $attemptedProviders[] = $providerName;

            try {
                return $this->tryProvider($provider, $request);
            } catch (ProviderException $e) {
                $lastException = $e;
                // Continue to next provider
            }
        }

        throw new ProviderException(
            'All providers exhausted. Attempted: ' . implode(', ', $attemptedProviders),
            $lastException?->getCode() ?? 0,
            $lastException,
        );
    }

    /**
     * Try a single provider with retries.
     *
     * @param ProviderContract $provider The provider to attempt.
     * @param ChatRequest      $request  The chat request.
     *
     * @return Response
     *
     * @throws ProviderException When retries are exhausted or a non-retryable error occurs.
     */
    protected function tryProvider(ProviderContract $provider, ChatRequest $request): Response
    {
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $provider->chat($request);
            } catch (ProviderException $e) {
                if (!self::isRetryable($e) || $attempt === $this->maxRetries) {
                    throw $e;
                }

                $this->sleep($this->calculateDelay($attempt));
            }
        }

        throw new ProviderException('Retries exhausted'); // @codeCoverageIgnore
    }

    /**
     * Determine whether a ProviderException represents a transient, retryable error.
     *
     * Retryable: 0 (timeout/network), 429 (rate limit), 500-599 (server errors).
     * Not retryable: 400, 401, 403, 404 and other client errors.
     */
    public static function isRetryable(ProviderException $e): bool
    {
        $code = $e->getCode();

        if ($code === 0 || $code === 429) {
            return true;
        }

        return $code >= 500 && $code <= 599;
    }

    /**
     * Calculate the delay in milliseconds for a given attempt using exponential backoff.
     */
    protected function calculateDelay(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** $attempt);

        return min($delay, $this->maxDelayMs);
    }

    /**
     * Sleep for the given number of milliseconds.
     *
     * Protected seam for testing — override in test subclass to avoid real delays.
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
