<?php

declare(strict_types=1);

namespace BsCms\Document;

final class DocumentSchema
{
    public const SCHEMA_VERSION = 1;

    public const PROFILE_ARTICLE = 'article';
    public const PROFILE_MINIMAL = 'minimal';

    public const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * @var list<string>
     */
    public const ARTICLE_NODES = [
        'doc',
        'paragraph',
        'heading',
        'blockquote',
        'bulletList',
        'orderedList',
        'listItem',
        'taskList',
        'taskItem',
        'codeBlock',
        'horizontalRule',
        'image',
        'callout',
        'faq',
        'keyFacts',
        'table',
        'tableRow',
        'tableHeader',
        'tableCell',
        'details',
        'detailsSummary',
        'detailsContent',
        'bookmark',
        'embed',
        'button',
        'text',
        'hardBreak',
    ];

    /**
     * @var list<string>
     */
    public const MINIMAL_NODES = [
        'doc',
        'paragraph',
        'heading',
        'bulletList',
        'orderedList',
        'listItem',
        'text',
        'hardBreak',
    ];

    /**
     * @var list<string>
     */
    public const ARTICLE_MARKS = [
        'bold',
        'italic',
        'strike',
        'underline',
        'code',
        'link',
        'highlight',
        'textStyle',
    ];

    /**
     * @var list<string>
     */
    public const MINIMAL_MARKS = [
        'bold',
        'italic',
        'strike',
        'underline',
        'code',
        'link',
    ];

    /**
     * Check if a URL is safe (http, https, mailto, tel, or relative path/anchor).
     */
    public static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return true;
        }

        // Relative path or anchor or query
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '?')) {
            // Must not start with // (protocol-relative to arbitrary origin)
            if (str_starts_with($trimmed, '//')) {
                return false;
            }
            return true;
        }

        $colonPos = strpos($trimmed, ':');
        if ($colonPos === false) {
            // Relative URL without leading slash (e.g. slug or file)
            return true;
        }

        $scheme = strtolower(substr($trimmed, 0, $colonPos));
        return in_array($scheme, self::SAFE_URL_SCHEMES, true);
    }
}
