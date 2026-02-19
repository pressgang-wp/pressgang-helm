<?php

namespace PressGang\Helm;

use PressGang\Helm\Chat\ChatBuilder;
use PressGang\Helm\Contracts\ProviderContract;

/**
 * Main entry point for PressGang Helm.
 *
 * Helm is a thin orchestration root that wires a provider and config
 * together and exposes builder entry points. It holds no global state
 * and makes no remote calls during construction.
 *
 * Callers are responsible for resolving config (e.g. from config/helm.php,
 * environment, or WP options) before passing it in.
 */
class Helm
{
    /**
     * @param ProviderContract     $provider The provider driver for AI requests.
     * @param array<string, mixed> $config   Resolved configuration values.
     */
    public function __construct(
        protected ProviderContract $provider,
        protected array $config = [],
    ) {}

    /**
     * Static factory for creating a Helm instance.
     *
     * @param ProviderContract     $provider The provider driver.
     * @param array<string, mixed> $config   Resolved configuration values.
     *
     * @return static
     */
    public static function make(ProviderContract $provider, array $config = []): static
    {
        return new static($provider, $config);
    }

    /**
     * Start building a chat completion request.
     *
     * @return ChatBuilder
     */
    public function chat(): ChatBuilder
    {
        return new ChatBuilder(
            provider: $this->provider,
            model: $this->config['model'] ?? null,
        );
    }

    /**
     * Get the current provider instance.
     *
     * @return ProviderContract
     */
    public function provider(): ProviderContract
    {
        return $this->provider;
    }
}
