<?php

namespace PressGang\Helm\Tests\Stubs;

use PressGang\Helm\Contracts\ToolContract;

class SecondConfigToolStub implements ToolContract
{
    public function name(): string
    {
        return 'second-config-tool';
    }

    public function description(): string
    {
        return 'Second config-driven test tool';
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
