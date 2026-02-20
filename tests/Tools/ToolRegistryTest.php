<?php

namespace PressGang\Helm\Tests\Tools;

use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\Exceptions\ToolExecutionException;
use PressGang\Helm\Tests\TestCase;
use PressGang\Helm\Tools\ToolRegistry;

class ToolRegistryTest extends TestCase
{
    protected function makeTool(string $name): ToolContract
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn($name);

        return $tool;
    }

    public function test_register_and_get(): void
    {
        $registry = new ToolRegistry();
        $tool = $this->makeTool('search');

        $registry->register($tool);

        $this->assertSame($tool, $registry->get('search'));
    }

    public function test_get_throws_on_unknown_tool(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('Tool not found: unknown');

        $registry->get('unknown');
    }

    public function test_all_returns_registered_tools(): void
    {
        $registry = new ToolRegistry();
        $search = $this->makeTool('search');
        $weather = $this->makeTool('weather');

        $registry->register($search);
        $registry->register($weather);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertContains($search, $all);
        $this->assertContains($weather, $all);
    }

    public function test_all_returns_empty_array_when_no_tools(): void
    {
        $registry = new ToolRegistry();

        $this->assertSame([], $registry->all());
    }

    public function test_has_returns_true_for_registered_tool(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('search'));

        $this->assertTrue($registry->has('search'));
    }

    public function test_has_returns_false_for_unknown_tool(): void
    {
        $registry = new ToolRegistry();

        $this->assertFalse($registry->has('unknown'));
    }

    public function test_register_returns_self_for_chaining(): void
    {
        $registry = new ToolRegistry();

        $result = $registry->register($this->makeTool('search'));

        $this->assertSame($registry, $result);
    }

    public function test_register_overwrites_duplicate_name(): void
    {
        $registry = new ToolRegistry();
        $first = $this->makeTool('search');
        $second = $this->makeTool('search');

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get('search'));
        $this->assertCount(1, $registry->all());
    }
}
