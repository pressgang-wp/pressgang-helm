<?php

namespace PressGang\Helm\Tests\WP;

use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\AnthropicProvider;
use PressGang\Helm\Providers\OpenAiProvider;
use PressGang\Helm\Tests\Stubs\ConfigToolStub;
use PressGang\Helm\Tests\Stubs\NonInstantiableConfigToolStub;
use PressGang\Helm\Tests\Stubs\SecondConfigToolStub;
use PressGang\Helm\Tests\Stubs\WpHooks;
use PressGang\Helm\Tests\TestCase;
use PressGang\Helm\WP\HelmServiceProvider;
use PressGang\Helm\WP\HookAwareProvider;

class HelmServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpHooks::reset();
    }

    protected function validConfig(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'api_key' => 'sk-test-key',
            'timeout' => 30,
        ], $overrides);
    }

    public function test_resolve_provider_returns_openai_provider(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $config = $this->validConfig(['provider' => 'openai']);

        $provider = HelmServiceProvider::resolveProvider($config, $transport);

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
    }

    public function test_resolve_provider_returns_anthropic_provider(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $config = $this->validConfig(['provider' => 'anthropic']);

        $provider = HelmServiceProvider::resolveProvider($config, $transport);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_resolve_provider_defaults_to_openai(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $config = $this->validConfig();
        unset($config['provider']);

        $provider = HelmServiceProvider::resolveProvider($config, $transport);

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
    }

    public function test_resolve_provider_throws_on_unknown_provider(): void
    {
        $transport = $this->createMock(TransportContract::class);
        $config = $this->validConfig(['provider' => 'gemini']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown provider: gemini');

        HelmServiceProvider::resolveProvider($config, $transport);
    }

    public function test_boot_creates_helm_instance(): void
    {
        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->assertInstanceOf(Helm::class, $sp->helm());
    }

    public function test_boot_wraps_provider_with_hook_aware_provider(): void
    {
        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->assertInstanceOf(HookAwareProvider::class, $sp->helm()->provider());
    }

    public function test_boot_skips_when_api_key_is_empty(): void
    {
        $sp = new HelmServiceProvider($this->validConfig(['api_key' => '']));
        $sp->boot();

        $this->assertNull($sp->helm());
    }

    public function test_boot_skips_when_api_key_is_missing(): void
    {
        $config = $this->validConfig();
        unset($config['api_key']);

        $sp = new HelmServiceProvider($config);
        $sp->boot();

        $this->assertNull($sp->helm());
    }

    public function test_boot_registers_helm_instance_filter(): void
    {
        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->assertArrayHasKey('pressgang_helm_instance', WpHooks::$filters);

        $result = \apply_filters('pressgang_helm_instance', null);
        $this->assertInstanceOf(Helm::class, $result);
        $this->assertSame($sp->helm(), $result);
    }

    public function test_boot_registers_tools_collector_on_init(): void
    {
        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->assertArrayHasKey('init', WpHooks::$actions);
    }

    public function test_boot_collects_tools_from_filter(): void
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn('search');
        $tool->method('description')->willReturn('Search');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        \add_filter('pressgang_helm_tools', fn (array $tools) => array_merge($tools, [$tool]));

        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        // Trigger the init action to run tool collection
        \do_action('init');

        // No exception means validation passed
        $this->assertTrue(true);
    }

    public function test_boot_applies_registered_tools_to_chat_requests(): void
    {
        $tool = $this->createMock(ToolContract::class);
        $tool->method('name')->willReturn('search');
        $tool->method('description')->willReturn('Search');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        \add_filter('pressgang_helm_tools', fn (array $tools) => array_merge($tools, [$tool]));

        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $request = $sp->helm()
            ->chat()
            ->user('Hi')
            ->toRequest();

        $this->assertCount(1, $request->tools);
        $this->assertSame('search', $request->tools[0]['name']);
    }

    public function test_boot_loads_tools_from_config(): void
    {
        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => [ConfigToolStub::class],
        ]));
        $sp->boot();

        $request = $sp->helm()
            ->chat()
            ->user('Hi')
            ->toRequest();

        $this->assertCount(1, $request->tools);
        $this->assertSame('config-tool', $request->tools[0]['name']);
    }

    public function test_boot_merges_config_tools_with_filter_tools(): void
    {
        $filterTool = $this->createMock(ToolContract::class);
        $filterTool->method('name')->willReturn('filter-tool');
        $filterTool->method('description')->willReturn('Filter');
        $filterTool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        \add_filter('pressgang_helm_tools', fn (array $tools) => array_merge($tools, [$filterTool]));

        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => [ConfigToolStub::class],
        ]));
        $sp->boot();

        $request = $sp->helm()
            ->chat()
            ->user('Hi')
            ->toRequest();

        $this->assertCount(2, $request->tools);
        $this->assertSame('config-tool', $request->tools[0]['name']);
        $this->assertSame('filter-tool', $request->tools[1]['name']);
    }

    public function test_boot_throws_when_config_tool_class_is_missing(): void
    {
        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => ['Not\\A\\Real\\ToolClass'],
        ]));
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('helm.tools class does not exist');

        \do_action('init');
    }

    public function test_boot_throws_when_config_tool_is_not_class_string(): void
    {
        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => [new SecondConfigToolStub()],
        ]));
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be a class-string');

        \do_action('init');
    }

    public function test_boot_throws_when_config_tools_is_not_array(): void
    {
        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => 'invalid',
        ]));
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('helm.tools config must be an array');

        \do_action('init');
    }

    public function test_boot_throws_when_config_tool_cannot_be_instantiated(): void
    {
        $sp = new HelmServiceProvider($this->validConfig([
            'tools' => [NonInstantiableConfigToolStub::class],
        ]));
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('helm.tools class could not be instantiated');

        \do_action('init');
    }

    public function test_boot_throws_on_invalid_tool(): void
    {
        \add_filter('pressgang_helm_tools', fn (array $tools) => ['not-a-tool']);

        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must implement ToolContract');

        \do_action('init');
    }

    public function test_boot_throws_when_tool_filter_returns_non_array(): void
    {
        \add_filter('pressgang_helm_tools', fn (array $tools) => 'invalid');

        $sp = new HelmServiceProvider($this->validConfig());
        $sp->boot();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must return an array');

        \do_action('init');
    }

    public function test_boot_does_not_register_hooks_when_skipped(): void
    {
        $sp = new HelmServiceProvider($this->validConfig(['api_key' => '']));
        $sp->boot();

        $this->assertEmpty(WpHooks::$filters);
        $this->assertEmpty(WpHooks::$actions);
    }
}
