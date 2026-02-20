<?php

namespace PressGang\Helm\WP;

use PressGang\Helm\Chat\ChatBuilder;
use PressGang\Helm\Helm;

/**
 * Helm variant for WordPress that auto-applies registered tools.
 *
 * Resolves tools via a callback on each chat() call so theme/plugin
 * registrations from pressgang_helm_tools are reflected without
 * requiring callers to manually attach tools every time.
 */
class WordPressHelm extends Helm
{
    /**
     * @param \Closure(): array<int, \PressGang\Helm\Contracts\ToolContract> $toolResolver
     */
    public function __construct(
        \PressGang\Helm\Contracts\ProviderContract $provider,
        array $config,
        protected \Closure $toolResolver,
    ) {
        parent::__construct($provider, $config);
    }

    /**
     * Start building a chat request with WordPress-registered tools preloaded.
     */
    public function chat(): ChatBuilder
    {
        $builder = parent::chat();
        $tools = ($this->toolResolver)();

        if ($tools !== []) {
            $builder->tools($tools);
        }

        return $builder;
    }
}
