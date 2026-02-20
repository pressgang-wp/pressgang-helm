<?php

namespace PressGang\Helm\Providers;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;

/**
 * OpenAI chat completions provider.
 *
 * Maps Helm's ChatRequest to the OpenAI /v1/chat/completions API format
 * and normalises the response. Depends on a TransportContract for HTTP,
 * making it testable without real network calls.
 *
 * Base URL is configurable for Azure OpenAI or proxy endpoints.
 */
class OpenAiProvider implements ProviderContract
{
    protected string $apiKey;

    protected string $baseUrl;

    /**
     * @param TransportContract    $transport The HTTP transport to use.
     * @param array<string, mixed> $config    Provider config. Requires 'api_key'. Optional: 'openai.base_url'.
     */
    public function __construct(
        protected TransportContract $transport,
        array $config = [],
    ) {
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['openai']['base_url'] ?? 'https://api.openai.com/v1', '/');

        if ($this->apiKey === '') {
            throw new ConfigurationException('OpenAI provider requires a non-empty api_key.');
        }
    }

    /**
     * Send a chat completion request to the OpenAI API.
     *
     * @param ChatRequest $request The immutable chat request DTO.
     *
     * @return Response The normalised response.
     *
     * @throws ProviderException On API or transport errors.
     */
    public function chat(ChatRequest $request): Response
    {
        $url = "{$this->baseUrl}/chat/completions";

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
        ];

        $body = $this->buildBody($request);

        try {
            $data = $this->transport->send('POST', $url, $headers, $body);
        } catch (ProviderException $e) {
            throw new ProviderException(
                $this->sanitiseMessage($e->getMessage()),
                $e->getCode(),
                $e,
            );
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        return new Response(
            content: $content,
            raw: $data,
        );
    }

    /**
     * Build the OpenAI API request body from a ChatRequest.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<string, mixed> The OpenAI-formatted request body.
     */
    protected function buildBody(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'messages' => $this->mapMessages($request),
        ];

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        if ($request->schema !== null) {
            $schema = $request->schema;
            $schemaName = isset($schema['name']) && is_string($schema['name']) && $schema['name'] !== ''
                ? $schema['name']
                : 'response';
            $schemaStrict = array_key_exists('strict', $schema) ? (bool) $schema['strict'] : true;
            unset($schema['name'], $schema['strict']);

            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => $schemaStrict,
                    'schema' => $schema,
                ],
            ];
        }

        return $body;
    }

    /**
     * Map ChatRequest messages to OpenAI message format.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<int, array<string, string>> OpenAI-formatted messages.
     */
    protected function mapMessages(ChatRequest $request): array
    {
        return array_map(
            fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ],
            $request->messages,
        );
    }

    /**
     * Strip API key from error messages to prevent secret leakage.
     *
     * @param string $message The original error message.
     *
     * @return string The sanitised message.
     */
    protected function sanitiseMessage(string $message): string
    {
        if ($this->apiKey === '') {
            return $message;
        }

        return str_replace($this->apiKey, '[REDACTED]', $message);
    }
}
