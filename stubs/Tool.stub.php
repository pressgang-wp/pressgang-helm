<?php

namespace App\Tools;

use PressGang\Helm\Contracts\ToolContract;

/**
 * Stub: Tool definition.
 *
 * Tools must be explicitly registered and are invoked by the provider
 * during a chat completion. Inputs and outputs are arrays only.
 */
class DummyTool implements ToolContract
{
    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'dummy_tool';
    }

    /**
     * {@inheritdoc}
     */
    public function description(): string
    {
        return 'A placeholder tool for scaffolding.';
    }

    /**
     * {@inheritdoc}
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The input query.'],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $input): array
    {
        return ['result' => 'Handled: ' . ($input['query'] ?? '')];
    }
}
