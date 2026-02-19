<?php

namespace PressGang\Helm\Contracts;

use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;

/**
 * Contract for AI provider drivers.
 *
 * Each provider (OpenAI, Anthropic, etc.) implements this interface
 * to normalise chat completions behind a single API surface.
 * Providers depend on a TransportContract for HTTP communication.
 *
 * Implement this contract to add a new provider driver to Helm.
 */
interface ProviderContract
{
    /**
     * Send a chat completion request to the provider.
     *
     * @param ChatRequest $request The immutable chat request DTO.
     *
     * @return Response The normalised response containing content and raw provider output.
     */
    public function chat(ChatRequest $request): Response;
}
