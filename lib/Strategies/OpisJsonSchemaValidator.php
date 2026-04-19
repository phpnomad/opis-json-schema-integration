<?php

namespace PHPNomad\OpisJsonSchema\Integration\Strategies;

use Opis\JsonSchema\Errors\ValidationError as OpisValidationError;
use Opis\JsonSchema\Validator;
use PHPNomad\JsonSchema\Exceptions\ValidationError;
use PHPNomad\JsonSchema\Interfaces\JsonSchemaValidatorStrategy;
use PHPNomad\JsonSchema\ValidationFailure;
use RuntimeException;

/**
 * Opis-backed implementation of PHPNomad's JsonSchemaValidatorStrategy.
 *
 * Accepts either a local file path or a URI for the schema argument. File
 * paths are read and parsed before validation; URIs are delegated to the
 * underlying Opis validator's resolver (register prefixes on the Opis
 * validator beforehand if custom resolution is needed).
 *
 * Converts Opis's nested error tree into a flat list of
 * {@see ValidationFailure} entries so callers see one row per actual
 * violation rather than container errors (allOf / oneOf / anyOf).
 *
 * The Opis {@see Validator} is constructed by {@see buildValidator()}.
 * Subclass and override that method to register custom keywords, resolver
 * prefixes, or other Opis-specific behavior. The no-arg constructor keeps
 * the class safe to auto-wire through standard dependency injection
 * containers.
 */
class OpisJsonSchemaValidator implements JsonSchemaValidatorStrategy
{
    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = $this->buildValidator();
    }

    /**
     * Build the underlying Opis validator. Override to customize.
     *
     * The default instance is configured with
     * {@see Validator::setMaxErrors()} and
     * {@see Validator::setStopAtFirstError()} so every violation surfaces
     * in a single pass, matching the abstraction's contract.
     */
    protected function buildValidator(): Validator
    {
        $validator = new Validator();
        $validator->setMaxErrors(PHP_INT_MAX);
        $validator->setStopAtFirstError(false);

        return $validator;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(array $data, string $schemaUri): bool
    {
        $schema = $this->resolveSchema($schemaUri);
        $dataAsObject = json_decode((string) json_encode($data));

        $result = $this->validator->validate($dataAsObject, $schema);
        $error = $result->error();

        if ($error === null) {
            return true;
        }

        $failures = [];
        $this->collectFailures($error, $failures);

        throw new ValidationError($failures);
    }

    /**
     * Resolve the schema argument into something Opis can validate against.
     *
     * Local files are read and decoded; anything else is passed through as a
     * URI string for the resolver to handle.
     */
    private function resolveSchema(string $schemaUri): string|object
    {
        if (!is_file($schemaUri)) {
            return $schemaUri;
        }

        $contents = file_get_contents($schemaUri);
        if ($contents === false) {
            throw new RuntimeException("Unable to read schema from {$schemaUri}");
        }

        $decoded = json_decode($contents);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in schema file {$schemaUri}: " . json_last_error_msg()
            );
        }

        if (!is_object($decoded)) {
            throw new RuntimeException(
                "Schema at {$schemaUri} must be a JSON object at the top level."
            );
        }

        return $decoded;
    }

    /**
     * @param ValidationFailure[] $failures
     */
    private function collectFailures(OpisValidationError $error, array &$failures): void
    {
        if ($error->subErrors() === []) {
            $failures[] = new ValidationFailure(
                path: $this->formatPath($error),
                message: $this->formatMessage($error),
                keyword: $error->keyword(),
            );

            return;
        }

        foreach ($error->subErrors() as $sub) {
            $this->collectFailures($sub, $failures);
        }
    }

    private function formatPath(OpisValidationError $error): string
    {
        $pointer = $error->data()->fullPath();

        return $pointer === [] ? '' : implode('.', array_map('strval', $pointer));
    }

    private function formatMessage(OpisValidationError $error): string
    {
        $message = $error->message();

        foreach ($error->args() as $key => $value) {
            $replacement = is_scalar($value)
                ? (string) $value
                : (string) json_encode($value);

            $message = str_replace('{' . $key . '}', $replacement, $message);
        }

        return $message;
    }
}
