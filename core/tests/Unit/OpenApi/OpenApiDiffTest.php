<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\OpenApi;

use BsCms\OpenApi\OpenApiGenerator;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class OpenApiDiffTest extends TestCase
{
    public function testFrozenOpenApiContractMatchesLiveSpecification(): void
    {
        $schemaDir = dirname(__DIR__, 3) . '/resources/schema';
        $builtIns = [];

        if (is_dir($schemaDir)) {
            $files = glob($schemaDir . '/*.json') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $data = is_string($content) ? json_decode($content, true) : null;
                if (is_array($data) && isset($data['handle'])) {
                    $builtIns[$data['handle']] = $data;
                }
            }
        }

        $mockRegistry = new class($builtIns) extends CollectionRegistry {
            /** @param array<string, array<string, mixed>> $cols */
            public function __construct(private array $cols) {}
            public function all(): array { return $this->cols; }
            public function has(string $h): bool { return isset($this->cols[$h]); }
            public function get(string $h): array { return $this->cols[$h] ?? []; }
        };

        $fieldTypeRegistry = new FieldTypeRegistry();
        $generator = new OpenApiGenerator($mockRegistry, $fieldTypeRegistry);
        $liveSpec = $generator->generate();

        $contractPath = dirname(__DIR__, 4) . '/docs/api/openapi-v1.json';
        $this->assertFileExists($contractPath, "Frozen contract 'docs/api/openapi-v1.json' does not exist.");

        $frozenContent = file_get_contents($contractPath);
        $this->assertIsString($frozenContent);
        $frozenSpec = json_decode($frozenContent, true);
        $this->assertIsArray($frozenSpec, "Frozen contract 'docs/api/openapi-v1.json' is not valid JSON.");

        $this->assertEquals(
            $liveSpec,
            $frozenSpec,
            "docs/api/openapi-v1.json does not match the live generated OpenAPI specification. " .
            "Run 'php tools/generate-openapi.php' to sync the frozen contract."
        );
    }
}
