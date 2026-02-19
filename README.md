# 🛞 PressGang Helm

Helm is structured, provider-agnostic AI orchestration for WordPress in the PressGang ecosystem. Helm gives WordPress themes and plugins a clean, testable API for chat completions, tool execution, and structured output — without coupling to any single AI provider. Take the helm 🛞. 

## Installation

```bash
composer require pressgang-wp/pressgang-helm
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
- **WordPress adapter** — WP HTTP transport, config resolution from constants/env/options, tool registry via filters
- **Structured output** — JSON Schema validation with typed exceptions
- **Streaming** — Event-driven token streaming with graceful fallback
- **Tool execution** — Provider-initiated tool calls with explicit registration and allow-listing

## Roadmap

1. Real provider driver (OpenAI via `TransportContract`)
2. WordPress adapter layer (`src/WP/`)
3. Structured output validation (`src/Schema/`)
4. Streaming support (`src/Streaming/`)
5. Tool execution pipeline
6. Embeddings and related primitives

## License

MIT
