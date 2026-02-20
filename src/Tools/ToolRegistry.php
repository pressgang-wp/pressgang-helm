<?php

namespace PressGang\Helm\Tools;

use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\Exceptions\ToolExecutionException;

/**
 * Simple, explicit registry for tool implementations.
 *
 * Tools must be explicitly registered before they can be resolved by name.
 * No auto-discovery, no magic. In the WP adapter, this is populated via
 * apply_filters('pressgang_helm_tools', []).
 */
class ToolRegistry
{
    /** @var array<string, ToolContract> */
    protected array $tools = [];

    /**
     * Register a tool implementation.
     *
     * @param ToolContract $tool The tool to register.
     *
     * @return $this
     */
    public function register(ToolContract $tool): static
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /**
     * Get a tool by name.
     *
     * @param string $name The tool name.
     *
     * @return ToolContract
     *
     * @throws ToolExecutionException If the tool is not registered.
     */
    public function get(string $name): ToolContract
    {
        if (!isset($this->tools[$name])) {
            throw new ToolExecutionException("Tool not found: {$name}");
        }

        return $this->tools[$name];
    }

    /**
     * Get all registered tools.
     *
     * @return array<int, ToolContract>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Check if a tool is registered.
     *
     * @param string $name The tool name.
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
}
