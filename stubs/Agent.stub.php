<?php

namespace App\Agents;

use PressGang\Helm\Helm;

/**
 * Stub: Agent definition.
 *
 * An agent combines a system prompt, tool set, and model config
 * into a reusable unit. This stub provides the scaffolding shape.
 */
class DummyAgent
{
    public function __construct(
        protected Helm $helm,
    ) {}

    /**
     * Run the agent with the given user input.
     *
     * @param string $input The user message.
     *
     * @return string The agent response content.
     */
    public function run(string $input): string
    {
        return $this->helm
            ->chat()
            ->system('You are a helpful assistant.')
            ->user($input)
            ->send()
            ->content;
    }
}
