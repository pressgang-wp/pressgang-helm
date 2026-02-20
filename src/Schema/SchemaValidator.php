<?php

namespace PressGang\Helm\Schema;

/**
 * Minimal JSON Schema validator for structured output.
 *
 * Validates decoded data against a JSON Schema definition, returning
 * an array of human-readable error strings. Supports the subset of
 * JSON Schema keywords needed for structured output: type, required,
 * properties, enum, minLength, maxLength, minimum, maximum, items.
 *
 * Not a full JSON Schema implementation — add keywords as needed,
 * or swap in opis/json-schema if complexity warrants it.
 */
class SchemaValidator
{
    /**
     * Validate data against a JSON Schema.
     *
     * @param mixed                $data   The decoded JSON data.
     * @param array<string, mixed> $schema The JSON Schema definition.
     * @param string               $path   Dot-notated path for error context.
     *
     * @return array<int, string> Validation errors (empty if valid).
     */
    public static function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $typeErrors = self::validateType($data, $schema['type'], $path);
            if ($typeErrors !== []) {
                return $typeErrors;
            }
        }

        if (isset($schema['enum'])) {
            if (!in_array($data, $schema['enum'], true)) {
                $allowed = implode(', ', array_map(fn ($v) => json_encode($v), $schema['enum']));
                $errors[] = "{$path}: value must be one of [{$allowed}].";
            }
        }

        if (is_string($data)) {
            $errors = array_merge($errors, self::validateString($data, $schema, $path));
        }

        if (is_int($data) || is_float($data)) {
            $errors = array_merge($errors, self::validateNumber($data, $schema, $path));
        }

        if (is_array($data) && self::isObject($data, $schema)) {
            $errors = array_merge($errors, self::validateObject($data, $schema, $path));
        }

        if (is_array($data) && ($schema['type'] ?? null) === 'array') {
            $errors = array_merge($errors, self::validateArray($data, $schema, $path));
        }

        return $errors;
    }

    /**
     * Validate the type of the data.
     *
     * @param mixed  $data The value to check.
     * @param string $type The expected JSON Schema type.
     * @param string $path Dot-notated path for error context.
     *
     * @return array<int, string>
     */
    protected static function validateType(mixed $data, string $type, string $path): array
    {
        $valid = match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'array' => is_array($data) && array_is_list($data),
            'object' => (is_array($data) && !array_is_list($data)) || $data === [],
            'null' => is_null($data),
            default => true,
        };

        if (!$valid) {
            $actual = get_debug_type($data);

            return ["{$path}: expected type '{$type}', got '{$actual}'."];
        }

        return [];
    }

    /**
     * Validate string constraints.
     *
     * @param string               $data   The string value.
     * @param array<string, mixed> $schema The schema definition.
     * @param string               $path   Dot-notated path for error context.
     *
     * @return array<int, string>
     */
    protected static function validateString(string $data, array $schema, string $path): array
    {
        $errors = [];
        $length = mb_strlen($data);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$path}: string length {$length} is less than minimum {$schema['minLength']}.";
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$path}: string length {$length} exceeds maximum {$schema['maxLength']}.";
        }

        return $errors;
    }

    /**
     * Validate number constraints.
     *
     * @param int|float            $data   The numeric value.
     * @param array<string, mixed> $schema The schema definition.
     * @param string               $path   Dot-notated path for error context.
     *
     * @return array<int, string>
     */
    protected static function validateNumber(int|float $data, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = "{$path}: value {$data} is less than minimum {$schema['minimum']}.";
        }

        if (isset($schema['maximum']) && $data > $schema['maximum']) {
            $errors[] = "{$path}: value {$data} exceeds maximum {$schema['maximum']}.";
        }

        return $errors;
    }

    /**
     * Validate object constraints (required properties + recursive property validation).
     *
     * @param array<string, mixed> $data   The object data as an associative array.
     * @param array<string, mixed> $schema The schema definition.
     * @param string               $path   Dot-notated path for error context.
     *
     * @return array<int, string>
     */
    protected static function validateObject(array $data, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $data)) {
                    $errors[] = "{$path}: missing required property '{$field}'.";
                }
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $property => $propertySchema) {
                if (array_key_exists($property, $data)) {
                    $errors = array_merge(
                        $errors,
                        self::validate($data[$property], $propertySchema, "{$path}.{$property}"),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Validate array constraints (items schema).
     *
     * @param array<int, mixed>    $data   The list data.
     * @param array<string, mixed> $schema The schema definition.
     * @param string               $path   Dot-notated path for error context.
     *
     * @return array<int, string>
     */
    protected static function validateArray(array $data, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $index => $item) {
                $errors = array_merge(
                    $errors,
                    self::validate($item, $schema['items'], "{$path}[{$index}]"),
                );
            }
        }

        return $errors;
    }

    /**
     * Determine if a data array should be treated as an object.
     *
     * @param array<mixed>         $data   The array to inspect.
     * @param array<string, mixed> $schema The schema definition.
     *
     * @return bool
     */
    protected static function isObject(array $data, array $schema): bool
    {
        if (($schema['type'] ?? null) === 'object') {
            return true;
        }

        if (isset($schema['properties']) || isset($schema['required'])) {
            return true;
        }

        return false;
    }
}
