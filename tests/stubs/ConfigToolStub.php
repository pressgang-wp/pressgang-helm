<?php

namespace PressGang\Helm\Tests\Stubs;

use PressGang\Helm\Contracts\ToolContract;

class ConfigToolStub implements ToolContract
{
    public function name(): string
    {
        return 'config-tool';
    }

    public function description(): string
    {
        return 'Config-driven test tool';
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
