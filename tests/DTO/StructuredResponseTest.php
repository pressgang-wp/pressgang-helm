<?php

namespace PressGang\Helm\Tests\DTO;

use PressGang\Helm\DTO\StructuredResponse;
use PressGang\Helm\Tests\TestCase;

class StructuredResponseTest extends TestCase
{
    protected function makeResponse(array $structured = ['score' => 8, 'feedback' => 'Good']): StructuredResponse
    {
        return new StructuredResponse(
            structured: $structured,
            content: json_encode($structured),
            raw: ['choices' => []],
        );
    }

    public function test_array_access_get(): void
    {
        $response = $this->makeResponse();

        $this->assertSame(8, $response['score']);
        $this->assertSame('Good', $response['feedback']);
    }

    public function test_array_access_exists(): void
    {
        $response = $this->makeResponse();

        $this->assertTrue(isset($response['score']));
        $this->assertFalse(isset($response['missing']));
    }

    public function test_array_access_returns_null_for_missing_key(): void
    {
        $response = $this->makeResponse();

        $this->assertNull($response['missing']);
    }

    public function test_array_access_set_throws(): void
    {
        $response = $this->makeResponse();

        $this->expectException(\LogicException::class);
        $response['score'] = 10;
    }

    public function test_array_access_unset_throws(): void
    {
        $response = $this->makeResponse();

        $this->expectException(\LogicException::class);
        unset($response['score']);
    }

    public function test_structured_property_is_accessible(): void
    {
        $data = ['name' => 'Test', 'value' => 42];
        $response = $this->makeResponse($data);

        $this->assertSame($data, $response->structured);
    }

    public function test_inherits_response_properties(): void
    {
        $response = $this->makeResponse();

        $this->assertSame('{"score":8,"feedback":"Good"}', $response->content);
        $this->assertSame(['choices' => []], $response->raw);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test_non_array_structured_data_is_supported(): void
    {
        $response = new StructuredResponse(
            structured: 'ok',
            content: '"ok"',
            raw: ['choices' => []],
        );

        $this->assertSame('ok', $response->structured);
        $this->assertFalse(isset($response['any']));
        $this->assertNull($response['any']);
    }
}
