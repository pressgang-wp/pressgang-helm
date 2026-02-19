<?php

namespace PressGang\Helm\Contracts;

/**
 * Contract for HTTP transport implementations.
 *
 * Abstracts the HTTP layer so core and providers never depend
 * on a specific HTTP client. The WP adapter provides WpHttpTransport;
 * standalone usage can provide a Guzzle or cURL implementation.
 *
 * Implement this contract to add a new transport driver.
 */
interface TransportContract
{
    /**
     * Send an HTTP request and return the decoded response.
     *
     * @param string               $method  HTTP method (GET, POST, etc.).
     * @param string               $url     Fully qualified URL.
     * @param array<string, mixed> $headers Request headers.
     * @param array<string, mixed> $body    Request body (will be JSON-encoded by the transport).
     *
     * @return array<string, mixed> Decoded JSON response body.
     */
    public function send(string $method, string $url, array $headers = [], array $body = []): array;
}
