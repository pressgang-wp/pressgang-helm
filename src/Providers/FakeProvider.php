<?php

namespace PressGang\Helm\Providers;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;

/**
 * A fake provider for testing and development.
 *
 * Returns a static response without making any HTTP calls.
 * Use this provider to verify builder/DTO plumbing and in test suites.
 */
class FakeProvider implements ProviderContract
{
    /**
     * Return a fake response without making any remote calls.
     *
     * @param ChatRequest $request The chat request (ignored).
     *
     * @return Response A static fake response.
     */
    public function chat(ChatRequest $request): Response
    {
        return new Response(
            content: 'fake response',
            raw: ['fake' => true],
        );
    }
}
