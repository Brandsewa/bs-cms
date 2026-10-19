<?php

declare(strict_types=1);

namespace BsCms\Document;

final class DerivedFields
{
    /**
     * Extract derived metadata and helper fields from a document tree.
     *
     * @param array<string, mixed> $document
     * @return array{
     *     excerpt: string,
     *     reading_time: int,
     *     word_count: int,
     *     outline: list<array{level: int, text: string, slug: string}>,
     *     media_ids: list<string>,
     *     internal_links: list<string>,
     *     faq_items: list<array{question: string, answer: string}>,
     *     search_text: string
     * }
     */
    public static function extract(array $document): array
    {
        $allText = [];
        $outline = [];
        $mediaIds = [];
        $internalLinks = [];
        $faqItems = [];
        $firstParagraphText = null;

        /** @var list<array<string, mixed>> $nodes */
        $nodes = array_values(array_filter((array) ($document['content'] ?? []), 'is_array'));
        self::walkNodes(
            $nodes,
            $allText,
            $outline,
            $mediaIds,
            $internalLinks,
            $faqItems,
            $firstParagraphText
        );

        $joinedText = implode(' ', array_filter($allText));
        $wordCount = str_word_count($joinedText);
        $readingTime = $wordCount > 0 ? (int) ceil($wordCount / 200) : 0;

        // Generate clean excerpt
        $excerptSource = $firstParagraphText ?? $joinedText;
        $excerpt = self::truncateExcerpt($excerptSource, 160);

        return [
            'excerpt' => $excerpt,
            'reading_time' => $readingTime,
            'word_count' => $wordCount,
            'outline' => $outline,
            'media_ids' => array_values(array_unique($mediaIds)),
            'internal_links' => array_values(array_unique($internalLinks)),
            'faq_items' => $faqItems,
            'search_text' => $joinedText,
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public static function extractMediaIds(array $document): array
    {
        return self::extract($document)['media_ids'];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $allText
     * @param list<array{level: int, text: string, slug: string}> $outline
     * @param list<string> $mediaIds
     * @param list<string> $internalLinks
     * @param list<array{question: string, answer: string}> $faqItems
     * @param string|null $firstParagraphText
     */
    private static function walkNodes(
        array $nodes,
        array &$allText,
        array &$outline,
        array &$mediaIds,
        array &$internalLinks,
        array &$faqItems,
        ?string &$firstParagraphText
    ): void {
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            $attrs = (array) ($node['attrs'] ?? []);
            $content = (array) ($node['content'] ?? []);

            // 1. Headings
            if ($type === 'heading') {
                $level = (int) ($attrs['level'] ?? 2);
                $headingText = self::getNodeText($node);
                if ($headingText !== '') {
                    $slug = self::slugify($headingText);
                    $outline[] = [
                        'level' => $level,
                        'text' => $headingText,
                        'slug' => $slug,
                    ];
                }
            }

            // 2. Paragraphs
            if ($type === 'paragraph') {
                $pText = self::getNodeText($node);
                if ($firstParagraphText === null && trim($pText) !== '') {
                    $firstParagraphText = trim($pText);
                }
            }

            // 3. Images & Media IDs
            if ($type === 'image') {
                $mId = $attrs['media_id'] ?? ($attrs['id'] ?? null);
                if (!empty($mId) && is_string($mId)) {
                    $mediaIds[] = (string) $mId;
                } elseif (!empty($attrs['src']) && is_string($attrs['src'])) {
                    if (preg_match('/([0-7][0-9A-HJKMNP-TV-Z]{25})/i', $attrs['src'], $m)) {
                        $mediaIds[] = strtoupper($m[1]);
                    }
                }
            }

            // 4. FAQ
            if ($type === 'faq') {
                $items = (array) ($attrs['items'] ?? []);
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['question'], $item['answer'])) {
                        $faqItems[] = [
                            'question' => (string) $item['question'],
                            'answer' => (string) $item['answer'],
                        ];
                        $allText[] = (string) $item['question'];
                        $allText[] = (string) $item['answer'];
                    }
                }
            }

            // 5. Key facts
            if ($type === 'keyFacts') {
                $facts = (array) ($attrs['facts'] ?? []);
                foreach ($facts as $fact) {
                    if (is_array($fact) && isset($fact['statement'], $fact['value'])) {
                        $allText[] = (string) $fact['statement'];
                        $allText[] = (string) $fact['value'];
                    }
                }
            }

            // 6. Text nodes and Marks (link discovery)
            if ($type === 'text') {
                $text = (string) ($node['text'] ?? '');
                $allText[] = $text;

                $marks = (array) ($node['marks'] ?? []);
                foreach ($marks as $mark) {
                    if (is_array($mark) && ($mark['type'] ?? '') === 'link') {
                        $href = (string) ($mark['attrs']['href'] ?? '');
                        if (self::isInternalLink($href)) {
                            $internalLinks[] = $href;
                        }
                    }
                }
            }

            // 7. Bookmarks
            if ($type === 'bookmark') {
                $url = (string) ($attrs['url'] ?? '');
                if (self::isInternalLink($url)) {
                    $internalLinks[] = $url;
                }
                if (!empty($attrs['title'])) {
                    $allText[] = (string) $attrs['title'];
                }
                if (!empty($attrs['description'])) {
                    $allText[] = (string) $attrs['description'];
                }
            }

            // Recurse into children
            if (!empty($content)) {
                /** @var list<array<string, mixed>> $childNodes */
                $childNodes = array_values(array_filter($content, 'is_array'));
                if (!empty($childNodes)) {
                    self::walkNodes(
                        $childNodes,
                        $allText,
                        $outline,
                        $mediaIds,
                        $internalLinks,
                        $faqItems,
                        $firstParagraphText
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function getNodeText(array $node): string
    {
        $texts = [];
        $content = (array) ($node['content'] ?? []);
        foreach ($content as $child) {
            if (is_array($child)) {
                if (($child['type'] ?? '') === 'text') {
                    $texts[] = (string) ($child['text'] ?? '');
                } else {
                    $texts[] = self::getNodeText($child);
                }
            }
        }
        return implode('', $texts);
    }

    private static function isInternalLink(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '//')) {
            return false;
        }

        // Relative path or anchor
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#')) {
            return true;
        }

        // Relative without scheme
        return !str_contains($trimmed, ':');
    }

    public static function truncateExcerpt(string $text, int $maxLength): string
    {
        $cleaned = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($cleaned) <= $maxLength) {
            return $cleaned;
        }

        $cut = mb_substr($cleaned, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 50) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, '.,!?:;') . '...';
    }

    private static function slugify(string $text): string
    {
        $slug = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $text) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_strtolower($slug);
        return $slug !== '' ? $slug : 'heading';
    }
}
