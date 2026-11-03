<?php

declare(strict_types=1);

namespace BsCms\Site;

/**
 * PHP port of @bs/renderer's Tiptap JSON → HTML converter (renderToHtml).
 * Produces the same markup as the static starter so pages rendered by the
 * on-the-fly site renderer look identical to the Astro build output.
 */
final class TiptapRenderer
{
    private const MAX_DEPTH = 32;

    private const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const CALLOUT_VARIANTS = ['info', 'warning', 'tip', 'note', 'success', 'error', 'danger'];

    private const EMBED_HOSTS = [
        'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'youtu.be', 'vimeo.com', 'player.vimeo.com', 'twitter.com', 'x.com',
        'open.spotify.com', 'soundcloud.com', 'w.soundcloud.com', 'codepen.io',
    ];

    /** @var (callable(string):string)|null */
    private $urlResolver;

    /** @param (callable(string):string)|null $urlResolver */
    public function __construct($urlResolver = null)
    {
        $this->urlResolver = $urlResolver;
    }

    /** @param mixed $doc */
    public function render($doc): string
    {
        if (!is_array($doc) || !isset($doc['content']) || !is_array($doc['content'])) {
            return '';
        }

        $chunks = [];
        foreach ($doc['content'] as $node) {
            $html = $this->node($node, 0);
            if (trim($html) !== '') {
                $chunks[] = $html;
            }
        }

        return implode("\n", $chunks);
    }

    /** @param mixed $value */
    public static function escapeHtml($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** @param mixed $url */
    public static function isSafeUrl($url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $normalized = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\s]/u', '', $url);
        $normalized = (string) preg_replace_callback('/&#(\d+);?/', fn ($m) => mb_chr((int) $m[1], 'UTF-8'), $normalized);
        $normalized = (string) preg_replace_callback(
            '/&#x([0-9a-fA-F]+);?/',
            fn ($m) => mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $normalized
        );
        $normalized = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\s]/u', '', $normalized);

        if ($normalized === '') {
            return false;
        }

