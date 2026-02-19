<?php

namespace App\Providers;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;

/**
 * Stub: Provider driver.
 *
 * Providers translate ChatRequest into provider-specific HTTP calls
 * via a TransportContract, then normalise the response into a Helm Response.
 */
class DummyProvider implements ProviderContract
{
    public function __construct(
        protected TransportContract $transport,
        protected string $apiKey,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function chat(ChatRequest $request): Response
    {
        $payload = $request->toArray();

        $raw = $this->transport->send('POST', 'https://api.example.com/v1/chat/completions', [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ], $payload);

        return new Response(
            content: $raw['choices'][0]['message']['content'] ?? '',
            raw: $raw,
        );
    }
}
