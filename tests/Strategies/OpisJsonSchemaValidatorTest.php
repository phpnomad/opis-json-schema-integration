<?php

namespace PHPNomad\OpisJsonSchema\Integration\Tests\Strategies;

use PHPNomad\JsonSchema\Exceptions\ValidationError;
use PHPNomad\JsonSchema\ValidationFailure;
use PHPNomad\OpisJsonSchema\Integration\Strategies\OpisJsonSchemaValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpisJsonSchemaValidatorTest extends TestCase
{
    private OpisJsonSchemaValidator $validator;
    private string $schemaPath;

    protected function setUp(): void
    {
        $this->validator = new OpisJsonSchemaValidator();
        $this->schemaPath = __DIR__ . '/../fixtures/person.schema.json';
    }

    public function testValidDataReturnsTrue(): void
    {
        $result = $this->validator->validate(
            ['name' => 'Alice', 'age' => 30],
            $this->schemaPath,
        );

        $this->assertTrue($result);
    }

    public function testMissingRequiredFieldThrowsValidationError(): void
    {
        try {
            $this->validator->validate(
                ['name' => 'Alice'],
                $this->schemaPath,
            );
            $this->fail('Expected ValidationError to be thrown.');
        } catch (ValidationError $e) {
            $this->assertNotEmpty($e->failures);
            $this->assertContainsOnlyInstancesOf(ValidationFailure::class, $e->failures);

            $keywords = array_map(fn (ValidationFailure $f) => $f->keyword, $e->failures);
            $this->assertContains('required', $keywords);
        }
    }

    public function testWrongTypeReportsPathAndKeyword(): void
    {
        try {
            $this->validator->validate(
                ['name' => 'Alice', 'age' => 'thirty'],
                $this->schemaPath,
            );
            $this->fail('Expected ValidationError to be thrown.');
        } catch (ValidationError $e) {
            $typeFailures = array_values(array_filter(
                $e->failures,
                fn (ValidationFailure $f) => $f->keyword === 'type',
            ));

            $this->assertCount(1, $typeFailures);
            $this->assertSame('age', $typeFailures[0]->path);
            $this->assertNotSame('', $typeFailures[0]->message);
        }
    }

    public function testAdditionalPropertiesRejected(): void
    {
        try {
            $this->validator->validate(
                ['name' => 'Alice', 'age' => 30, 'unknown' => 'field'],
                $this->schemaPath,
            );
            $this->fail('Expected ValidationError to be thrown.');
        } catch (ValidationError $e) {
            $keywords = array_map(fn (ValidationFailure $f) => $f->keyword, $e->failures);
            $this->assertContains('additionalProperties', $keywords);
        }
    }

    public function testMultipleViolationsAllReported(): void
    {
        try {
            $this->validator->validate(
                ['age' => -5, 'unknown' => 'field'],
                $this->schemaPath,
            );
            $this->fail('Expected ValidationError to be thrown.');
        } catch (ValidationError $e) {
            $keywords = array_map(fn (ValidationFailure $f) => $f->keyword, $e->failures);

            // Missing 'name' (required), negative age (minimum), extra field (additionalProperties).
            $this->assertContains('required', $keywords);
            $this->assertContains('minimum', $keywords);
            $this->assertContains('additionalProperties', $keywords);
        }
    }

    public function testMissingSchemaFileIsPassedThroughToResolver(): void
    {
        // Non-existent path that isn't a valid URI either — Opis's resolver
        // will reject it; we expect a runtime surface, not a silent success.
        $this->expectException(\Throwable::class);

        $this->validator->validate(
            ['name' => 'Alice', 'age' => 30],
            '/nonexistent/path/that/does-not-exist.json',
        );
    }

    public function testInvalidJsonSchemaFileThrowsRuntimeException(): void
    {
        $broken = tempnam(sys_get_temp_dir(), 'broken-schema-') . '.json';
        file_put_contents($broken, '{ not valid json');

        try {
            $this->expectException(RuntimeException::class);
            $this->validator->validate(['x' => 1], $broken);
        } finally {
            @unlink($broken);
        }
    }
}
