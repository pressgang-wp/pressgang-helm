# PressGang Helm — Agent Guide

All changes must conform to these rules. This is the single source of truth for the codebase.

## Project Status

Helm is in **early build** — the specification is stable but implementation is in progress. When generating code, follow the repo layout and contracts described below exactly. Do not invent patterns that diverge from this guide.

## What Helm Is

Helm is the AI orchestration layer for the PressGang ecosystem. It provides a WordPress-friendly, provider-agnostic PHP API for:

- Chat/completions-style requests
- Tool/function execution
- Structured output (JSON schema validation)
- Embeddings and related primitives
- Streaming (deferred — most use cases are server-side where full response is needed)

Helm is **infrastructure**, not a chatbot widget. It exists to make AI usage explicit, deterministic where possible, testable, and safe to run inside WordPress.

## What Helm Is Not

- Not a UI/chat plugin or prompt playground
- Not a provider SDK — it wraps providers behind contracts
- Not a framework container or service locator
- Not a background job system

Helm does not mutate WordPress state by default, silently execute tools, or make remote calls during bootstrap.

---

## Design Principles

### WordPress First

When diverging from patterns found in other frameworks (e.g. Laravel AI), prefer WordPress-native APIs:

- **HTTP**: `wp_remote_post()` / `wp_remote_get()` via `WpHttpTransport`, not Guzzle or cURL wrappers
- **Caching**: `wp_cache_get()` / `wp_cache_set()` and transients, not custom cache layers
- **Scheduling**: Action Scheduler or `wp_schedule_event()`, not custom job systems
- **Hooks**: `do_action()` / `apply_filters()` for extensibility, not custom event dispatchers
- **Options**: `get_option()` / `update_option()` for persistent settings
- **Config**: PressGang's `Config::get()` system, not a separate config repository

Only deviate from WordPress APIs when they are demonstrably inadequate for the task.

### Explicit Over Magic

- No facades, hidden globals, or "ambient" state.
- All configuration resolved via PressGang's `Config` system in the WP layer, or passed explicitly in core.
- Request DTOs must be inspectable — dumpable and serialisable for debugging.

### Provider-Agnostic Core

- Code under `src/` (outside `src/WP/`) must never call WordPress or PressGang functions.
- WordPress and PressGang integration lives in `src/WP/` only.
- Providers are drivers implementing `ProviderContract`; core cannot assume OpenAI/Anthropic specifics.

### Deterministic Contracts

- Builders collect intent and produce **immutable** request DTOs.
- Responses must preserve both raw provider output and the normalised Helm result.
- Structured output must validate against its schema; failures must be explicit.

### Tools Are Opt-In and Safe

- Tools must be explicitly registered.
- Tool execution must be gated (capability checks / allow-lists in the WP adapter).
- Tool inputs and outputs must be JSON-serialisable arrays only.

### No Remote Calls During Load

- No HTTP requests at file-load time or during class construction.
- Provider HTTP calls execute only inside explicit `->send()` / `->stream()` paths.

---

## Architecture

### Two Layers

| Layer | Location | Responsibility |
|---|---|---|
| **Core** | `src/` (except `WP/`) | Contracts, DTOs, builders, providers, validation, streaming. Framework-agnostic, zero dependencies. |
| **PressGang Adapter** | `src/WP/` | Boots Helm via PressGang config, provides WP HTTP transport, registers tools via filters, exposes hooks for observability. |

### Core Boundaries

Core **must**:

- Define stable contracts (`ProviderContract`, `ToolContract`, `TransportContract`)
- Contain provider drivers (OpenAI, Anthropic) that depend only on `TransportContract`
- Normalise provider responses into Helm response types
- Validate structured output against JSON schema
- Expose a small, cohesive public API (`Helm`, `ChatBuilder`)

Core **must not**:

