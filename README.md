# PHPNomad Opis JSON Schema Integration

Concrete `JsonSchemaValidatorStrategy` implementation backed by [`opis/json-schema`](https://github.com/opis/json-schema). Drop in to satisfy [`phpnomad/json-schema`](https://github.com/phpnomad/json-schema) with full draft 2020-12 support.

## Requirements

PHP 8.2 or newer.

## Installation

```bash
composer require phpnomad/opis-json-schema-integration
```

This pulls in both `phpnomad/json-schema` (the abstraction) and `opis/json-schema` (the concrete validator) automatically.

## Usage

```php
use PHPNomad\JsonSchema\Exceptions\ValidationError;
use PHPNomad\OpisJsonSchema\Integration\Strategies\OpisJsonSchemaValidator;

$validator = new OpisJsonSchemaValidator();

try {
    $validator->validate(
        ['name' => 'Alice', 'age' => 30],
        '/path/to/person.schema.json',
    );
} catch (ValidationError $e) {
    foreach ($e->failures as $failure) {
        printf(
            "%s: %s [%s]\n",
            $failure->path,
            $failure->message,
            $failure->keyword,
        );
    }
}
```

### Schema resolution

The `$schemaUri` argument accepts:

- **A local file path** — the file is read and parsed as JSON before validation.
- **A URI string** — passed straight through to Opis's resolver. Register prefixes or IDs on the underlying `Opis\JsonSchema\Validator` beforehand if you need custom resolution.

### Custom Opis configuration

Inject a preconfigured `Opis\JsonSchema\Validator` to customize keyword registration, resolver prefixes, or other Opis behavior:

```php
use Opis\JsonSchema\Validator as OpisValidator;

$opis = new OpisValidator();
$opis->resolver()->registerPrefix('https://my-site.example/', '/path/to/schemas');

$validator = new OpisJsonSchemaValidator($opis);
```

## How failures are reported

Every Opis leaf error becomes a `PHPNomad\JsonSchema\ValidationFailure`:

- `path` — data pointer, dotted notation (e.g. `programs.gold.incentiveType`; empty string at the root).
- `message` — Opis's error message with placeholder substitution applied.
- `keyword` — the JSON Schema keyword that failed (`required`, `type`, `enum`, etc.).

Container errors (`allOf`, `oneOf`, `anyOf`) are unwrapped — only the leaf violations are surfaced, so callers see one entry per actual problem.

## License

[MIT](LICENSE.txt)
