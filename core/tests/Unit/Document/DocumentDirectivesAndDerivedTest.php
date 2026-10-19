<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Document;

use BsCms\Document\DerivedFields;
use BsCms\Document\DocumentConverter;
use BsCms\Document\DocumentValidator;
use BsCms\Document\DocumentSchema;
use PHPUnit\Framework\TestCase;

final class DocumentDirectivesAndDerivedTest extends TestCase
{
    private DocumentConverter $converter;
    private DocumentValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new DocumentConverter();
        $this->validator = new DocumentValidator();
    }

    public function testMaxDepth20EnforcedInValidator(): void
    {
        // Construct a document nested 25 levels deep
        $nested = ['type' => 'text', 'text' => 'Deeply nested content'];
        for ($i = 0; $i < 25; $i++) {
            $nested = [
                'type' => 'callout',
                'attrs' => ['type' => 'info'],
                'content' => [$nested],
            ];
        }

        $deepDoc = [
            'type' => 'doc',
            'schema_version' => 1,
            'content' => [$nested],
        ];

        $errors = $this->validator->validate($deepDoc);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exceeds maximum allowed nesting depth of 20', $errors[0]);

        // Verify valid depth <= 20 passes
        $validDoc = [
            'type' => 'doc',
            'schema_version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Shallow content'],
                    ],
                ],
            ],
        ];
        $this->assertEmpty($this->validator->validate($validDoc));
    }

    public function testFaqDirectiveWithHeadingsParsing(): void
    {
        $markdown = <<<'MD'
:::faq
### What is BS CMS?
BS CMS is an enterprise headless CMS built for speed and security.

### How does tree taxonomy work?
It uses materialized paths and closure tables for ultra-fast hierarchy queries.
:::
MD;

        $doc = $this->converter->markdownToDocument($markdown);
        $this->assertCount(1, $doc['content']);
        $faqNode = $doc['content'][0];
        $this->assertSame('faq', $faqNode['type']);

        $items = $faqNode['attrs']['items'] ?? [];
        $this->assertCount(2, $items);
        $this->assertSame('What is BS CMS?', $items[0]['question']);
        $this->assertStringContainsString('enterprise headless CMS', $items[0]['answer']);
        $this->assertSame('How does tree taxonomy work?', $items[1]['question']);
        $this->assertStringContainsString('materialized paths', $items[1]['answer']);

        // Check validation passes without error
        $errors = $this->validator->validate($doc);
        $this->assertEmpty($errors);
    }

    public function testRoundTripAllDirectives(): void
    {
        // 1. Callout
        $calloutMd = ":::callout warning [Important Note]\nPlease handle image uploads with caution.\n:::";
        $calloutDoc = $this->converter->markdownToDocument($calloutMd);
        $this->assertEmpty($this->validator->validate($calloutDoc));
        $this->assertSame('callout', $calloutDoc['content'][0]['type']);
        $this->assertSame('warning', $calloutDoc['content'][0]['attrs']['type']);
        $this->assertSame('Important Note', $calloutDoc['content'][0]['attrs']['title']);
        $roundCallout = $this->converter->documentToMarkdown($calloutDoc);
        $this->assertStringContainsString(':::callout warning [Important Note]', $roundCallout);

        // 2. FAQ
        $faqMd = ":::faq\n### Question One\nAnswer to question one.\n### Question Two\nAnswer to question two.\n:::";
        $faqDoc = $this->converter->markdownToDocument($faqMd);
        $this->assertEmpty($this->validator->validate($faqDoc));
        $this->assertSame('faq', $faqDoc['content'][0]['type']);
        $roundFaq = $this->converter->documentToMarkdown($faqDoc);
        $this->assertStringContainsString('### Question One', $roundFaq);
        $this->assertStringContainsString('Answer to question one.', $roundFaq);
        $this->assertStringContainsString('### Question Two', $roundFaq);

        // 3. Facts / keyfacts
        $factsMd = ":::facts\n- Revenue | $10M | https://example.com/sec\n- Users | 100,000 | https://example.com/stats\n:::";
        $factsDoc = $this->converter->markdownToDocument($factsMd);
        $this->assertEmpty($this->validator->validate($factsDoc));
        $this->assertSame('keyFacts', $factsDoc['content'][0]['type']);
        $roundFacts = $this->converter->documentToMarkdown($factsDoc);
        $this->assertStringContainsString(':::facts', $roundFacts);
        $this->assertStringContainsString('- Revenue | $10M | https://example.com/sec', $roundFacts);

        // 4. Embed
        $embedMd = ":::embed https://www.youtube.com/watch?v=dQw4w9WgXcQ\n:::";
        $embedDoc = $this->converter->markdownToDocument($embedMd);
        $this->assertEmpty($this->validator->validate($embedDoc));
        $this->assertSame('embed', $embedDoc['content'][0]['type']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $embedDoc['content'][0]['attrs']['src']);
        $roundEmbed = $this->converter->documentToMarkdown($embedDoc);
        $this->assertStringContainsString(':::embed https://www.youtube.com/watch?v=dQw4w9WgXcQ', $roundEmbed);

        // 5. Button
        $buttonMd = ":::button [Learn More] https://example.com/docs\n:::";
        $buttonDoc = $this->converter->markdownToDocument($buttonMd);
        $this->assertEmpty($this->validator->validate($buttonDoc));
        $this->assertSame('button', $buttonDoc['content'][0]['type']);
        $this->assertSame('Learn More', $buttonDoc['content'][0]['attrs']['text']);
        $this->assertSame('https://example.com/docs', $buttonDoc['content'][0]['attrs']['url']);
        $roundButton = $this->converter->documentToMarkdown($buttonDoc);
        $this->assertStringContainsString(':::button [Learn More] https://example.com/docs', $roundButton);
    }

    public function testDerivedFieldsExtraction(): void
    {
        $longParagraph = str_repeat("BS CMS provides enterprise-grade performance and headless flexibility. ", 25);
        $doc = [
            'type' => 'doc',
            'schema_version' => 1,
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [['type' => 'text', 'text' => 'Architecture Overview']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $longParagraph]],
                ],
                [
                    'type' => 'image',
                    'attrs' => [
                        'src' => '/media/01HTERM000000000000000001.jpg',
                        'media_id' => '01HTERM000000000000000001',
                        'alt' => 'Architecture Diagram',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Read our documentation',
                            'marks' => [
                                ['type' => 'link', 'attrs' => ['href' => '/blog/getting-started']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $derived = DerivedFields::extract($doc);

        // 1. Excerpt: truncated to max 160 characters
        $this->assertLessThanOrEqual(160, mb_strlen($derived['excerpt']));
        $this->assertStringStartsWith('BS CMS provides enterprise-grade', $derived['excerpt']);

        // 2. Reading time: calculated at 200 words per minute
        $this->assertGreaterThan(0, $derived['word_count']);
        $expectedReadingTime = (int) ceil($derived['word_count'] / 200);
        $this->assertSame($expectedReadingTime, $derived['reading_time']);

        // 3. Outline: includes heading slug
        $this->assertCount(1, $derived['outline']);
        $this->assertSame('Architecture Overview', $derived['outline'][0]['text']);
        $this->assertSame('architecture-overview', $derived['outline'][0]['slug']);

        // 4. Media IDs & internal links extracted
        $this->assertContains('01HTERM000000000000000001', $derived['media_ids']);
        $this->assertContains('/blog/getting-started', $derived['internal_links']);
    }
}