- Read environment variables, WordPress options, or PressGang config directly
- Call `wp_remote_request()`, `Config::get()`, or any WP/PressGang function
- Import any class from `PressGang\` (parent framework) or WordPress

### PressGang Adapter Boundaries

The adapter in `src/WP/` bridges Helm's core into the PressGang ecosystem.

Adapter **must**:

- Resolve config via PressGang's `Config::get('helm')` — no separate config repository
- Provide `WpHttpTransport` implementing `TransportContract` via `wp_remote_post()`
- Bootstrap Helm via a `HelmServiceProvider` following PressGang's service provider pattern
- Register tools via `apply_filters('pressgang_helm_tools', [])`
- Provide observability hooks: `do_action('pressgang_helm_request', $request)`, `do_action('pressgang_helm_response', $response)`
- Enforce `current_user_can()` capability checks on tool execution that mutates state
- Enforce nonce verification and input sanitisation where applicable

Adapter **must not**:

- Leak WordPress or PressGang dependencies into core namespaces
- Force global state mutation

### How PressGang Integration Works

Helm registers as a PressGang package. No custom config loader or service container needed.

1. **`config/helm.php`** — picked up automatically by PressGang's `FileConfigLoader`. Child themes can override via their own `config/helm.php`.
2. **`HelmServiceProvider`** — reads config via `Config::get('helm')`, resolves transport and provider, wires the `Helm` instance.
3. **Hooks** — `pressgang_helm_*` actions/filters follow PressGang convention. Tool registration, request/response lifecycle, and error reporting all use WordPress hooks.
4. **Child theme overrides** — config values, tool lists, and provider selection can all be filtered.

---

## Repo Layout

```
pressgang-helm/
├── config/                  # Package config defaults (loaded by PressGang's FileConfigLoader)
│   └── helm.php
├── src/
│   ├── Helm.php             # Main entry point (receives provider + config)
│   ├── Contracts/           # Interfaces (ProviderContract, ToolContract, TransportContract)
│   ├── Chat/                # ChatBuilder
│   ├── DTO/                 # Immutable request/response value objects
│   ├── Schema/              # JSON schema validation + coercion
│   ├── Tools/               # Tool registry primitives
│   ├── Exceptions/          # Typed exception classes
│   ├── Providers/           # Provider drivers (OpenAI, Anthropic, Fake)
│   ├── Transport/           # CurlTransport (standalone, no WP)
│   └── WP/                  # PressGang adapter layer
│       ├── HelmServiceProvider.php
│       ├── WpHttpTransport.php
│       └── HelmContextManager.php (if needed for Timber/Twig)
├── stubs/                   # Userland scaffolds (Agent/Tool/Provider templates)
├── tests/                   # PHPUnit tests (no WP runtime)
└── composer.json
```

### Namespace Convention

Root namespace: `PressGang\Helm`

PSR-4 autoload mapping: `PressGang\Helm\` → `src/`

All classes use their directory as namespace segment: a class at `src/Chat/ChatBuilder.php` is `PressGang\Helm\Chat\ChatBuilder`.

### File Naming

- One class per file.
- Filename matches class name exactly (PascalCase).
- Contracts (interfaces) are suffixed with `Contract`: `ProviderContract`, `ToolContract`.
- Exceptions are suffixed with `Exception`: `ProviderException`, `ConfigurationException`.

---

## Public API (Stability Surface)

The following are stable public API — breaking changes require a major version bump:

- `PressGang\Helm\Helm`
- `PressGang\Helm\Chat\ChatBuilder`
- All interfaces under `PressGang\Helm\Contracts\*`
- All DTOs under `PressGang\Helm\DTO\*`

Everything else is internal and may change without notice.

---

## Configuration

Config is declarative and managed by PressGang's config system.

### Config Shape (`config/helm.php`)

```php
return [
    'provider'    => 'openai',
    'model'       => 'gpt-4o',
    'api_key'     => '',
    'temperature' => 1.0,
    'timeout'     => 30,
    'retries'     => 1,
    'logging'     => false,
];
```

### Resolution Order (in PressGang Adapter)

1. Child theme `config/helm.php` overrides (via PressGang's `FileConfigLoader` merge)
2. Parent theme `config/helm.php` defaults
3. `apply_filters('pressgang_get_config', $config)` for runtime overrides
4. Constants and environment variables can be injected via the filter or config file

Core receives the resolved config array — it never knows where values came from.

No config resolution at file-load time.

---

## DTOs and Validation

### Request DTOs Are Immutable

- `ChatRequest` — messages, model settings, tools, schema constraints
- `Message` — value object with role, content, optional tool_call

DTOs must be JSON-serialisable (provide `toArray()` at minimum).

### Structured Output

- When a JSON schema is provided, Helm validates model output against it.
- Validation failures surface as `SchemaValidationException` containing:
  - Validation errors
  - Raw model output
  - Request context
- Optional repair retries must be explicit: `->repair(1)`.

---

## Tools

Tools implement `ToolContract`:

```php
interface ToolContract
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function handle(array $input): array;
}
```

- Tools must be deterministic where possible.
- Tools must not perform writes unless explicitly designed and guarded in the WP adapter.
- Inputs and outputs are arrays only (JSON-safe).

### Tool Registration (WP Adapter)

Tools are registered via WordPress filter in the adapter:

```php
$tools = apply_filters('pressgang_helm_tools', []);
```

Theme and plugin code registers tools by hooking the filter. The adapter validates and allow-lists before passing to core.

---

## Error Handling

Typed exceptions under `PressGang\Helm\Exceptions\`:

| Exception | When |
|---|---|
| `ProviderException` | Transport or provider failure |
| `SchemaValidationException` | Structured output fails validation |
| `ToolExecutionException` | Tool failed or received invalid input |
| `ConfigurationException` | Missing provider, key, or model |

Exceptions must include enough context for debugging. Never include raw API keys in exception messages or logs.

---

## Code Style

- **PHP 8.3+** with typed properties and return types.
- **No `declare(strict_types=1)`** — matches PressGang ecosystem convention.
- No global functions in Helm core.
- One class per file, small and cohesive (SRP).
- Prefer value objects and explicit contracts over untyped arrays.
- Use `readonly` properties on DTOs where appropriate.

### Doc Blocks

Doc blocks are part of Helm's developer experience. Required tiering:

**Class doc blocks:**

| Tier | Scope | Requirement |
|---|---|---|
| A | Public primitives, extension points | 2-5 lines: responsibility, invariants, how to extend |
| B | Thin glue classes | 1-2 sentences |
| C | Trivial internal classes | Optional |

**Method doc blocks** (public/protected):

- 1-line behaviour summary
- `@param` for each parameter
- `@return` with generics where useful (`array<string, mixed>`)
- Note invariants, caching, or side effects when non-obvious

**Avoid**: `@package` tags, restating types already in signatures, control-flow commentary.

---

## Testing

### Stack

- **PHPUnit** — consistent with PressGang ecosystem.
- **PressGang Muster** (`pressgang-wp/muster`) — extend for AI-specific test seeding and fakes.
- **No WordPress runtime** in unit tests. WP behaviour is behind `src/WP/` and tested via seams.
- Tests live under `tests/` mirroring `src/` structure.

### Muster Integration

Helm extends Muster rather than building a separate fake/mock layer:

- **`FakeProvider`** — already exists, implements `ProviderContract` with static responses. Lives in `src/Providers/` (part of core, no WP dependency).
- **Victuals extension** — extend Muster's `Victuals` with AI-specific fake data generators (e.g. deterministic chat responses, token usage, tool call payloads) for seeding test scenarios.
- **WordPress stubs** — reuse Muster's `WordPressStubs` for offline testing of `src/WP/` adapter code without a WP runtime.
- **Response builders** — provide builder helpers for constructing test `ChatRequest`, `Message`, and `Response` objects with sensible defaults via Muster's seeded Faker.

### Testability Seams

Hard dependencies (static calls, constructors with side effects, WP functions) must be wrapped behind:

1. A contract (preferred), or
2. A protected method seam (acceptable), or
3. An injected transport

Never put unmockable calls at file-load time.

### Test Priority

1. Builder -> DTO correctness (messages, model settings)
2. Provider dispatch (`ChatRequest` passed correctly)
3. Schema validation (happy path + failure)
4. Tool registry and invocation contract
5. Exception types and messages (verify no secrets leak)

---

## Implementing a Change

1. **Choose the correct layer**: contracts/DTOs/providers -> core, WP/PressGang specifics -> `src/WP/`.
2. **Prefer WordPress APIs** when adding WP adapter functionality. Check if WordPress provides a native solution before introducing abstractions.
3. Add or extend DTOs and builder methods.
4. Write tests for the new behaviour.
5. Add doc blocks for invariants and extension points.
6. Keep diffs minimal — no opportunistic refactors.

---

## Hard Failures (Never Do These)

- Call WordPress or PressGang functions from outside `src/WP/`
- Introduce remote calls during autoload or construction
- Execute tools without explicit registry + allow-listing
- Swallow schema validation errors
- Add global helper functions in core
- Leak secrets in logs or exceptions
- Reinvent infrastructure that PressGang or WordPress already provides
