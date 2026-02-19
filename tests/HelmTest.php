<?php

namespace PressGang\Helm\Tests;

use PressGang\Helm\Chat\ChatBuilder;
use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\FakeProvider;

class HelmTest extends TestCase
{
    public function test_make_returns_helm_instance(): void
    {
        $helm = Helm::make(new FakeProvider());

        $this->assertInstanceOf(Helm::class, $helm);
    }

    public function test_make_accepts_config(): void
    {
        $helm = Helm::make(new FakeProvider(), ['model' => 'claude-sonnet-4-20250514']);

        $this->assertInstanceOf(Helm::class, $helm);
    }

    public function test_provider_returns_injected_instance(): void
    {
        $provider = new FakeProvider();
        $helm = Helm::make($provider);

        $this->assertSame($provider, $helm->provider());
    }

    public function test_provider_returns_provider_contract(): void
    {
        $helm = Helm::make(new FakeProvider());

        $this->assertInstanceOf(ProviderContract::class, $helm->provider());
    }

    public function test_chat_returns_chat_builder(): void
    {
        $helm = Helm::make(new FakeProvider(), ['model' => 'gpt-4o']);

        $this->assertInstanceOf(ChatBuilder::class, $helm->chat());
    }
}
