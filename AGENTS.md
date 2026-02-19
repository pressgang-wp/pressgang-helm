# PressGang Helm — Agent Guide

All changes must conform to these rules. This is the single source of truth for the codebase.

## Project Status

Helm is in **early build** — the specification is stable but implementation is in progress. When generating code, follow the repo layout and contracts described below exactly. Do not invent patterns that diverge from this guide.

## What Helm Is

Helm is the AI orchestration layer for the PressGang ecosystem. It provides a WordPress-friendly, provider-agnostic PHP API for:

- Chat/completions-style requests
- Tool/function execution
- Structured output (JSON schema validation)
- Streaming (evented responses)
- Embeddings and related primitives (deferred)

Helm is **infrastructure**, not a chatbot widget. It exists to make AI usage explicit, deterministic where possible, testable, and safe to run inside WordPress.

## What Helm Is Not

- Not a UI/chat plugin or prompt playground
- Not a provider SDK — it wraps providers behind contracts
- Not a framework container or service locator
- Not a background job system

Helm does not mutate WordPress state by default, silently execute tools, or make remote calls during bootstrap.

---

## Design Rules (Non-Negotiable)

### Explicit Over Magic

- No facades, hidden globals, or "ambient" state.
- All configuration must be passed explicitly or resolved via `ConfigRepositoryContract`.
- Request DTOs must be inspectable — dumpable and serialisable for debugging.

### Provider-Agnostic Core

- Code under `src/` (outside `src/WP/`) must never call WordPress functions.
- WordPress integration lives in `src/WP/` only.
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

### Three Layers

| Layer | Location | Responsibility |
|---|---|---|
| **Core** | `src/` (except `WP/`) | Contracts, DTOs, builders, validation, streaming abstractions. Framework-agnostic. |
| **Providers** | `src/Providers/` | Driver implementations behind `ProviderContract`. Depend on `TransportContract`. |
| **WP Adapter** | `src/WP/` | WP HTTP transport, config resolution, tool registry hooks/filters, WP-CLI (if added). |

### Core Boundaries

Core **must**:

- Define stable contracts (`ProviderContract`, `ToolContract`, `TransportContract`, `ConfigRepositoryContract`)
- Normalise provider responses into Helm response types
- Validate structured output against JSON schema
- Expose a small, cohesive public API (`Helm`, `ChatBuilder`)

Core **must not**:

- Read environment variables or WordPress options directly
- Call `wp_remote_request()` or any WP function

### WP Adapter Boundaries

WP adapter **must**:

- Resolve config from constants > env > WP options > package defaults (in that order)
- Provide `WpHttpTransport` wrapping the WP HTTP API
- Register tools via filters safely
- Provide debug hooks/actions for observability

WP adapter **must not**:

- Leak WordPress dependencies into core namespaces
- Force global state mutation

### WordPress Security (WP Adapter Only)

Any code under `src/WP/` that writes or mutates state must enforce:

- `current_user_can()` capability checks
- Nonce verification where applicable
- Sanitisation and validation of all user input

---

## Repo Layout

```
pressgang-helm/
├── config/                  # Package config defaults (arrays returning arrays)
│   └── helm.php
├── src/
│   ├── Helm.php             # Main entry point
│   ├── Contracts/           # Interfaces (ProviderContract, ToolContract, etc.)
│   ├── Chat/                # ChatBuilder + Message value object
│   ├── DTO/                 # Immutable request/response value objects
│   ├── Schema/              # JSON schema validation + coercion
│   ├── Tools/               # Tool registry primitives
│   ├── Streaming/           # Stream event types + handlers
│   ├── Exceptions/          # Typed exception classes
│   ├── Providers/           # Provider drivers
│   └── WP/                  # WordPress adapter (only WP-dependent code)
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

Config is declarative. Core uses `ConfigRepositoryContract` to access:

- Provider name
- API key
- Default model
- Timeouts / retries
- Logging / telemetry flags (optional)

WP adapter resolves config in precedence order:

1. PHP constants (explicit, highest priority)
2. Environment variables
3. WordPress options (if enabled)
4. Package defaults (`config/helm.php`)

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

---

## Streaming

When implemented, streaming:

- Must be event-driven (token deltas, tool calls, final message, errors)
- Must not require ReactPHP or other heavyweight dependencies
- Must degrade gracefully to non-stream mode

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

- **PHP 8.1+** with typed properties and return types.
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

- **PHPUnit** — preferred for consistency with PressGang.
- **No WordPress runtime** in unit tests. WP behaviour is behind `src/WP/` and tested via seams.
- Tests live under `tests/` mirroring `src/` structure.

### Testability Seams

Hard dependencies (static calls, constructors with side effects, WP functions) must be wrapped behind:

1. A contract (preferred), or
2. A protected method seam (acceptable), or
3. An injected transport / config repository

Never put unmockable calls at file-load time.

### Test Priority

1. Builder -> DTO correctness (messages, model settings)
2. Provider dispatch (`ChatRequest` passed correctly)
3. Schema validation (happy path + failure)
4. Tool registry and invocation contract
5. Exception types and messages (verify no secrets leak)

---

## Implementing a Change

1. **Choose the correct layer**: contracts/DTOs -> core, HTTP details -> transport/provider, WP specifics -> `src/WP/`.
2. Add or extend DTOs and builder methods.
3. Write tests for the new behaviour.
4. Add doc blocks for invariants and extension points.
5. Keep diffs minimal — no opportunistic refactors.

---

## Hard Failures (Never Do These)

- Call WordPress functions from outside `src/WP/`
- Introduce remote calls during autoload or construction
- Execute tools without explicit registry + allow-listing
- Swallow schema validation errors
- Add global helper functions in core
- Leak secrets in logs or exceptions