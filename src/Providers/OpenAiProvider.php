<?php

namespace PressGang\Helm\Providers;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
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

        return $this->parseResponse($data);
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
            $body['tools'] = $this->mapTools($request->tools);
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
     * Handles regular messages, assistant messages with tool calls,
     * and tool result messages.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<int, array<string, mixed>> OpenAI-formatted messages.
     */
    protected function mapMessages(ChatRequest $request): array
    {
        $mapped = [];

        foreach ($request->messages as $message) {
            if ($message->toolCalls !== null) {
                $mapped[] = [
                    'role' => 'assistant',
                    'content' => $message->content !== '' ? $message->content : null,
                    'tool_calls' => array_map(fn (ToolCall $tc) => [
                        'id' => $tc->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $tc->name,
                            'arguments' => json_encode($tc->arguments),
                        ],
                    ], $message->toolCalls),
                ];
            } elseif ($message->toolCallId !== null) {
                $mapped[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->toolCallId,
                    'content' => $message->content,
                ];
            } else {
                $mapped[] = [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            }
        }

        return $mapped;
    }

    /**
     * Map normalised tool definitions to OpenAI tool format.
     *
     * @param array<int, array<string, mixed>> $tools Normalised tool definitions.
     *
     * @return array<int, array<string, mixed>> OpenAI-formatted tools.
     */
    protected function mapTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
            ],
        ], $tools);
    }

    /**
     * Parse the OpenAI response into a Helm Response.
     *
     * @param array<string, mixed> $data The raw API response.
     *
     * @return Response
     */
    protected function parseResponse(array $data): Response
    {
        $message = $data['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? '';
        $toolCalls = null;

        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $toolCalls = [];

            foreach ($message['tool_calls'] as $tc) {
                if (
                    !is_array($tc)
                    || !isset($tc['id'], $tc['function'])
                    || !is_string($tc['id'])
                    || !is_array($tc['function'])
                    || !isset($tc['function']['name'])
                    || !is_string($tc['function']['name'])
                ) {
                    throw new ProviderException('Malformed tool call in OpenAI response.');
                }

                $decodedArguments = json_decode($tc['function']['arguments'] ?? '{}', true);
                $arguments = is_array($decodedArguments) ? $decodedArguments : [];

                $toolCalls[] = new ToolCall(
                    id: $tc['id'],
                    name: $tc['function']['name'],
                    arguments: $arguments,
                );
            }
        }

        return new Response(
            content: $content,
            raw: $data,
            toolCalls: $toolCalls,
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
