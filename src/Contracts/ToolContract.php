<?php

namespace PressGang\Helm\Contracts;

/**
 * Contract for tool definitions that can be invoked by AI providers.
 *
 * Tools must be explicitly registered and allow-listed before execution.
 * Inputs and outputs are JSON-serialisable arrays only — no objects,
 * no side effects unless the tool is explicitly designed for mutation.
 *
 * Implement this contract to create a new tool for Helm.
 */
interface ToolContract
{
    /**
     * The unique name identifying this tool.
     *
     * @return string
     */
    public function name(): string;

    /**
     * A human-readable description of what this tool does.
     *
     * @return string
     */
    public function description(): string;

    /**
     * The JSON Schema describing expected input parameters.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with the given input.
     *
     * @param array<string, mixed> $input Validated input matching the inputSchema.
     *
     * @return array<string, mixed> JSON-serialisable result.
     */
    public function handle(array $input): array;
}
