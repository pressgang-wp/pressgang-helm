<?php

namespace PressGang\Helm\WP;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\AnthropicProvider;
use PressGang\Helm\Providers\OpenAiProvider;
use PressGang\ServiceProviders\ServiceProviderInterface;
use Throwable;

/**
 * Boots Helm inside a PressGang theme.
 *
 * Reads config from PressGang's Config system, resolves the transport
 * and provider, creates the Helm instance, and registers WordPress
 * hooks for instance access and tool collection. Follows PressGang's
 * service provider pattern (instance-based with boot()).
 */
class HelmServiceProvider implements ServiceProviderInterface
{
    protected ?Helm $helm = null;

    /**
     * @param array<string, mixed> $config Pre-resolved config (for testing). Empty to read from PressGang.
     */
    public function __construct(
        protected array $config = [],
    ) {}

    /**
     * Boot Helm from config.
     *
     * Reads from PressGang Config when no config was provided at construction.
     * Skips silently when api_key is empty — no exception during bootstrap.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->config === []) {
            $this->config = \PressGang\Bootstrap\Config::get('helm', []);
        }

        if (empty($this->config['api_key'])) {
            return;
        }

        $transport = new WpHttpTransport($this->config);
        $provider = self::resolveProvider($this->config, $transport);
        $hookAwareProvider = new HookAwareProvider($provider);

        $fallbackProviders = $this->resolveFallbackProviders($transport);

        $this->helm = new WordPressHelm(
            provider: $hookAwareProvider,
            config: $this->config,
            toolResolver: fn () => $this->collectTools(),
            fallbackProviders: $fallbackProviders,
        );

        \add_filter('pressgang_helm_instance', [$this, 'filterHelmInstance']);
        \add_action('init', [$this, 'validateRegisteredToolsOnInit']);
    }

    /**
     * Get the booted Helm instance, or null if boot was skipped.
     *
     * @return Helm|null
     */
    public function helm(): ?Helm
    {
        return $this->helm;
    }

    /**
     * Resolve the provider driver from config.
     *
     * @param array<string, mixed> $config    The resolved Helm config.
     * @param TransportContract    $transport The HTTP transport.
     *
     * @return ProviderContract
     *
     * @throws ConfigurationException If the provider name is unknown.
     */
    public static function resolveProvider(array $config, TransportContract $transport): ProviderContract
    {
        $name = $config['provider'] ?? 'openai';

        return match ($name) {
            'openai' => new OpenAiProvider($transport, $config),
            'anthropic' => new AnthropicProvider($transport, $config),
            default => throw new ConfigurationException("Unknown provider: {$name}"),
        };
    }

    /**
     * Resolve fallback providers from config.
     *
     * Each entry in config['fallback_providers'] is a provider key (e.g. 'anthropic').
     * The provider-specific config section is merged into the base config, and
     * each resolved provider is wrapped in HookAwareProvider for observability.
     *
     * @param TransportContract $transport The HTTP transport.
     *
     * @return ProviderContract[]
     *
     * @throws ConfigurationException If a fallback provider key is invalid.
     */
    protected function resolveFallbackProviders(TransportContract $transport): array
    {
        $fallbackKeys = $this->config['fallback_providers'] ?? [];

        if (!is_array($fallbackKeys)) {
            $type = get_debug_type($fallbackKeys);
            throw new ConfigurationException(
                "helm.fallback_providers config must be an array, got {$type}.",
            );
        }

        if ($fallbackKeys === []) {
            return [];
        }

        $providers = [];
        $primaryProvider = $this->config['provider'] ?? 'openai';

        foreach ($fallbackKeys as $index => $key) {
            if (!is_string($key) || $key === '') {
                $type = get_debug_type($key);
                throw new ConfigurationException(
                    "helm.fallback_providers entry at index {$index} must be a non-empty provider key string, got {$type}.",
                );
            }

            $providerSpecific = $this->config[$key] ?? [];
            if (!is_array($providerSpecific)) {
                $type = get_debug_type($providerSpecific);
                throw new ConfigurationException(
                    "helm.{$key} config must be an array when used as a fallback provider, got {$type}.",
                );
            }

            if ($key !== $primaryProvider && !array_key_exists('api_key', $providerSpecific)) {
                throw new ConfigurationException(
                    "helm.{$key}.api_key must be set when '{$key}' is configured as a fallback provider.",
                );
            }

            $providerConfig = array_merge(
                $this->config,
                $providerSpecific,
                ['provider' => $key],
            );

            $provider = self::resolveProvider($providerConfig, $transport);
            $providers[] = new HookAwareProvider($provider);
        }

        return $providers;
    }

    /**
     * Validate that collected tools all implement ToolContract.
     *
     * @param array<mixed> $tools The tools collected from the filter.
     *
     * @throws ConfigurationException If a tool does not implement ToolContract.
     */
    protected function validateTools(array $tools): void
    {
        foreach ($tools as $index => $tool) {
            if (!$tool instanceof ToolContract) {
                $type = get_debug_type($tool);
                throw new ConfigurationException(
                    "Tool at index {$index} must implement ToolContract, got {$type}.",
                );
            }
        }
    }

    /**
     * Collect and validate tools from WordPress filters.
     *
     * @return array<int, ToolContract>
     *
     * @throws ConfigurationException If filter output is invalid.
     */
    protected function collectTools(): array
    {
        $configTools = $this->configuredTools();
        $tools = \apply_filters('pressgang_helm_tools', $configTools);

        if (!is_array($tools)) {
            $type = get_debug_type($tools);
            throw new ConfigurationException(
                "pressgang_helm_tools filter must return an array, got {$type}.",
            );
        }

        $this->validateTools($tools);

        return $tools;
    }

    /**
     * Instantiate tool classes listed in config.
     *
     * @return array<int, object>
     *
     * @throws ConfigurationException If a class name is invalid or cannot be instantiated.
     */
    protected function configuredTools(): array
    {
        $configured = $this->config['tools'] ?? [];

        if (!is_array($configured)) {
            $type = get_debug_type($configured);
            throw new ConfigurationException(
                "helm.tools config must be an array, got {$type}.",
            );
        }

        $tools = [];

        foreach ($configured as $index => $class) {
            if (!is_string($class)) {
                $type = get_debug_type($class);
                throw new ConfigurationException(
                    "helm.tools entry at index {$index} must be a class-string, got {$type}.",
                );
            }

            if (!class_exists($class)) {
                throw new ConfigurationException(
                    "helm.tools class does not exist at index {$index}: {$class}.",
                );
            }

            try {
                $tools[] = new $class();
            } catch (Throwable $e) {
                throw new ConfigurationException(
                    "helm.tools class could not be instantiated at index {$index}: {$class}.",
                    previous: $e,
                );
            }
        }

        return $tools;
    }

    /**
     * WordPress filter callback returning the booted Helm instance.
     *
     * @param mixed $instance Existing filtered value (ignored).
     *
     * @return Helm|null
     */
    public function filterHelmInstance(mixed $instance = null): ?Helm
    {
        return $this->helm;
    }

    /**
     * WordPress init action callback that validates registered tools.
     *
     * Triggers configuration exceptions early on invalid tool registrations.
     *
     * @return void
     */
    public function validateRegisteredToolsOnInit(): void
    {
        $this->collectTools();
    }
}
