<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Schema;

use BsCms\Schema\FieldTypeRegistry;
use BsCms\Schema\FieldTypes\BlocksType;
use BsCms\Schema\FieldTypes\BooleanType;
use BsCms\Schema\FieldTypes\ColorType;
use BsCms\Schema\FieldTypes\ComputedType;
use BsCms\Schema\FieldTypes\DateType;
use BsCms\Schema\FieldTypes\DatetimeType;
use BsCms\Schema\FieldTypes\EmailType;
use BsCms\Schema\FieldTypes\EnumType;
use BsCms\Schema\FieldTypes\FaqType;
use BsCms\Schema\FieldTypes\GroupType;
use BsCms\Schema\FieldTypes\JsonType;
use BsCms\Schema\FieldTypes\MarkdownType;
use BsCms\Schema\FieldTypes\MediaType;
use BsCms\Schema\FieldTypes\MultiEnumType;
use BsCms\Schema\FieldTypes\NumberType;
use BsCms\Schema\FieldTypes\PhoneType;
use BsCms\Schema\FieldTypes\PopulateContext;
use BsCms\Schema\FieldTypes\RelationType;
use BsCms\Schema\FieldTypes\RepeaterType;
use BsCms\Schema\FieldTypes\RichtextType;
use BsCms\Schema\FieldTypes\SeoType;
use BsCms\Schema\FieldTypes\SlugType;
use BsCms\Schema\FieldTypes\StorageDescriptor;
use BsCms\Schema\FieldTypes\TaxonomyType;
use BsCms\Schema\FieldTypes\TextareaType;
use BsCms\Schema\FieldTypes\TextType;
use BsCms\Schema\FieldTypes\TimeType;
use BsCms\Schema\FieldTypes\UrlType;
use BsCms\Schema\FieldTypes\UserType;
use BsCms\Schema\FieldTypes\ValidationContext;
use PHPUnit\Framework\TestCase;

