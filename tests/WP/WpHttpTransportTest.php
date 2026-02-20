<?php

namespace PressGang\Helm\Tests\WP;

use PressGang\Helm\Exceptions\ProviderException;
use PressGang\Helm\Tests\Stubs\WpHttp;
use PressGang\Helm\Tests\Stubs\WpHooks;
use PressGang\Helm\Tests\TestCase;
use PressGang\Helm\WP\WpHttpTransport;

class WpHttpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpHooks::reset();
        WpHttp::reset();
    }

    public function test_send_builds_request_and_decodes_json(): void
    {
        WpHttp::$response = [
            'response' => ['code' => 200],
            'body' => '{"ok":true}',
        ];

        $transport = new WpHttpTransport(['timeout' => 45]);
        $result = $transport->send(
            'POST',
            'https://api.example.com/v1/chat',
            ['Authorization' => 'Bearer sk-test'],
            ['model' => 'gpt-4o'],
        );

        $this->assertSame(['ok' => true], $result);
        $this->assertCount(1, WpHttp::$requests);
        $this->assertSame('https://api.example.com/v1/chat', WpHttp::$requests[0]['url']);
        $this->assertSame('POST', WpHttp::$requests[0]['args']['method']);
        $this->assertSame(45, WpHttp::$requests[0]['args']['timeout']);
        $this->assertSame('Content-Type: application/json', 'Content-Type: ' . WpHttp::$requests[0]['args']['headers']['Content-Type']);
        $this->assertSame('Bearer sk-test', WpHttp::$requests[0]['args']['headers']['Authorization']);
        $this->assertSame('{"model":"gpt-4o"}', WpHttp::$requests[0]['args']['body']);
    }

    public function test_send_omits_body_when_empty(): void
    {
        WpHttp::$response = [
            'response' => ['code' => 200],
            'body' => '{"ok":true}',
        ];

        $transport = new WpHttpTransport();
        $transport->send('GET', 'https://api.example.com/v1/models');

        $this->assertArrayNotHasKey('body', WpHttp::$requests[0]['args']);
    }

    public function test_send_throws_on_wp_error(): void
    {
        WpHttp::$response = new \WP_Error('http_request_failed', 'Connection refused');

        $transport = new WpHttpTransport();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Connection refused');

        $transport->send('POST', 'https://api.example.com/v1/chat');
    }

    public function test_send_throws_on_http_error_with_json_message(): void
    {
        WpHttp::$response = [
            'response' => ['code' => 401],
            'body' => '{"error":{"message":"Invalid API key"}}',
        ];

        $transport = new WpHttpTransport();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid API key');
        $this->expectExceptionCode(401);

        $transport->send('POST', 'https://api.example.com/v1/chat');
    }

    public function test_send_throws_on_http_error_with_non_json_body(): void
    {
        WpHttp::$response = [
            'response' => ['code' => 502],
            'body' => '<html>Bad Gateway</html>',
        ];

        $transport = new WpHttpTransport();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HTTP 502');
        $this->expectExceptionCode(502);

        $transport->send('POST', 'https://api.example.com/v1/chat');
    }

    public function test_send_throws_on_invalid_json_success_response(): void
    {
        WpHttp::$response = [
            'response' => ['code' => 200],
            'body' => 'not-json',
        ];

        $transport = new WpHttpTransport();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $transport->send('POST', 'https://api.example.com/v1/chat');
    }
}
