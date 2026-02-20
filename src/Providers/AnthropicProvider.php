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
 * Anthropic Messages API provider.
 *
 * Maps Helm's ChatRequest to the Anthropic /v1/messages format and
 * normalises the response. Key differences from OpenAI: system message
 * is a top-level parameter, tool results use tool_result content blocks
 * inside user messages, and the response content is an array of blocks.
 *
 * Base URL is configurable for proxy endpoints.
 */
class AnthropicProvider implements ProviderContract
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $apiVersion;

    protected int $maxTokens;

    /**
     * @param TransportContract    $transport The HTTP transport to use.
     * @param array<string, mixed> $config    Provider config. Requires 'api_key'.
     */
    public function __construct(
        protected TransportContract $transport,
        array $config = [],
    ) {
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = rtrim($config['anthropic']['base_url'] ?? 'https://api.anthropic.com/v1', '/');
        $this->apiVersion = $config['anthropic']['api_version'] ?? '2023-06-01';
        $this->maxTokens = $config['anthropic']['max_tokens'] ?? 4096;

        if ($this->apiKey === '') {
            throw new ConfigurationException('Anthropic provider requires a non-empty api_key.');
        }
    }

    /**
     * Send a chat completion request to the Anthropic Messages API.
     *
     * @param ChatRequest $request The immutable chat request DTO.
     *
     * @return Response The normalised response.
     *
     * @throws ProviderException On API or transport errors.
     */
    public function chat(ChatRequest $request): Response
    {
        $url = "{$this->baseUrl}/messages";

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
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
     * Build the Anthropic API request body from a ChatRequest.
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<string, mixed> The Anthropic-formatted request body.
     */
    protected function buildBody(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->mapMessages($request),
        ];

        $system = $this->extractSystem($request);
        if ($system !== null) {
            $body['system'] = $system;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
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
     * Map ChatRequest messages to Anthropic message format.
     *
     * Excludes system messages (handled as top-level parameter).
     *
     * @param ChatRequest $request The chat request DTO.
     *
     * @return array<int, array<string, mixed>> Anthropic-formatted messages.
     */
    protected function mapMessages(ChatRequest $request): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                continue;
            }

            if ($message->toolCalls !== null) {
                $content = [];

                if ($message->content !== '') {
                    $content[] = ['type' => 'text', 'text' => $message->content];
                }

                foreach ($message->toolCalls as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->id,
                        'name' => $toolCall->name,
                        'input' => $toolCall->arguments,
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => $content];
            } elseif ($message->toolCallId !== null) {
                $messages[] = [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'tool_result',
                            'tool_use_id' => $message->toolCallId,
                            'content' => $message->content,
                        ],
                    ],
                ];
            } else {
                $messages[] = [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            }
        }

        return $messages;
    }

    /**
     * Map normalised tool definitions to Anthropic tool format.
     *
     * @param array<int, array<string, mixed>> $tools Normalised tool definitions.
     *
     * @return array<int, array<string, mixed>> Anthropic-formatted tools.
     */
    protected function mapTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'] ?? '',
            'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
        ], $tools);
    }

    /**
     * Parse the Anthropic response into a Helm Response.
     *
     * @param array<string, mixed> $data The raw API response.
     *
     * @return Response
     */
    protected function parseResponse(array $data): Response
    {
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            }

            if (($block['type'] ?? '') === 'tool_use') {
                if (
                    !isset($block['id'], $block['name'])
                    || !is_string($block['id'])
                    || !is_string($block['name'])
                ) {
                    throw new ProviderException('Malformed tool call in Anthropic response.');
                }

                $arguments = $block['input'] ?? [];
                if (!is_array($arguments)) {
                    throw new ProviderException('Malformed tool call arguments in Anthropic response.');
                }

                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    arguments: $arguments,
                );
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