final class FieldTypesTest extends TestCase
{
    private FieldTypeRegistry $registry;
    private ValidationContext $dummyContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new FieldTypeRegistry();
        $this->dummyContext = new ValidationContext([], ['handle' => 'test'], ['handle' => 'posts']);
    }

    public function testPhase4FieldTypesRefuseUsageWithExplicitError(): void
    {
        $phase4Types = ['blocks'];
        foreach ($phase4Types as $typeName) {
            $this->assertTrue($this->registry->has($typeName), "Registry must know {$typeName}");
            $type = $this->registry->get($typeName);
            $errors = $type->validateConfig([]);
            $this->assertArrayHasKey('type', $errors);
            $this->assertSame(
                "The '{$typeName}' field type is available in Phase 4.",
                $errors['type'],
                "Field type {$typeName} must refuse with exact Phase 4 message"
            );
        }
    }

    public function testRichtextTypeValidationAndStorage(): void
    {
        $rich = new \BsCms\Schema\FieldTypes\RichtextType();
        $this->assertSame('richtext', $rich->name());
        $this->assertSame([], $rich->validateConfig(['profile' => 'article']));
        $this->assertSame([], $rich->validateConfig(['profile' => 'minimal']));
        $this->assertArrayHasKey('profile', $rich->validateConfig(['profile' => 'invalid_profile']));

        // Accepts markdown
        $this->assertSame([], $rich->validate("## Hello\n\nWorld", [], $this->dummyContext));
        $normalized = $rich->normalize("## Hello\n\nWorld", []);
        $this->assertIsArray($normalized);
        $this->assertSame('doc', $normalized['type']);
        $this->assertSame(1, $normalized['schema_version']);

        // Accepts JSON string or document array
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Test paragraph'],
                    ],
                ],
            ],
        ];
        $this->assertSame([], $rich->validate($doc, [], $this->dummyContext));
        $this->assertSame([], $rich->validate(json_encode($doc), [], $this->dummyContext));
    }

    public function testMediaTypeValidationAndStorage(): void
    {
        $media = new \BsCms\Schema\FieldTypes\MediaType();
        $this->assertSame('media', $media->name());
        $this->assertSame([], $media->validateConfig(['allowed_types' => ['image', 'video'], 'multiple' => true]));
        $this->assertArrayHasKey('multiple', $media->validateConfig(['multiple' => 'not-bool']));

        $validUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $invalidUlid = 'not-a-valid-ulid!';

        // Single media
        $this->assertSame([], $media->validate($validUlid, ['multiple' => false], $this->dummyContext));
        $errs = $media->validate($invalidUlid, ['multiple' => false], $this->dummyContext);
        $this->assertNotEmpty($errs);
        $this->assertSame($validUlid, $media->normalize(strtolower($validUlid), ['multiple' => false]));

        // Multiple media
        $this->assertSame([], $media->validate([$validUlid], ['multiple' => true], $this->dummyContext));
        $errsMult = $media->validate([$validUlid, $invalidUlid], ['multiple' => true], $this->dummyContext);
        $this->assertNotEmpty($errsMult);
        $this->assertSame([$validUlid], $media->normalize([strtolower($validUlid)], ['multiple' => true]));
    }

    public function testTaxonomyTypeValidationAndStorage(): void
    {
        $tax = new TaxonomyType();
        $this->assertSame('taxonomy', $tax->name());
        $this->assertSame(['taxonomy' => "The 'taxonomy' handle or ID is required in taxonomy field configuration."], $tax->validateConfig([]));
        $this->assertSame([], $tax->validateConfig(['taxonomy' => 'categories']));
        $this->assertContains('in', $tax->filterOperators());
        $this->assertContains('all', $tax->filterOperators());
    }

    public function testTextTypeValidationAndStorage(): void
    {
        $text = new TextType();
        $this->assertSame('text', $text->name());
        $this->assertTrue($text->isSortable());

        // Config validation
        $this->assertSame([], $text->validateConfig(['min_length' => 2, 'max_length' => 50]));
        $invalid = $text->validateConfig(['min_length' => 10, 'max_length' => 5]);
        $this->assertArrayHasKey('min_length', $invalid);

        // Normalize
        $this->assertSame('Hello', $text->normalize('  Hello  ', []));

        // Validate
        $this->assertSame([], $text->validate('Hello', ['min_length' => 2, 'max_length' => 10], $this->dummyContext));
        $errs = $text->validate('Too long text here', ['max_length' => 5], $this->dummyContext);
        $this->assertNotEmpty($errs);

        // Storage
        $this->assertSame('VARCHAR(50)', $text->storage(['max_length' => 50])->columnType);
        $this->assertSame('TEXT', $text->storage(['max_length' => 500])->columnType);
    }

    public function testNumberTypeValidationAndStorage(): void
    {
        $number = new NumberType();
        $this->assertSame('number', $number->name());
        $this->assertTrue($number->isSortable());

        // Integer storage
        $this->assertSame('INT', $number->storage([])->columnType);
        $this->assertSame('DECIMAL(12,2)', $number->storage(['precision' => 2])->columnType);

        // Normalization
        $this->assertSame(42, $number->normalize('42', []));
        $this->assertSame(42.5, $number->normalize('42.5', ['precision' => 2]));

        // Range validation
        $this->assertSame([], $number->validate(15, ['min' => 10, 'max' => 20], $this->dummyContext));
        $errs = $number->validate(5, ['min' => 10], $this->dummyContext);
        $this->assertCount(1, $errs);
    }

    public function testBooleanType(): void
    {
        $bool = new BooleanType();
        $this->assertTrue($bool->normalize('1', []));
        $this->assertTrue($bool->normalize('true', []));
        $this->assertFalse($bool->normalize('0', []));
        $this->assertFalse($bool->normalize('false', []));
        $this->assertSame('TINYINT(1)', $bool->storage([])->columnType);
    }

    public function testDateAndDatetimeTypes(): void
    {
        $date = new DateType();
        $this->assertSame('DATE', $date->storage([])->columnType);
        $this->assertSame([], $date->validate('2026-10-18', [], $this->dummyContext));
        $this->assertNotEmpty($date->validate('2026-02-31', [], $this->dummyContext));

        $datetime = new DatetimeType();
        $this->assertSame('DATETIME(3)', $datetime->storage([])->columnType);
        $this->assertSame([], $datetime->validate('2026-10-18T10:30:00Z', [], $this->dummyContext));
        $this->assertNotEmpty($datetime->validate('invalid-date', [], $this->dummyContext));

        $apiVal = $datetime->toApi('2026-10-18 10:30:00.000', [], new PopulateContext([]));
        $this->assertStringContainsString('2026-10-18T10:30:00', (string) $apiVal);
    }

    public function testEnumType(): void
    {
        $enum = new EnumType();
        $config = ['options' => ['draft', 'published', 'archived']];
        $this->assertSame([], $enum->validateConfig($config));
        $this->assertSame([], $enum->validate('published', $config, $this->dummyContext));
        $this->assertNotEmpty($enum->validate('unknown', $config, $this->dummyContext));
        $this->assertSame('VARCHAR(64)', $enum->storage([])->columnType);
    }

    public function testMultiEnumType(): void
    {
        $multi = new MultiEnumType();
        $config = ['options' => ['apple', 'banana', 'cherry']];
        $this->assertSame(['apple', 'banana'], $multi->normalize('apple, banana', $config));
        $this->assertSame([], $multi->validate(['apple'], $config, $this->dummyContext));
        $this->assertNotEmpty($multi->validate(['orange'], $config, $this->dummyContext));
        $this->assertSame(StorageDescriptor::MODE_JSON, $multi->storage([])->mode);
    }

    public function testSlugType(): void
    {
        $slug = new SlugType();
        $this->assertSame('hello-world-2026', $slug->normalize('  Hello, World! 2026... ', []));
        $this->assertSame([], $slug->validate('hello-world', [], $this->dummyContext));
        $this->assertNotEmpty($slug->validate('Hello World!', [], $this->dummyContext));
        $this->assertSame('VARCHAR(191)', $slug->storage([])->columnType);
    }

    public function testEmailAndUrlAndPhoneAndColor(): void
    {
        $email = new EmailType();
        $this->assertSame('admin@example.com', $email->normalize(' Admin@Example.Com ', []));
        $this->assertSame([], $email->validate('admin@example.com', [], $this->dummyContext));
        $this->assertNotEmpty($email->validate('not-an-email', [], $this->dummyContext));

        $url = new UrlType();
        $this->assertSame([], $url->validate('https://example.com/test', [], $this->dummyContext));
        $this->assertNotEmpty($url->validate('ftp:// invalid', [], $this->dummyContext));

        $phone = new PhoneType();
        $this->assertSame([], $phone->validate('+1 (555) 123-4567', [], $this->dummyContext));
        $this->assertNotEmpty($phone->validate('abc-def', [], $this->dummyContext));

        $color = new ColorType();
        $this->assertSame([], $color->validate('#ff5500', [], $this->dummyContext));
        $this->assertNotEmpty($color->validate('red', [], $this->dummyContext));
    }

    public function testUserType(): void
    {
        $user = new UserType();
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->assertSame([], $user->validate($ulid, [], $this->dummyContext));
        $this->assertNotEmpty($user->validate('invalid-ulid', [], $this->dummyContext));
        $this->assertStringContainsString('CHAR(26)', $user->storage([])->columnType ?? '');
    }

    public function testRelationType(): void
    {
        $relation = new RelationType();
        $config = ['target_collection' => 'authors', 'cardinality' => 'many_to_many'];
        $this->assertSame([], $relation->validateConfig($config));
        $this->assertSame(StorageDescriptor::MODE_RELATION, $relation->storage([])->mode);

        $ulid1 = '01ARZ3NDEKTSV4RRFFQ69G5FA1';
        $ulid2 = '01ARZ3NDEKTSV4RRFFQ69G5FA2';
        $this->assertSame([$ulid1, $ulid2], $relation->normalize([$ulid1, $ulid2], $config));
        $this->assertSame([], $relation->validate([$ulid1, $ulid2], $config, $this->dummyContext));
        $this->assertNotEmpty($relation->validate(['invalid'], $config, $this->dummyContext));

        // Populated data
        $popCtx = new PopulateContext(
            ['handle' => 'authors'],
            ['authors' => [['id' => $ulid1, 'name' => 'Alice']]],
            ['authors']
        );
        $res = $relation->toApi([$ulid1], $config, $popCtx);
        $this->assertIsArray($res);
        $this->assertSame('Alice', $res[0]['name']);
    }

    public function testSeoType(): void
    {
        $seo = new SeoType();
        $norm = $seo->normalize(['title' => 'My Title', 'canonical' => 'https://example.com'], []);
        $this->assertIsArray($norm);
        $this->assertSame('My Title', $norm['title']);
        $this->assertSame('https://example.com', $norm['canonical']);
        $this->assertFalse($norm['noindex']);
        $this->assertSame([], $seo->validate($norm, [], $this->dummyContext));
        $this->assertNotEmpty($seo->validate(['canonical' => 'invalid-url'], [], $this->dummyContext));
    }

    public function testRegistryAllAndDescribe(): void
    {
        $all = $this->registry->all();
        $this->assertGreaterThanOrEqual(25, count($all));
        $desc = $this->registry->describeAll();
        $this->assertNotEmpty($desc);
        $handles = array_column($desc, 'type');
        $this->assertContains('text', $handles);
        $this->assertContains('richtext', $handles);
        $this->assertContains('relation', $handles);
    }
}
