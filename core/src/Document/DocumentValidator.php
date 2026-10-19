<?php

declare(strict_types=1);

namespace BsCms\Document;

final class DocumentValidator
{
    public const MAX_DEPTH = 20;

    /**
     * @param array<string, mixed> $document
     * @param string $profile 'article' | 'minimal'
     * @param list<string>|null $allowedNodes Custom allowed nodes override
     * @return list<string> Validation error messages (empty if valid)
     */
    public function validate(
        array $document,
        string $profile = DocumentSchema::PROFILE_ARTICLE,
        ?array $allowedNodes = null
    ): array {
        if (self::calculateDepth($document) > self::MAX_DEPTH) {
            return ["Document structure exceeds maximum allowed nesting depth of " . self::MAX_DEPTH . "."];
        }

        $errors = [];

        if (($document['type'] ?? '') !== 'doc') {
            return ["Document root must have type 'doc'."];
        }

        $nodeAllowList = $allowedNodes ?? (
            $profile === DocumentSchema::PROFILE_MINIMAL
                ? DocumentSchema::MINIMAL_NODES
                : DocumentSchema::ARTICLE_NODES
        );

        $markAllowList = $profile === DocumentSchema::PROFILE_MINIMAL
            ? DocumentSchema::MINIMAL_MARKS
            : DocumentSchema::ARTICLE_MARKS;

        $content = $document['content'] ?? [];
        if (!is_array($content)) {
            return ["Document 'content' must be an array of block nodes."];
        }

        foreach ($content as $idx => $node) {
            if (!is_array($node)) {
                $errors[] = "Node at index {$idx} must be an object.";
                continue;
            }
            $this->validateNode($node, "content.{$idx}", $nodeAllowList, $markAllowList, $errors);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $allowedNodes
     * @param list<string> $allowedMarks
     * @param list<string> $errors
     */
    private function validateNode(
        array $node,
        string $path,
        array $allowedNodes,
        array $allowedMarks,
        array &$errors
    ): void {
        $type = $node['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $errors[] = "Node at '{$path}' must specify a valid 'type'.";
            return;
        }

        if (!in_array($type, $allowedNodes, true)) {
            $errors[] = "Node type '{$type}' at '{$path}' is not allowed in this document profile.";
            return;
        }

        // Validate node attributes
        $attrs = isset($node['attrs']) && is_array($node['attrs']) ? $node['attrs'] : [];
        $this->validateNodeAttributes($type, $attrs, $path, $errors);

        // Validate marks if present
        if (isset($node['marks'])) {
            if (!is_array($node['marks'])) {
                $errors[] = "Marks at '{$path}' must be an array.";
            } else {
                foreach ($node['marks'] as $mIdx => $mark) {
                    if (!is_array($mark)) {
                        $errors[] = "Mark at '{$path}.marks.{$mIdx}' must be an object.";
                        continue;
                    }
                    $this->validateMark($mark, "{$path}.marks.{$mIdx}", $allowedMarks, $errors);
                }
            }
        }

        // Validate children recursively if content is present
        if (isset($node['content'])) {
            if (!is_array($node['content'])) {
                $errors[] = "Content at '{$path}' must be an array.";
            } else {
                foreach ($node['content'] as $cIdx => $child) {
                    if (!is_array($child)) {
                        $errors[] = "Child node at '{$path}.content.{$cIdx}' must be an object.";
                        continue;
                    }
                    $this->validateNode($child, "{$path}.content.{$cIdx}", $allowedNodes, $allowedMarks, $errors);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<string> $errors
     */
    private function validateNodeAttributes(string $type, array $attrs, string $path, array &$errors): void
    {
        switch ($type) {
            case 'heading':
                $level = $attrs['level'] ?? null;
                if (!is_int($level) || $level < 2 || $level > 4) {
                    $errors[] = "Heading at '{$path}' must have an integer level between 2 and 4 (H1 is reserved for entry title).";
                }
                break;

            case 'callout':
                $calloutType = $attrs['type'] ?? 'info';
                if (!in_array($calloutType, ['info', 'tip', 'warning', 'note'], true)) {
                    $errors[] = "Callout at '{$path}' has invalid type '{$calloutType}'. Allowed: info, tip, warning, note.";
                }
                break;

            case 'image':
                $src = $attrs['src'] ?? null;
                if (!is_string($src) || trim($src) === '') {
                    $errors[] = "Image at '{$path}' requires a non-empty 'src'.";
                } elseif (!DocumentSchema::isSafeUrl($src)) {
                    $errors[] = "Image 'src' at '{$path}' contains an unsafe URL scheme.";
                }

                if (isset($attrs['align']) && !in_array($attrs['align'], ['left', 'center', 'right', 'wide', 'full'], true)) {
                    $errors[] = "Image alignment at '{$path}' must be one of: left, center, right, wide, full.";
                }

                if (isset($attrs['media_id']) && (!is_string($attrs['media_id']) || strlen($attrs['media_id']) !== 26)) {
                    $errors[] = "Image 'media_id' at '{$path}' must be a 26-character ULID.";
                }
                break;

            case 'bookmark':
                $url = $attrs['url'] ?? null;
                if (!is_string($url) || trim($url) === '') {
                    $errors[] = "Bookmark at '{$path}' requires a non-empty 'url'.";
                } elseif (!DocumentSchema::isSafeUrl($url)) {
                    $errors[] = "Bookmark 'url' at '{$path}' contains an unsafe URL scheme.";
                }
                break;

            case 'embed':
                $src = $attrs['src'] ?? null;
                if (!is_string($src) || trim($src) === '') {
                    $errors[] = "Embed at '{$path}' requires a non-empty 'src'.";
                } elseif (!DocumentSchema::isSafeUrl($src)) {
                    $errors[] = "Embed 'src' at '{$path}' contains an unsafe URL scheme.";
                }
                break;

            case 'button':
                $url = $attrs['url'] ?? null;
                $text = $attrs['text'] ?? null;
                if (!is_string($text) || trim($text) === '') {
                    $errors[] = "Button at '{$path}' requires a non-empty 'text'.";
                }
                if (!is_string($url) || trim($url) === '') {
                    $errors[] = "Button at '{$path}' requires a non-empty 'url'.";
                } elseif (!DocumentSchema::isSafeUrl($url)) {
                    $errors[] = "Button 'url' at '{$path}' contains an unsafe URL scheme.";
                }
                break;

            case 'faq':
                $items = $attrs['items'] ?? null;
                if (!is_array($items) || empty($items)) {
                    $errors[] = "FAQ block at '{$path}' must have non-empty 'items' array.";
                } else {
                    foreach ($items as $fIdx => $item) {
                        if (!is_array($item) || empty($item['question']) || empty($item['answer'])) {
                            $errors[] = "FAQ item at '{$path}.items.{$fIdx}' must contain non-empty 'question' and 'answer'.";
                        }
                    }
                }
                break;

            case 'keyFacts':
                $facts = $attrs['facts'] ?? null;
                if (!is_array($facts) || empty($facts)) {
                    $errors[] = "Key facts block at '{$path}' must have non-empty 'facts' array.";
                } else {
                    foreach ($facts as $fIdx => $fact) {
                        if (!is_array($fact) || empty($fact['statement']) || empty($fact['value'])) {
                            $errors[] = "Key fact at '{$path}.facts.{$fIdx}' must contain non-empty 'statement' and 'value'.";
                        }
                        if (isset($fact['source_url']) && is_string($fact['source_url']) && !DocumentSchema::isSafeUrl($fact['source_url'])) {
                            $errors[] = "Key fact 'source_url' at '{$path}.facts.{$fIdx}' contains an unsafe URL scheme.";
                        }
                    }
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $mark
     * @param list<string> $allowedMarks
     * @param list<string> $errors
     */
    private function validateMark(array $mark, string $path, array $allowedMarks, array &$errors): void
    {
        $type = $mark['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $errors[] = "Mark at '{$path}' must specify a valid 'type'.";
            return;
        }

        if (!in_array($type, $allowedMarks, true)) {
            $errors[] = "Mark type '{$type}' at '{$path}' is not allowed in this document profile.";
            return;
        }

        if ($type === 'link') {
            $attrs = isset($mark['attrs']) && is_array($mark['attrs']) ? $mark['attrs'] : [];
            $href = $attrs['href'] ?? null;
            if (!is_string($href) || trim($href) === '') {
                $errors[] = "Link mark at '{$path}' requires a non-empty 'href'.";
            } elseif (!DocumentSchema::isSafeUrl($href)) {
                $errors[] = "Link mark 'href' at '{$path}' contains an unsafe URL scheme.";
            }

            if (isset($attrs['target']) && !in_array($attrs['target'], ['_blank', '_self'], true)) {
                $errors[] = "Link target at '{$path}' must be '_blank' or '_self'.";
            }
        }
    }

    /**
     * Recursively calculate max array nesting depth.
     */
    public static function calculateDepth(mixed $value, int $currentDepth = 1): int
    {
        if (!is_array($value) || empty($value)) {
            return $currentDepth;
        }

        $max = $currentDepth;
        foreach ($value as $v) {
            if (is_array($v)) {
                $d = self::calculateDepth($v, $currentDepth + 1);
                if ($d > $max) {
                    $max = $d;
                }
            }
        }

        return $max;
    }
}
