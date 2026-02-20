<?php

namespace PressGang\Helm\Chat;

use PressGang\Helm\Contracts\ProviderContract;
use PressGang\Helm\Contracts\ToolContract;
use PressGang\Helm\DTO\ChatRequest;
use PressGang\Helm\DTO\Message;
use PressGang\Helm\DTO\Response;
use PressGang\Helm\DTO\ToolCall;
use PressGang\Helm\DTO\ToolResult;
use PressGang\Helm\Exceptions\ConfigurationException;
use PressGang\Helm\Exceptions\ToolExecutionException;
use Throwable;

/**
 * Fluent builder for constructing and sending chat completion requests.
 *
 * Collects messages, model settings, tools, and schema constraints,
 * then produces an immutable ChatRequest and dispatches it to a provider.
 * When tools are provided, runs an agentic loop that executes tool calls
 * and feeds results back until the model produces a final text response.
 */
class ChatBuilder
{
    /** @var array<int, Message> */
    protected array $messages = [];

    protected ?string $model;

    protected ?float $temperature = null;

    /** @var array<int, array<string, mixed>> Tool definitions for the request. */
    protected array $toolDefinitions = [];

    /** @var array<string, ToolContract> Tool implementations keyed by name. */
    protected array $toolObjects = [];

    /** @var array<string, mixed>|null */
    protected ?array $schema = null;

    protected ?int $maxSteps = null;

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
     * Accepts an array of ToolContract implementations. The builder converts
     * them to provider-agnostic definitions for the request and keeps the
     * implementations for tool execution in the agentic loop.
     *
     * @param array<int, ToolContract> $tools Tool implementations.
     *
     * @return $this
     *
     * @throws ToolExecutionException If duplicate tool names are provided.
     */
    public function tools(array $tools): static
    {
        $this->toolDefinitions = [];
        $this->toolObjects = [];

        foreach ($tools as $tool) {
            if (isset($this->toolObjects[$tool->name()])) {
                throw new ToolExecutionException("Duplicate tool name: {$tool->name()}");
            }

            $this->toolObjects[$tool->name()] = $tool;
            $this->toolDefinitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ];
        }

        return $this;
    }

    /**
     * Set the maximum number of tool execution steps.
     *
     * @param int $steps Maximum loop iterations before stopping.
     *
     * @return $this
     */
    public function maxSteps(int $steps): static
    {
        $this->maxSteps = $steps;

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
     * When tools are registered, runs an agentic loop: if the provider
     * returns tool calls, executes them, appends results, and re-sends
     * until the model produces a text response or max steps is reached.
     *
     * @return Response The normalised provider response.
     *
     * @throws ConfigurationException  If no model has been set.
     * @throws ToolExecutionException  If a tool fails or is not found.
     */
    public function send(): Response
    {
        $request = $this->toRequest();
        $response = $this->provider->chat($request);
        $steps = 0;
        $maxSteps = $this->maxSteps ?? max(count($this->toolDefinitions) * 2, 5);

        while ($response->hasToolCalls() && $this->toolObjects !== [] && $steps < $maxSteps) {
            $results = $this->executeTools($response->toolCalls);
            $this->appendAssistantMessage($response);
            $this->appendToolResults($results);

            $request = $this->toRequest();
            $response = $this->provider->chat($request);
            $steps++;
        }

        return $response;
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
            tools: $this->toolDefinitions,
            schema: $this->schema,
        );
    }

    /**
     * Execute tool calls and return results.
     *
     * @param array<int, ToolCall> $toolCalls The tool calls from the provider response.
     *
     * @return array<int, ToolResult>
     *
     * @throws ToolExecutionException If a tool is not found or fails.
     */
    protected function executeTools(array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            if (!isset($this->toolObjects[$toolCall->name])) {
                throw new ToolExecutionException("Tool not found: {$toolCall->name}");
            }

            $tool = $this->toolObjects[$toolCall->name];
            try {
                $result = $tool->handle($toolCall->arguments);
            } catch (Throwable $e) {
                throw new ToolExecutionException(
                    "Tool execution failed: {$toolCall->name}",
                    0,
                    $e,
                );
            }

            if (!is_array($result)) {
                throw new ToolExecutionException("Tool {$toolCall->name} must return an array result.");
            }

            $results[] = new ToolResult(
                toolCallId: $toolCall->id,
                name: $toolCall->name,
                result: $result,
            );
        }

        return $results;
    }

    /**
     * Append the assistant's response (including tool calls) as a message.
     *
     * @param Response $response The provider response with tool calls.
     */
    protected function appendAssistantMessage(Response $response): void
    {
        $this->messages[] = new Message(
            role: 'assistant',
            content: $response->content,
            toolCalls: $response->toolCalls,
        );
    }

    /**
     * Append tool results as individual messages.
     *
     * @param array<int, ToolResult> $results The tool execution results.
     */
    protected function appendToolResults(array $results): void
    {
        foreach ($results as $result) {
            $content = json_encode($result->result);
            if ($content === false) {
                throw new ToolExecutionException(
                    "Tool result for {$result->name} could not be JSON encoded."
                );
            }

            $this->messages[] = new Message(
                role: 'tool',
                content: $content,
                toolCallId: $result->toolCallId,
            );
        }
    }
}
