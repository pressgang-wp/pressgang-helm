<?php

namespace PressGang\Helm\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\Exceptions\ConfigurationException;

/**
 * Fluent builder for constructing and sending chat completion requests.
 *
 * Collects messages, model settings, tools, and schema constraints,
 * then produces an immutable ChatRequest and dispatches it to a provider.
 * ChatBuilder is the primary user-facing API for composing AI requests.
 */
class ChatBuilder
{
    /** @var array<int, Message> */
    protected array $messages = [];

    protected ?string $model;

    protected ?float $temperature = null;

    /** @var array<int, array<string, mixed>> */
    protected array $tools = [];

    /** @var array<string, mixed>|null */
    protected ?array $schema = null;

    /**
     * @param ProviderContract $provider The provider to dispatch requests to.
     * @param string|null      $model    Default model from config (can be overridden via ->model()).
     */
    public function __construct(
        protected ProviderContract $provider,
        ?string $model = null,
    ) {
        $this->model = $model;
    }

    /**
     * Add a system message to the conversation.
     *
     * @param string $text The system instruction content.
     *
     * @return $this
     */
    public function system(string $text): static
    {
        $this->messages[] = new Message(role: 'system', content: $text);

        return $this;
    }

    /**
     * Add a user message to the conversation.
     *
     * @param string $text The user message content.
     *
     * @return $this
     */
    public function user(string $text): static
    {
        $this->messages[] = new Message(role: 'user', content: $text);

        return $this;
    }

    /**
     * Set the model to use for this request.
     *
     * @param string $model The model identifier (e.g. 'gpt-4o', 'claude-sonnet-4-20250514').
     *
     * @return $this
     */
    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set the sampling temperature.
     *
     * @param float $value Temperature value (typically 0.0 to 2.0).
     *
     * @return $this
     */
    public function temperature(float $value): static
    {
        $this->temperature = $value;

        return $this;
    }

    /**
     * Set the tools available for this request.
     *
     * @param array<int, array<string, mixed>> $tools Tool definitions.
     *
     * @return $this
     */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Set a JSON Schema for structured output validation.
     *
     * @param array<string, mixed> $schema A valid JSON Schema definition.
     *
     * @return $this
     */
    public function jsonSchema(array $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Build the immutable ChatRequest and send it to the provider.
     *
     * @return Response The normalised provider response.
     *
     * @throws ConfigurationException If no model has been set.
     */
    public function send(): Response
    {
        $request = $this->toRequest();

        return $this->provider->chat($request);
    }

    /**
     * Build the immutable ChatRequest from the current builder state.
     *
     * @return ChatRequest
     *
     * @throws ConfigurationException If no model has been set.
     */
    public function toRequest(): ChatRequest
    {
        if ($this->model === null) {
            throw new ConfigurationException(
                'No model specified. Call ->model() or provide a model in config.'
            );
        }

        return new ChatRequest(
            messages: $this->messages,
            model: $this->model,
            temperature: $this->temperature,
            tools: $this->tools,
            schema: $this->schema,
        );
    }
}
