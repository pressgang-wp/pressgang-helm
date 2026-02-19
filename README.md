# 🛞 PressGang Helm

Helm is structured, provider-agnostic AI orchestration for WordPress in the PressGang ecosystem. Helm gives WordPress themes and plugins a clean, testable API for chat completions, tool execution, and structured output — without coupling to any single AI provider. Take the helm 🛞. 

## Installation

```bash
composer require pressgang-wp/helm
```

## Quickstart

```php
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\FakeProvider;

// Use FakeProvider for now — real providers (OpenAI, Anthropic) coming soon.
$helm = Helm::make(new FakeProvider(), [
    'model' => 'gpt-4o',
]);

$response = $helm
    ->chat()
    ->system('You are a helpful assistant.')
    ->user('What is WordPress?')
    ->send();

echo $response->content;
```

## Status

Helm is in early development. The core builder/DTO/contract surface is taking shape. The following are in progress:

- **Provider drivers** — OpenAI and Anthropic via transport abstraction
- **Tool execution** — Provider-initiated tool calls with explicit registration and allow-listing
- **Structured output** — JSON Schema validation with typed exceptions
- **PressGang adapter** — WP HTTP transport, config via PressGang's `Config`, tool registry via filters
- **Conversation memory** — Multi-turn chat with WordPress-native storage
- **Embeddings** — Vector generation with WP object cache support

## Roadmap

1. Transport layer + OpenAI provider
2. Anthropic provider + tool execution loop
3. Structured output validation
4. PressGang adapter layer (`src/WP/`)
5. Conversation memory
6. Embeddings
7. Agent pattern (reusable agent classes)

Streaming is deferred — most Helm use cases are server-side orchestration where the full response is needed before proceeding. WordPress's HTTP layer (`wp_remote_post()`) also has no native SSE support.

## License

MIT
