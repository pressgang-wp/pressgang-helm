# 🛞 PressGang Helm

**Helm is the AI orchestration layer for PressGang.**  
Provider-agnostic, WordPress-friendly, and built for production workflows: chat, tools, and schema-constrained output without locking your stack to a single model vendor.
Take the helm of your AI features without drifting into provider lock-in.

## ✅ Requirements

- PHP `^8.3`

## 🚀 Installation

```bash
composer require pressgang-wp/helm
```

## 🌊 Why Helm

- ⚓ Provider-agnostic core (`OpenAI`, `Anthropic`, `Fake`)
- 🧰 Tool execution loop with explicit contracts and max-step guardrails
- 🧱 Structured output with JSON schema validation + repair retries
- 🧪 High test coverage and deterministic DTO contracts
- 🧩 WordPress adapter scaffolding ready for PressGang integration
- 🛟 Clear failure modes with typed exceptions for safer debugging

## 📦 Current Status

Implemented:

- Core public API: `Helm`, `ChatBuilder`, DTOs, contracts
- Providers: `OpenAiProvider`, `AnthropicProvider`, `FakeProvider`
- WordPress transport: `WpHttpTransport`
- Tool execution loop:
  - register tools via `->tools([...])` (`ToolContract` implementations)
  - provider tool-call parsing (`ToolCall`)
  - tool result replay into conversation (`ToolResult`)
  - `->maxSteps()` guard
- Structured output:
  - `->jsonSchema([...])` validation
  - `StructuredResponse` return type
  - `SchemaValidationException` with validation errors/raw output/request context
  - `->repair(n)` retry with validation feedback
- WordPress adapter scaffolding:
  - `HelmServiceProvider`
  - `HookAwareProvider` (`pressgang_helm_request`, `pressgang_helm_response`, `pressgang_helm_error`)

Not implemented yet (on roadmap):

- Streaming API
- Embeddings API
- Conversation memory store
- Agent abstraction
- Provider failover/retry policy
- Complete PressGang production wiring hardening

## 🗺️ What This Means Right Now

You can already build real provider-backed AI workflows in PHP with typed requests/responses, tool calls, and validated JSON outputs.  
If you’re building AI features in PressGang, Helm gives you the stable orchestration surface now while the remaining adapter/runtime layers are finished.
In short: you can ship today, then chart the next leg as the roadmap lands.

## ⚡ Quickstart (Fake Provider)

New here? Start with `FakeProvider` to learn the API shape before wiring real credentials.

```php
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\FakeProvider;

$helm = Helm::make(new FakeProvider(), ['model' => 'gpt-4o']);

$response = $helm
    ->chat()
    ->system('You are concise.')
    ->user('What is WordPress?')
    ->send();

echo $response->content;
```

## 🤖 Quickstart (OpenAI Provider)

When you’re ready for live responses, swap in a real provider and keep the same builder flow.

```php
use PressGang\Helm\Helm;
use PressGang\Helm\Providers\OpenAiProvider;
use PressGang\Helm\WP\WpHttpTransport;

$config = [
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'api_key' => 'sk-...',
    'timeout' => 30,
    'openai' => [
        'base_url' => 'https://api.openai.com/v1',
    ],
];

$transport = new WpHttpTransport($config);
$provider = new OpenAiProvider($transport, $config);
$helm = Helm::make($provider, $config);

$response = $helm->chat()->user('Say hello in one sentence.')->send();
echo $response->content;
```

## 🛠️ Tool Loop Example

Tools are explicit and opt-in: you register them, Helm executes them, and the model gets the results fed back in.

```php
use PressGang\Helm\Contracts\ToolContract;

class WeatherTool implements ToolContract
{
    public function name(): string { return 'get_weather'; }
    public function description(): string { return 'Get weather by city'; }
    public function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];
    }
    public function handle(array $input): array {
        return ['city' => $input['city'] ?? '', 'temp_c' => 21];
    }
}

$response = $helm
    ->chat()
    ->user('What is the weather in London?')
    ->tools([new WeatherTool()])
    ->maxSteps(5)
    ->send();
```

## 🧾 Structured Output Example

Need reliable payloads for downstream code? Add a schema and keep your output on a steady heading.

```php
$response = $helm
    ->chat()
    ->user('Rate this code from 1-10 and explain why.')
    ->jsonSchema([
        'type' => 'object',
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'feedback' => ['type' => 'string'],
        ],
        'required' => ['score', 'feedback'],
    ])
    ->repair(1)
    ->send(); // StructuredResponse

echo $response['score'];
echo $response['feedback'];
```

## 🧪 Testing

Run test suite:

```bash
vendor/bin/phpunit -c phpunit.xml.dist --testdox
```

## 🧭 Roadmap

See `docs/roadmap/` for milestone docs.

If you’re wondering what to build next, start with provider + schema-backed flows, then add adapter/runtime integrations.

## 📄 License

MIT
