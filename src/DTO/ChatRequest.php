<?php

namespace PressGang\Helm\DTO;

/**
 * Immutable value object representing a complete chat completion request.
 *
 * Built by ChatBuilder and passed to a ProviderContract for execution.
 * Contains all intent (messages, model settings, tools, schema) needed
 * to make a provider call. Can be serialised for debugging and logging.
 */
class ChatRequest
{
    /**
     * @param array<int, Message>       $messages    Ordered list of messages.
     * @param string                    $model       The model identifier.
     * @param float|null                $temperature Sampling temperature (null = provider default).
     * @param array<int, array>         $tools       Tool definitions for the provider.
     * @param array<string, mixed>|null $schema      JSON Schema for structured output validation.
     */
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly ?float $temperature = null,
        public readonly array $tools = [],
        public readonly ?array $schema = null,
    ) {}

    /**
     * Convert the request to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'messages' => array_map(fn (Message $m) => $m->toArray(), $this->messages),
            'model' => $this->model,
        ];

        if ($this->temperature !== null) {
            $data['temperature'] = $this->temperature;
        }

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        if ($this->schema !== null) {
            $data['schema'] = $this->schema;
        }

        return $data;
    }
}