        $lower = mb_strtolower($normalized);
        foreach (['javascript:', 'vbscript:', 'data:', 'file:'] as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return false;
            }
        }

        $trimmed = trim($url);
        if (str_starts_with($trimmed, '//')) {
            return false;
        }
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '?')) {
            return true;
        }
        if (str_starts_with($trimmed, 'bs://')) {
            return true;
        }

        $colon = strpos($trimmed, ':');
        if ($colon === false) {
            return true;
        }

        $scheme = mb_strtolower(substr($trimmed, 0, $colon));
        return in_array($scheme, self::SAFE_URL_SCHEMES, true);
    }

    /** @param mixed $url */
    public function sanitizeUrl($url): string
    {
        if (!is_string($url) || trim($url) === '') {
            return '#';
        }
        $trimmed = trim($url);
        if (!self::isSafeUrl($trimmed)) {
            return '#';
        }
        if ($this->urlResolver !== null) {
            $resolved = ($this->urlResolver)($trimmed);
            return self::isSafeUrl($resolved) ? $resolved : '#';
        }

        return $trimmed;
    }

    /**
     * @param list<array<string, mixed>>|null $marks
     */
    private function renderMarks(string $text, ?array $marks): string
    {
        $result = self::escapeHtml($text);
        foreach ($marks ?? [] as $mark) {
            $type = (string) ($mark['type'] ?? '');
            switch ($type) {
                case 'bold':
                    $result = "<strong>{$result}</strong>";
                    break;
                case 'italic':
                    $result = "<em>{$result}</em>";
                    break;
                case 'strike':
                    $result = "<s>{$result}</s>";
                    break;
                case 'underline':
                    $result = "<u>{$result}</u>";
                    break;
                case 'code':
                    $result = "<code>{$result}</code>";
                    break;
                case 'highlight':
                    $result = "<mark>{$result}</mark>";
                    break;
                case 'link':
                    $href = self::escapeHtml($this->sanitizeUrl($mark['attrs']['href'] ?? ''));
                    $target = !empty($mark['attrs']['target']) ? ' target="' . self::escapeHtml($mark['attrs']['target']) . '"' : '';
                    $rel = !empty($mark['attrs']['rel'])
                        ? ' rel="' . self::escapeHtml($mark['attrs']['rel']) . '"'
                        : ' rel="noopener noreferrer"';
                    $result = "<a href=\"{$href}\"{$rel}{$target}>{$result}</a>";
                    break;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed>|null $content
     */
    private function inline(?array $content, int $depth): string
    {
        if ($content === null || $depth > self::MAX_DEPTH) {
            return '';
        }
        $out = '';
        foreach ($content as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type === 'text') {
                $out .= $this->renderMarks((string) ($node['text'] ?? ''), $node['marks'] ?? null);
            } elseif ($type === 'hardBreak') {
                $out .= '<br />';
            } elseif ($type === 'image') {
                $out .= $this->imageNode($node);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function imageNode(array $node): string
    {
        $attrs = $node['attrs'] ?? [];
        $src = self::escapeHtml($this->sanitizeUrl($attrs['src'] ?? ''));
        $alt = self::escapeHtml($attrs['alt'] ?? '');
        $title = !empty($attrs['title']) ? ' title="' . self::escapeHtml($attrs['title']) . '"' : '';
        $width = !empty($attrs['width']) ? ' width="' . preg_replace('/[^\d]/', '', (string) $attrs['width']) . '"' : '';
        $height = !empty($attrs['height']) ? ' height="' . preg_replace('/[^\d]/', '', (string) $attrs['height']) . '"' : '';

        $srcset = '';
        if (is_string($attrs['srcset'] ?? null) && trim($attrs['srcset']) !== '') {
            $valid = [];
            foreach (explode(',', $attrs['srcset']) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $segments = preg_split('/\s+/', $part, 2) ?: [$part];
                $candidateUrl = $segments[0];
                $descriptor = $segments[1] ?? '';
                if ($candidateUrl && self::isSafeUrl($candidateUrl)) {
                    $valid[] = self::escapeHtml($candidateUrl) . ($descriptor !== '' ? ' ' . self::escapeHtml($descriptor) : '');
                }
            }
            if ($valid !== []) {
                $srcset = ' srcset="' . implode(', ', $valid) . '"';
            }
        }

        $sizes = !empty($attrs['sizes']) ? ' sizes="' . self::escapeHtml((string) $attrs['sizes']) . '"' : '';
        $mediaId = !empty($attrs['media_id']) || !empty($attrs['mediaId'])
            ? ' data-media-id="' . self::escapeHtml((string) ($attrs['media_id'] ?? $attrs['mediaId'])) . '"' : '';

        return "<img src=\"{$src}\" alt=\"{$alt}\"{$title}{$width}{$height}{$srcset}{$sizes}{$mediaId} loading=\"lazy\" decoding=\"async\" />";
    }

    /** @param mixed $variantRaw */
    private function calloutVariant($variantRaw): string
    {
        if (is_string($variantRaw)) {
            $v = strtolower(trim($variantRaw));
            if (in_array($v, self::CALLOUT_VARIANTS, true)) {
                return $v;
            }
        }

        return 'info';
    }

    /** @param mixed $node */
    private function node($node, int $depth): string
    {
        if (!is_array($node) || $depth > self::MAX_DEPTH) {
            return '';
        }

        $type = (string) ($node['type'] ?? '');
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $content = is_array($node['content'] ?? null) ? $node['content'] : null;
        $d = $depth + 1;
        $children = fn () => implode("\n", array_map(fn ($n) => $this->node($n, $d), $content ?? []));
        $listItems = fn () => implode("\n", array_map(fn ($n) => $this->node($n, $d), $content ?? []));

        switch ($type) {
            case 'paragraph':
                return '<p>' . $this->inline($content, $d) . '</p>';

            case 'heading':
                $level = min(6, max(1, (int) ($attrs['level'] ?? 2)));

                return "<h{$level}>" . $this->inline($content, $d) . "</h{$level}>";

            case 'blockquote':
                return "<blockquote>\n" . $children() . "\n</blockquote>";

            case 'codeBlock':
                $lang = self::escapeHtml($attrs['language'] ?? '');
                $code = self::escapeHtml(implode('', array_map(fn ($n) => (string) ($n['text'] ?? ''), $content ?? [])));
                $class = $lang !== '' ? " class=\"language-{$lang}\"" : '';

                return "<pre><code{$class}>{$code}</code></pre>";

            case 'bulletList':
                return "<ul>\n" . $listItems() . "\n</ul>";

            case 'orderedList':
                $start = (!empty($attrs['start']) && (int) $attrs['start'] !== 1) ? ' start="' . (int) $attrs['start'] . '"' : '';

                return "<ol{$start}>\n" . $listItems() . "\n</ol>";

            case 'listItem':
                $inner = '';
                foreach ($content ?? [] as $n) {
                    $inner .= (($n['type'] ?? '') === 'paragraph')
                        ? $this->inline(is_array($n['content'] ?? null) ? $n['content'] : null, $d)
                        : $this->node($n, $d);
                }

                return "<li>{$inner}</li>";

            case 'taskList':
                return "<ul class=\"task-list\">\n" . $listItems() . "\n</ul>";

            case 'taskItem':
                $checked = !empty($attrs['checked']);
                $inner = '';
                foreach ($content ?? [] as $n) {
                    $inner .= (($n['type'] ?? '') === 'paragraph')
                        ? $this->inline(is_array($n['content'] ?? null) ? $n['content'] : null, $d)
                        : $this->node($n, $d);
                }
                $box = '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . ' /> ';

                return "<li class=\"task-list-item\">{$box}{$inner}</li>";

            case 'horizontalRule':
                return '<hr />';

            case 'image':
                return $this->imageNode($node);

            case 'callout':
                $variant = $this->calloutVariant($attrs['variant'] ?? $attrs['type'] ?? null);
                $title = !empty($attrs['title'])
                    ? '<p class="callout-title"><strong>' . self::escapeHtml($attrs['title']) . "</strong></p>\n" : '';

                return "<div class=\"callout callout-{$variant}\" role=\"note\">\n{$title}" . $children() . "\n</div>";

            case 'faq':
                $items = is_array($attrs['items'] ?? null) ? $attrs['items'] : [];
                if ($items === []) {
                    return '';
                }
                $rendered = [];
                foreach ($items as $item) {
                    $q = self::escapeHtml($item['question'] ?? '');
                    $a = self::escapeHtml($item['answer'] ?? '');
                    $rendered[] = "<details class=\"faq-item\">\n  <summary>{$q}</summary>\n  <div class=\"faq-answer\">\n    <p>{$a}</p>\n  </div>\n</details>";
                }

                return "<div class=\"faq-group\">\n" . implode("\n", $rendered) . "\n</div>";

            case 'keyFacts':
            case 'facts':
                $facts = is_array($attrs['facts'] ?? null) ? $attrs['facts'] : [];
                if ($facts === []) {
                    return '';
                }
                $lines = [];
                foreach ($facts as $fact) {
                    $stmt = self::escapeHtml($fact['statement'] ?? '');
                    $val = self::escapeHtml($fact['value'] ?? '');
                    $src = '';
                    if (!empty($fact['source_url'])) {
                        $safeSource = self::escapeHtml($this->sanitizeUrl($fact['source_url']));
                        $src = " <a href=\"{$safeSource}\" class=\"fact-source\" rel=\"noopener noreferrer\">(Source)</a>";
                    }
                    $lines[] = "  <div class=\"fact-item\"><dt>{$stmt}</dt><dd>{$val}{$src}</dd></div>";
                }

                return "<dl class=\"key-facts\">\n" . implode("\n", $lines) . "\n</dl>";

            case 'bookmark':
                $rawUrl = (string) ($attrs['url'] ?? '');
                $url = self::escapeHtml($this->sanitizeUrl($rawUrl));
                $title = self::escapeHtml($attrs['title'] ?? $rawUrl);
                $desc = !empty($attrs['description']) ? '<p class="bookmark-desc">' . self::escapeHtml($attrs['description']) . '</p>' : '';
                $img = !empty($attrs['image'])
                    ? '<img src="' . self::escapeHtml($this->sanitizeUrl($attrs['image'])) . '" alt="" class="bookmark-image" loading="lazy" />' : '';

                return "<div class=\"bookmark-card\"><a href=\"{$url}\" rel=\"noopener noreferrer\" class=\"bookmark-link\">{$img}<div class=\"bookmark-content\"><p class=\"bookmark-title\"><strong>{$title}</strong></p>{$desc}</div></a></div>";

            case 'embed':
                $rawSrc = (string) ($attrs['url'] ?? $attrs['src'] ?? '');
                $provider = self::escapeHtml((string) ($attrs['provider'] ?? ''));
                if (!$this->isAllowedEmbedUrl($rawSrc)) {
                    $safeLink = $this->sanitizeUrl($rawSrc);
                    if ($safeLink !== '#') {
                        $safe = self::escapeHtml($safeLink);

                        return "<div class=\"embed-fallback\"><a href=\"{$safe}\" rel=\"noopener noreferrer\" target=\"_blank\">View embedded content ({$safe})</a></div>";
                    }

                    return '';
                }
                $normalizedSrc = $rawSrc;
                $parts = @parse_url($rawSrc);
                if (is_array($parts) && isset($parts['host'], $parts['scheme']) && $parts['scheme'] === 'https') {
                    $host = mb_strtolower($parts['host']);
                    $path = $parts['path'] ?? '/';
                    if (in_array($host, ['youtube.com', 'www.youtube.com'], true)) {
                        $normalizedSrc = 'https://www.youtube-nocookie.com' . $path
                            . (isset($parts['query']) ? '?' . $parts['query'] : '');
                    } elseif (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('#^/(\d+)#', $path, $m)) {
                        $normalizedSrc = "https://player.vimeo.com/video/{$m[1]}";
                    }
                }
                $safeSrc = self::escapeHtml($normalizedSrc);
                $providerAttr = $provider !== '' ? " data-embed-provider=\"{$provider}\"" : '';

                return "<div class=\"embed-placeholder\" data-embed-src=\"{$safeSrc}\"{$providerAttr}>\n  <button type=\"button\" class=\"embed-load-button\">Load interactive embed ({$safeSrc})</button>\n</div>";

            case 'button':
                $label = self::escapeHtml($attrs['label'] ?? $attrs['text'] ?? 'Click Here');
                $url = self::escapeHtml($this->sanitizeUrl($attrs['url'] ?? '#'));

                return "<a href=\"{$url}\" class=\"button btn-primary\" role=\"button\">{$label}</a>";

            case 'details':
                return "<details>\n" . $children() . "\n</details>";

            case 'detailsSummary':
                return '<summary>' . $this->inline($content, $d) . '</summary>';

            case 'detailsContent':
                return "<div class=\"details-content\">\n" . $children() . "\n</div>";

            case 'table':
                return "<div class=\"table-container\">\n<table>\n<tbody>\n" . $children() . "\n</tbody>\n</table>\n</div>";

            case 'tableRow':
                $cells = '';
                foreach ($content ?? [] as $n) {
                    $cells .= $this->node($n, $d);
                }

                return "<tr>{$cells}</tr>";

            case 'tableHeader':
            case 'tableCell':
                $inner = '';
                foreach ($content ?? [] as $n) {
                    $inner .= (($n['type'] ?? '') === 'paragraph')
                        ? $this->inline(is_array($n['content'] ?? null) ? $n['content'] : null, $d)
                        : $this->node($n, $d);
                }
                $tag = $type === 'tableHeader' ? 'th' : 'td';

                return "<{$tag}>{$inner}</{$tag}>";

            default:
                return '';
        }
    }

    private function isAllowedEmbedUrl(string $url): bool
    {
        $trimmed = trim($url);
        if (!str_starts_with($trimmed, 'https://')) {
            return false;
        }
        $parts = @parse_url($trimmed);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $host = mb_strtolower($parts['host']);
        foreach (self::EMBED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
