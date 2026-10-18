<?php

declare(strict_types=1);

require __DIR__ . '/../core/vendor/autoload.php';

use BsCms\OpenApi\OpenApiGenerator;
use BsCms\Schema\CollectionRegistry;
use BsCms\Schema\FieldTypeRegistry;

// Create dummy collection registry with default built-ins for static doc generation
$schemaDir = __DIR__ . '/../core/resources/schema';
$builtIns = [];
if (is_dir($schemaDir)) {
    foreach (scandir($schemaDir) as $file) {
        if (str_ends_with($file, '.json')) {
            $data = json_decode((string) file_get_contents($schemaDir . '/' . $file), true);
            if (is_array($data) && isset($data['handle'])) {
                $builtIns[$data['handle']] = $data;
            }
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

$spec = $generator->generate();

@mkdir(__DIR__ . '/../docs/api', 0755, true);
$target = __DIR__ . '/../docs/api/openapi-v1.json';
file_put_contents($target, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Successfully frozen OpenAPI v1 contract at {$target} (" . strlen(json_encode($spec)) . " bytes)\n";
