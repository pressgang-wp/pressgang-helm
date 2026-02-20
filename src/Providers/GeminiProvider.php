<?php

namespace PressGang\Helm\Providers;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\TransportContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ProviderException;

/**
 * Google Gemini generateContent provider.
 *
 * Maps Helm's ChatRequest to the Gemini /models/{model}:generateContent API
 * format and normalises the response. Key differences from OpenAI/Anthropic:
 * model is in the URL path (not body), system message is a top-level
 * systemInstruction field, assistant role is 'model', temperature and schema
 * live inside generationConfig, and tool calls use functionCall/functionResponse.
 *
 * Gemini does not return tool call IDs, so synthetic deterministic IDs are
 * generated in the format 'gemini_{name}_{index}' for ChatBuilder correlation.
 *
 * Base URL is configurable for proxy endpoints.
 */
class GeminiProvider implements ProviderContract
{
    protected string $apiKey;

    protected string $baseUrl;

    /**
     * @param TransportContract    $transport The HTTP transport to use.
     * @param array<string, mixed> $config    Provider config. Requires 'api_key'. Optional: 'gemini.base_url'.
     */
    public function __construct(
        protected TransportContract $transport,
        array $config = [],
    ) {
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['gemini']['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');

        if ($this->apiKey === '') {
            throw new ConfigurationException('Gemini provider requires a non-empty api_key.');
        }
    }

    /**
     * Send a chat request to the Gemini generateContent API.
     *
     * @param ChatRequest $request The immutable chat request DTO.
     *
     * @return Response The normalised response.
     *
     * @throws ProviderException On API or transport errors.
     */
    public function chat(ChatRequest $request): Response
    {
        $url = "{$this->baseUrl}/models/{$request->model}:generateContent";

        $headers = [
            'x-goog-api-key' => $this->apiKey,
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
     * Build the Gemini API request body from a ChatRequest.
     *
     * Model is not included — it is part of the URL path.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<string, mixed> The Gemini-formatted request body.
     */
    protected function buildBody(ChatRequest $request): array
    {
        $body = [
            'contents' => $this->mapMessages($request),
        ];

        $system = $this->extractSystem($request);
        if ($system !== null) {
            $body['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        $generationConfig = [];

        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }

        if ($request->schema !== null) {
            $schema = $request->schema;
            unset($schema['name'], $schema['strict']);

            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $schema;
        }

        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        if ($request->tools !== []) {
            $body['tools'] = $this->mapTools($request->tools);
        }

        return $body;
    }

    /**
     * Extract system message content from the request.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return string|null The system message content, or null if none.
     */
    protected function extractSystem(ChatRequest $request): ?string
    {
        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                return $message->content;
            }
        }

        return null;
    }

    /**
     * Map ChatRequest messages to Gemini contents format.
     *
     * Excludes system messages (handled as top-level systemInstruction).
     * Maps assistant role to 'model', wraps content in parts arrays,
     * and converts tool calls/results to functionCall/functionResponse.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<int, array<string, mixed>> Gemini-formatted contents.
     */
    protected function mapMessages(ChatRequest $request): array
    {
        $toolCallNames = [];
        foreach ($request->messages as $message) {
            if ($message->toolCalls !== null) {
                foreach ($message->toolCalls as $toolCall) {
                    $toolCallNames[$toolCall->id] = $toolCall->name;
                }
            }
        }

        $mapped = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                continue;
            }

            if ($message->toolCalls !== null) {
                $parts = [];

                if ($message->content !== '') {
                    $parts[] = ['text' => $message->content];
                }

                foreach ($message->toolCalls as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall->name,
                            'args' => $toolCall->arguments,
                        ],
                    ];
                }

                $mapped[] = ['role' => 'model', 'parts' => $parts];
            } elseif ($message->toolCallId !== null) {
                if (!isset($toolCallNames[$message->toolCallId])) {
                    throw new ProviderException(
                        "Cannot map tool result message: unknown tool_call_id '{$message->toolCallId}'.",
                    );
                }

                $name = $toolCallNames[$message->toolCallId];
                $decoded = json_decode($message->content, true);
                $responseData = is_array($decoded) ? $decoded : ['result' => $message->content];

                $mapped[] = [
                    'role' => 'user',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $name,
                                'response' => $responseData,
                            ],
                        ],
                    ],
                ];
            } else {
                $mapped[] = [
                    'role' => $message->role === 'assistant' ? 'model' : $message->role,
                    'parts' => [['text' => $message->content]],
                ];
            }
        }

        return $mapped;
    }

    /**
     * Map normalised tool definitions to Gemini tool format.
     *
     * Gemini wraps tools in a single object with a functionDeclarations array.
     * Empty properties use stdClass to ensure JSON encoding as {} not [].
     *
     * @param array<int, array<string, mixed>> $tools Normalised tool definitions.
     *
     * @return array<int, array<string, mixed>> Gemini-formatted tools.
     */
    protected function mapTools(array $tools): array
    {
        return [
            [
                'functionDeclarations' => array_map(function (array $tool): array {
                    $parameters = $tool['parameters'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ];

                    if (
                        is_array($parameters)
                        && array_key_exists('properties', $parameters)
                        && is_array($parameters['properties'])
                        && $parameters['properties'] === []
                    ) {
                        $parameters['properties'] = new \stdClass();
                    }

                    return [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'parameters' => $parameters,
                    ];
                }, $tools),
            ],
        ];
    }

    /**
     * Parse the Gemini response into a Helm Response.
     *
     * Generates synthetic tool call IDs in the format 'gemini_{name}_{index}'
     * since Gemini does not provide IDs for function calls.
     *
     * @param array<string, mixed> $data The raw API response.
     *
     * @return Response
     *
     * @throws ProviderException On malformed function call data.
     */
    protected function parseResponse(array $data): Response
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $content = '';
        $toolCalls = [];
        $toolCallIndex = 0;

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];

                if (
                    !is_array($fc)
                    || !isset($fc['name'])
                    || !is_string($fc['name'])
                ) {
                    throw new ProviderException('Malformed function call in Gemini response.');
                }

                $arguments = $fc['args'] ?? [];
                if (!is_array($arguments)) {
                    throw new ProviderException('Malformed function call arguments in Gemini response.');
                }

                $toolCalls[] = new ToolCall(
                    id: sprintf('gemini_%s_%d', $fc['name'], $toolCallIndex),
                    name: $fc['name'],
                    arguments: $arguments,
                );

                $toolCallIndex++;
            }
        }

        return new Response(
            content: $content,
            raw: $data,
            toolCalls: $toolCalls !== [] ? $toolCalls : null,
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
