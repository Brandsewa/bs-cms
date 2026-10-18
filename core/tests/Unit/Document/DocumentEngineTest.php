<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Document;

use BsCms\Document\DerivedFields;
use BsCms\Document\DocumentConverter;
use BsCms\Document\DocumentSchema;
use BsCms\Document\DocumentValidator;
use PHPUnit\Framework\TestCase;

final class DocumentEngineTest extends TestCase
{
    private DocumentValidator $validator;
    private DocumentConverter $converter;

    protected function setUp(): void
    {
        $this->validator = new DocumentValidator();
        $this->converter = new DocumentConverter();
    }

    public function testValidatorRejectsNonDocRoot(): void
    {
        $errors = $this->validator->validate(['type' => 'paragraph', 'content' => []]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("must have type 'doc'", $errors[0]);
    }

    public function testValidatorRejectsUnknownNodeTypes(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'malicious_script_node', 'content' => []],
            ],
        ];
        $errors = $this->validator->validate($doc);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Node type 'malicious_script_node'", $errors[0]);
    }

    public function testValidatorRejectsH1Heading(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 1],
                    'content' => [
                        ['type' => 'text', 'text' => 'Title'],
                    ],
                ],
            ],
        ];
        $errors = $this->validator->validate($doc);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("H1 is reserved", $errors[0]);
    }

    public function testValidatorRejectsUnsafeUrlsInLinksAndImages(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Bad link',
                            'marks' => [
                                ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'image',
                    'attrs' => ['src' => 'data:text/html;base64,PHNjcmlwdD4='],
                ],
            ],
        ];
        $errors = $this->validator->validate($doc);
        $this->assertCount(2, $errors);
        $this->assertStringContainsString("unsafe URL scheme", $errors[0]);
        $this->assertStringContainsString("unsafe URL scheme", $errors[1]);
    }

    public function testValidatorAllowsSafeUrls(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Safe relative link',
                            'marks' => [
                                ['type' => 'link', 'attrs' => ['href' => '/blog/my-post#section']],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => ' and safe https link',
                            'marks' => [
                                ['type' => 'link', 'attrs' => ['href' => 'https://brandsewa.com']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $errors = $this->validator->validate($doc);
        $this->assertEmpty($errors);
    }

    public function testMinimalProfileRejectsEmbedsAndCustomBlocks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'callout',
                    'attrs' => ['type' => 'info'],
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Callout']]],
                    ],
                ],
            ],
        ];
        // Minimal profile rejects callout
        $errors = $this->validator->validate($doc, DocumentSchema::PROFILE_MINIMAL);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("not allowed in this document profile", $errors[0]);

        // Article profile allows callout
        $articleErrors = $this->validator->validate($doc, DocumentSchema::PROFILE_ARTICLE);
        $this->assertEmpty($articleErrors);
    }

    public function testMarkdownToDocumentConversion(): void
    {
        $markdown = <<<MD
# Top Heading Converts to H2

This is a paragraph with **bold**, *italic*, ~~strike~~, and `inline code`.
Here is a [link to post](/posts/welcome).

## Second Heading

- Bullet item 1
- Bullet item 2

1. Numbered item 1
2. Numbered item 2

- [ ] Unchecked task
- [x] Checked task

> Blockquote with inner wisdom

```php
echo "Hello World";
```

![Sample Image](https://example.com/photo.jpg "Photo Title")

---

| Col 1 | Col 2 |
|---|---|
| A | B |

:::callout tip [Helpful Tip]
Do not forget to test everything.
:::

:::faq
Q: What is BS CMS?
A: An autonomous ultra-fast CMS.
Q: Is it fast?
A: Extremely fast.
:::

:::keyfacts
- Speed | 10ms | https://example.com/benchmarks
- License | MIT |
:::
MD;

        $doc = $this->converter->markdownToDocument($markdown);
        $this->assertSame('doc', $doc['type']);
        $this->assertSame(DocumentSchema::SCHEMA_VERSION, $doc['schema_version']);
        $this->assertNotEmpty($doc['content']);

        // Check validator passes on generated doc
        $errors = $this->validator->validate($doc);
        $this->assertEmpty($errors, 'Generated document must be valid against DocumentSchema: ' . implode(', ', $errors));

        // Check headings level conversion (H1 -> H2)
        $h2 = $doc['content'][0];
        $this->assertSame('heading', $h2['type']);
        $this->assertSame(2, $h2['attrs']['level']);

        // Check custom blocks
        $types = array_column($doc['content'], 'type');
        $this->assertContains('codeBlock', $types);
        $this->assertContains('taskList', $types);
        $this->assertContains('table', $types);
        $this->assertContains('callout', $types);
        $this->assertContains('faq', $types);
        $this->assertContains('keyFacts', $types);
    }

    public function testRoundTripFidelityMarkdownToDocumentToMarkdown(): void
    {
        $original = <<<MD
## Overview

This is standard text with **bold** and *italic* formatting.
Check out [Brand Sewa](https://brandsewa.com) for details.

### Features

- Fast execution
- Strong security

```javascript
console.log("roundtrip");
```

:::callout info [Note]
Important information here.
:::

:::faq
Q: Works?
A: Yes, seamlessly.
:::
MD;

        $doc = $this->converter->markdownToDocument($original);
        $markdownOut = $this->converter->documentToMarkdown($doc);

        $docAgain = $this->converter->markdownToDocument($markdownOut);
        $this->assertSame($doc['content'], $docAgain['content']);
    }

    public function testDerivedFieldsExtraction(): void
    {
        $markdown = <<<MD
## Welcome to BS CMS

BS CMS provides an autonomous headless content management system built with high standards of security, accessibility, and speed. It offers full Tiptap document schemas and markdown synchronization.

### System Architecture

The architecture uses relational data integrity and zero bloat.

![Featured Image](https://example.com/media/01ARZ3NDEKTSV4RRFFQ69G5FAV.jpg)

Here is an [internal link](/docs/architecture) and another [link](/posts/guide).

:::faq
Q: How does caching work?
A: It uses aggressive HTTP cache headers and ETags.
:::
MD;

        $doc = $this->converter->markdownToDocument($markdown);
        $derived = DerivedFields::extract($doc);

        // Excerpt
        $this->assertNotEmpty($derived['excerpt']);
        $this->assertStringContainsString('BS CMS provides an autonomous', $derived['excerpt']);

        // Word count and reading time
        $this->assertGreaterThan(20, $derived['word_count']);
        $this->assertSame(1, $derived['reading_time']);

        // Heading outline
        $this->assertCount(2, $derived['outline']);
        $this->assertSame('Welcome to BS CMS', $derived['outline'][0]['text']);
        $this->assertSame('welcome-to-bs-cms', $derived['outline'][0]['slug']);
        $this->assertSame('System Architecture', $derived['outline'][1]['text']);

        // Media IDs
        $this->assertCount(1, $derived['media_ids']);
        $this->assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $derived['media_ids'][0]);

        // Internal links
        $this->assertContains('/docs/architecture', $derived['internal_links']);
        $this->assertContains('/posts/guide', $derived['internal_links']);

        // FAQ items
        $this->assertCount(1, $derived['faq_items']);
        $this->assertSame('How does caching work?', $derived['faq_items'][0]['question']);

        // Search text
        $this->assertStringContainsString('Welcome to BS CMS', $derived['search_text']);
        $this->assertStringContainsString('aggressive HTTP cache headers', $derived['search_text']);
    }
}
