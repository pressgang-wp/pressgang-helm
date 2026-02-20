<?php

namespace PressGang\Helm\Tests\Schema;

use PressGang\Helm\Schema\SchemaValidator;
use PressGang\Helm\Tests\TestCase;

class SchemaValidatorTest extends TestCase
{
    public function test_validates_string_type(): void
    {
        $errors = SchemaValidator::validate('hello', ['type' => 'string']);

        $this->assertSame([], $errors);
    }

    public function test_rejects_wrong_type(): void
    {
        $errors = SchemaValidator::validate(42, ['type' => 'string']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("expected type 'string'", $errors[0]);
    }

    public function test_validates_integer_type(): void
    {
        $this->assertSame([], SchemaValidator::validate(5, ['type' => 'integer']));
        $this->assertNotEmpty(SchemaValidator::validate(5.5, ['type' => 'integer']));
    }

    public function test_validates_number_type(): void
    {
        $this->assertSame([], SchemaValidator::validate(5, ['type' => 'number']));
        $this->assertSame([], SchemaValidator::validate(3.14, ['type' => 'number']));
        $this->assertNotEmpty(SchemaValidator::validate('five', ['type' => 'number']));
    }

    public function test_validates_boolean_type(): void
    {
        $this->assertSame([], SchemaValidator::validate(true, ['type' => 'boolean']));
        $this->assertNotEmpty(SchemaValidator::validate(1, ['type' => 'boolean']));
    }

    public function test_validates_null_type(): void
    {
        $this->assertSame([], SchemaValidator::validate(null, ['type' => 'null']));
        $this->assertNotEmpty(SchemaValidator::validate('', ['type' => 'null']));
    }

    public function test_validates_array_type(): void
    {
        $this->assertSame([], SchemaValidator::validate([1, 2, 3], ['type' => 'array']));
        $this->assertNotEmpty(SchemaValidator::validate(['key' => 'val'], ['type' => 'array']));
    }

    public function test_validates_object_type(): void
    {
        $this->assertSame([], SchemaValidator::validate(['key' => 'val'], ['type' => 'object']));
        $this->assertSame([], SchemaValidator::validate([], ['type' => 'object'])); // empty array is valid object
        $this->assertNotEmpty(SchemaValidator::validate([1, 2], ['type' => 'object']));
    }

    public function test_validates_required_properties(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['name', 'age'],
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        $this->assertSame([], SchemaValidator::validate(['name' => 'Alice', 'age' => 30], $schema));

        $errors = SchemaValidator::validate(['name' => 'Alice'], $schema);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("missing required property 'age'", $errors[0]);
    }

    public function test_validates_nested_properties(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'required' => ['city'],
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $valid = ['address' => ['city' => 'London']];
        $this->assertSame([], SchemaValidator::validate($valid, $schema));

        $invalid = ['address' => ['city' => 123]];
        $errors = SchemaValidator::validate($invalid, $schema);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('$.address.city', $errors[0]);
    }

    public function test_validates_enum(): void
    {
        $schema = ['type' => 'string', 'enum' => ['red', 'green', 'blue']];

        $this->assertSame([], SchemaValidator::validate('red', $schema));

        $errors = SchemaValidator::validate('yellow', $schema);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must be one of', $errors[0]);
    }

    public function test_validates_min_length(): void
    {
        $schema = ['type' => 'string', 'minLength' => 3];

        $this->assertSame([], SchemaValidator::validate('abc', $schema));
        $this->assertNotEmpty(SchemaValidator::validate('ab', $schema));
    }

    public function test_validates_max_length(): void
    {
        $schema = ['type' => 'string', 'maxLength' => 5];

        $this->assertSame([], SchemaValidator::validate('hello', $schema));
        $this->assertNotEmpty(SchemaValidator::validate('toolong', $schema));
    }

    public function test_validates_minimum(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 1];

        $this->assertSame([], SchemaValidator::validate(1, $schema));
        $this->assertNotEmpty(SchemaValidator::validate(0, $schema));
    }

    public function test_validates_maximum(): void
    {
        $schema = ['type' => 'integer', 'maximum' => 10];

        $this->assertSame([], SchemaValidator::validate(10, $schema));
        $this->assertNotEmpty(SchemaValidator::validate(11, $schema));
    }

    public function test_validates_array_items(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];

        $this->assertSame([], SchemaValidator::validate(['a', 'b'], $schema));

        $errors = SchemaValidator::validate(['a', 42], $schema);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('$[1]', $errors[0]);
    }

    public function test_returns_empty_for_data_with_no_schema(): void
    {
        $this->assertSame([], SchemaValidator::validate('anything', []));
    }

    public function test_unknown_type_passes_validation(): void
    {
        $this->assertSame([], SchemaValidator::validate('data', ['type' => 'unknown']));
    }

    public function test_multiple_errors_accumulated(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['a', 'b', 'c'],
        ];

        $errors = SchemaValidator::validate([], $schema);
        $this->assertCount(3, $errors);
    }

    public function test_type_error_short_circuits(): void
    {
        // When type is wrong, should return early (no further validation)
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
        ];

        $errors = SchemaValidator::validate('not-an-object', $schema);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("expected type 'object'", $errors[0]);
    }
}
