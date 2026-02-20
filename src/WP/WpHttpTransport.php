<?php

namespace PressGang\Helm\WP;

use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\Exceptions\ProviderException;

/**
 * WordPress HTTP API transport.
 *
 * Implements TransportContract via wp_remote_request(), delegating
 * all HTTP to WordPress's built-in HTTP layer. This transport is
 * only used inside the PressGang adapter — core never imports it.
 */
class WpHttpTransport implements TransportContract
{
    protected int $timeout;

    /**
     * @param array<string, mixed> $config Transport configuration. Supports 'timeout' (seconds).
     */
    public function __construct(array $config = [])
    {
        $this->timeout = $config['timeout'] ?? 30;
    }

    /**
     * Send an HTTP request via WordPress HTTP API and return the decoded JSON response.
     *
     * @param string               $method  HTTP method (GET, POST, etc.).
     * @param string               $url     Fully qualified URL.
     * @param array<string, mixed> $headers Request headers.
     * @param array<string, mixed> $body    Request body (will be JSON-encoded).
     *
     * @return array<string, mixed> Decoded JSON response body.
     *
     * @throws ProviderException On connection failure or HTTP error.
     */
    public function send(string $method, string $url, array $headers = [], array $body = []): array
    {
        $args = [
            'method' => $method,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'timeout' => $this->timeout,
        ];

        if ($body !== []) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new ProviderException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            $decoded = json_decode($responseBody, true);
            $message = is_array($decoded)
                ? ($decoded['error']['message'] ?? "HTTP {$code}")
                : "HTTP {$code}";
            throw new ProviderException($message, $code);
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            throw new ProviderException("Invalid JSON response from provider (HTTP {$code})");
        }

        return $decoded;
    }
}
