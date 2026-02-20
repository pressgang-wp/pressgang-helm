<?php

namespace PressGang\Helm\Tests\Stubs;

use PressGang\Helm\Contracts\ToolContract;

class NonInstantiableConfigToolStub implements ToolContract
{
    public function __construct(string $required)
    {
    }

    public function name(): string
    {
        return 'non-instantiable-config-tool';
    }

    public function description(): string
    {
        return 'Non-instantiable config-driven test tool';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $input): array
    {
        return ['ok' => true];
    }
}
