<?php

/**
 * Minimal WordPress function stubs for testing the WP adapter layer.
 *
 * Records all hook registrations and action dispatches so tests can
 * assert against them. Replaced by real WordPress (or Muster stubs)
 * when running in a WordPress test environment.
 */

namespace PressGang\ServiceProviders {

    if (!interface_exists(ServiceProviderInterface::class)) {
        /**
         * Stub of the PressGang ServiceProviderInterface for test isolation.
         */
        interface ServiceProviderInterface
        {
            public function boot(): void;
        }
    }
}

namespace PressGang\Helm\Tests\Stubs {

    /**
     * In-memory hook registry for test assertions.
     */
    class WpHooks
    {
        /** @var array<string, list<array{callback: callable, priority: int}>> */
        public static array $filters = [];

        /** @var array<string, list<array{callback: callable, priority: int}>> */
        public static array $actions = [];

        /** @var array<string, list<array<mixed>>> Dispatched action calls. */
        public static array $dispatched = [];

        public static function reset(): void
        {
            self::$filters = [];
            self::$actions = [];
            self::$dispatched = [];
        }
    }

    /**
     * In-memory HTTP stub state for wp_remote_request tests.
     */
    class WpHttp
    {
        /** @var mixed */
        public static mixed $response = [
            'response' => ['code' => 200],
            'body' => '{}',
        ];

        /** @var array<int, array{url: string, args: array<string, mixed>}> */
        public static array $requests = [];

        public static function reset(): void
        {
            self::$response = [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
            self::$requests = [];
        }
    }
}

namespace {

    if (!function_exists('add_filter')) {
        function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            \PressGang\Helm\Tests\Stubs\WpHooks::$filters[$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
            ];

            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
        {
            \PressGang\Helm\Tests\Stubs\WpHooks::$actions[$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
            ];

            return true;
        }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
        {
            foreach (\PressGang\Helm\Tests\Stubs\WpHooks::$filters[$tag] ?? [] as $entry) {
                $value = ($entry['callback'])($value, ...$args);
            }

            return $value;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $tag, mixed ...$args): void
        {
            \PressGang\Helm\Tests\Stubs\WpHooks::$dispatched[$tag][] = $args;

            foreach (\PressGang\Helm\Tests\Stubs\WpHooks::$actions[$tag] ?? [] as $entry) {
                ($entry['callback'])(...$args);
            }
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(
                protected string $code = '',
                protected string $message = '',
            ) {}

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('wp_remote_request')) {
        function wp_remote_request(string $url, array $args = []): mixed
        {
            \PressGang\Helm\Tests\Stubs\WpHttp::$requests[] = [
                'url' => $url,
                'args' => $args,
            ];

            return \PressGang\Helm\Tests\Stubs\WpHttp::$response;
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(mixed $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return (string) ($response['body'] ?? '');
        }
    }
}
