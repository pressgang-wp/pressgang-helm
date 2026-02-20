<?php

namespace PressGang\Helm\WP;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use Throwable;

/**
 * Provider decorator that fires WordPress hooks around each chat() call.
 *
 * Wraps any ProviderContract and dispatches pressgang_helm_request,
 * pressgang_helm_response, and pressgang_helm_error actions at the
 * appropriate lifecycle points. Keeps hook logic out of core.
 */
class HookAwareProvider implements ProviderContract
{
    /**
     * @param ProviderContract $provider The underlying provider to delegate to.
     */
    public function __construct(
        protected ProviderContract $provider,
    ) {}

    /**
     * Send a chat request, firing lifecycle hooks before and after.
     *
     * @param ChatRequest $request The immutable chat request DTO.
     *
     * @return Response The normalised response.
     *
     * @throws Throwable Re-throws underlying provider exceptions after hook dispatch.
     */
    public function chat(ChatRequest $request): Response
    {
        \do_action('pressgang_helm_request', $request);

        try {
            $response = $this->provider->chat($request);
        } catch (Throwable $e) {
            \do_action('pressgang_helm_error', $e, $request);

            throw $e;
        }

        \do_action('pressgang_helm_response', $response, $request);

        return $response;
    }
}
