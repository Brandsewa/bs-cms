<?php

declare(strict_types=1);

namespace BsCms\Document;

final class DocumentConverter
{
    /**
     * Convert Markdown into a canonical Tiptap/ProseMirror Document array.
     *
     * @param string $markdown
     * @param string $profile 'article' | 'minimal'
     * @return array<string, mixed>
     */
    public function markdownToDocument(string $markdown, string $profile = DocumentSchema::PROFILE_ARTICLE): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $normalized);
        $totalLines = count($lines);

        $content = [];
        $i = 0;

        while ($i < $totalLines) {
            $line = $lines[$i];

            // 1. Skip completely empty lines between blocks
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // 2. Fenced code block: ```[language]
            if (preg_match('/^```([a-zA-Z0-9_\-\.]*)$/', trim($line), $codeMatch)) {
                $language = trim($codeMatch[1]) !== '' ? trim($codeMatch[1]) : null;
                $codeLines = [];
                $i++;
                while ($i < $totalLines && trim($lines[$i]) !== '```') {
                    $codeLines[] = $lines[$i];
                    $i++;
                }
                if ($i < $totalLines && trim($lines[$i]) === '```') {
                    $i++; // skip closing ```
                }
                $codeText = implode("\n", $codeLines);
                $node = [
                    'type' => 'codeBlock',
                    'content' => [
                        ['type' => 'text', 'text' => $codeText],
                    ],
                ];
                if ($language !== null) {
                    $node['attrs'] = ['language' => $language];
                }
                $content[] = $node;
                continue;
            }

            // 3. Custom Fenced Directives: :::callout, :::faq, :::keyfacts, :::bookmark, :::embed
            if (preg_match('/^:::([a-zA-Z0-9_\-]+)(?:\s+(.*))?$/', trim($line), $directiveMatch)) {
                $directive = strtolower(trim($directiveMatch[1]));
                $args = trim($directiveMatch[2] ?? '');
                $directiveLines = [];
                $i++;
                while ($i < $totalLines && trim($lines[$i]) !== ':::') {
                    $directiveLines[] = $lines[$i];
                    $i++;
                }
                if ($i < $totalLines && trim($lines[$i]) === ':::') {
                    $i++; // skip closing :::
                }

                $parsedDirective = $this->parseDirective($directive, $args, $directiveLines);
                if ($parsedDirective !== null) {
                    $content[] = $parsedDirective;
                    continue;
                }
            }

            // 4. Headings: #, ##, ###, ####
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $hMatch)) {
                $rawLevel = strlen($hMatch[1]);
                // H1 converts to H2, levels capped between 2 and 4
                $level = max(2, min(4, $rawLevel === 1 ? 2 : $rawLevel));
                $headingText = trim($hMatch[2]);
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => $level],
                    'content' => $this->parseInline($headingText),
                ];
                $i++;
                continue;
            }

            // 5. Horizontal rule: --- or *** or ___
            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', trim($line))) {
                $content[] = ['type' => 'horizontalRule'];
                $i++;
                continue;
            }

            // 6. Blockquote: > text
            if (str_starts_with(trim($line), '>')) {
                $quoteLines = [];
                while ($i < $totalLines && str_starts_with(trim($lines[$i]), '>')) {
                    $cleaned = preg_replace('/^>\s?/', '', $lines[$i]);
                    $quoteLines[] = $cleaned;
                    $i++;
                }
                $quoteBody = implode("\n", $quoteLines);
                $innerDoc = $this->markdownToDocument($quoteBody, $profile);
                $content[] = [
                    'type' => 'blockquote',
                    'content' => $innerDoc['content'] ?? [],
                ];
                continue;
            }

            // 7. Markdown Table: starts with | and contains |
            if (str_starts_with(trim($line), '|') && str_ends_with(trim($line), '|')) {
                $tableLines = [];
                while ($i < $totalLines && str_starts_with(trim($lines[$i]), '|') && str_ends_with(trim($lines[$i]), '|')) {
                    $tableLines[] = trim($lines[$i]);
                    $i++;
                }
                $tableNode = $this->parseTable($tableLines);
                if ($tableNode !== null) {
                    $content[] = $tableNode;
                    continue;
                }
            }

            // 8. Task lists: - [ ] or - [x]
            if (preg_match('/^-\s+\[([ xX])\]\s+(.+)$/', $line)) {
                $taskItems = [];
                while ($i < $totalLines && preg_match('/^-\s+\[([ xX])\]\s+(.+)$/', $lines[$i], $taskMatch)) {
                    $checked = strtolower($taskMatch[1]) === 'x';
                    $itemText = trim($taskMatch[2]);
                    $taskItems[] = [
                        'type' => 'taskItem',
                        'attrs' => ['checked' => $checked],
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => $this->parseInline($itemText),
                            ],
                        ],
                    ];
                    $i++;
                }
                $content[] = [
                    'type' => 'taskList',
                    'content' => $taskItems,
                ];
                continue;
            }

            // 9. Bullet list: - or *
            if (preg_match('/^[\*\-]\s+(.+)$/', $line)) {
                $listItems = [];
                while ($i < $totalLines && preg_match('/^[\*\-]\s+(.+)$/', $lines[$i], $itemMatch)) {
                    $itemText = trim($itemMatch[1]);
                    $listItems[] = [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => $this->parseInline($itemText),
                            ],
                        ],
                    ];
                    $i++;
                }
                $content[] = [
                    'type' => 'bulletList',
                    'content' => $listItems,
                ];
                continue;
            }

            // 10. Numbered list: 1. 2.
            if (preg_match('/^(\d+)\.\s+(.+)$/', $line, $numMatch)) {
                $start = (int) $numMatch[1];
                $listItems = [];
                while ($i < $totalLines && preg_match('/^\d+\.\s+(.+)$/', $lines[$i], $itemMatch)) {
                    $itemText = trim($itemMatch[1]);
                    $listItems[] = [
                        'type' => 'listItem',
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => $this->parseInline($itemText),
                            ],
                        ],
                    ];
                    $i++;
                }
                $node = [
                    'type' => 'orderedList',
                    'content' => $listItems,
                ];
                if ($start !== 1) {
                    $node['attrs'] = ['start' => $start];
                }
                $content[] = $node;
                continue;
            }

            // 11. Standalone image: ![alt](src "title")
            if (preg_match('/^!\[(.*?)\]\((\S+?)(?:\s+"(.*?)")?\)$/', trim($line), $imgMatch)) {
                $alt = $imgMatch[1];
                $src = $imgMatch[2];
                $title = $imgMatch[3] ?? null;

                $attrs = ['src' => $src];
                if ($alt !== '') {
                    $attrs['alt'] = $alt;
                }
                if ($title !== null && $title !== '') {
                    $attrs['title'] = $title;
                }

                // If src contains a 26-char ULID (e.g. /media/01J... or 01J...), extract media_id
                if (preg_match('/([0-7][0-9A-HJKMNP-TV-Z]{25})/i', $src, $ulidMatch)) {
                    $attrs['media_id'] = strtoupper($ulidMatch[1]);
                }

                $content[] = [
                    'type' => 'image',
                    'attrs' => $attrs,
                ];
                $i++;
                continue;
            }

            // 12. Standard Paragraph
            $paraLines = [];
            while ($i < $totalLines && trim($lines[$i]) !== '') {
                // Break if next line starts a new block construct
                $next = $lines[$i];
                if ($paraLines !== [] && (
                    str_starts_with(trim($next), '```') ||
                    str_starts_with(trim($next), ':::') ||
                    preg_match('/^#{1,6}\s+/', $next) ||
                    preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', trim($next)) ||
                    str_starts_with(trim($next), '>') ||
                    (str_starts_with(trim($next), '|') && str_ends_with(trim($next), '|')) ||
                    preg_match('/^[\*\-]\s+/', $next) ||
                    preg_match('/^\d+\.\s+/', $next)
                )) {
                    break;
                }
                $paraLines[] = $lines[$i];
                $i++;
            }

            $paraText = implode(' ', array_map('trim', $paraLines));
            if ($paraText !== '') {
                $content[] = [
                    'type' => 'paragraph',
                    'content' => $this->parseInline($paraText),
                ];
            }
        }

        return [
            'type' => 'doc',
            'schema_version' => DocumentSchema::SCHEMA_VERSION,
            'content' => $content,
        ];
    }

    /**
     * Parse inline text elements (links, bold, italic, strike, code, text).
     *
     * @param string $text
     * @return list<array<string, mixed>>
     */
    public function parseInline(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $nodes = [];

        // Match markdown tokens:
        // 1. Image: ![alt](url)
        // 2. Link: [text](url)
        // 3. Bold + Italic: ***text*** or ___text___
        // 4. Bold: **text** or __text__
        // 5. Italic: *text* or _text_
        // 6. Strike: ~~text~~
        // 7. Inline code: `code`
        $pattern = '/(!?\[.*?\]\(.*?\)|(?:\*\*\*|___).+?(?:\*\*\*|___)|(?:\*\*|__).+?(?:\*\*|__)|(?:\*|_).+?(?:\*|_)|~~.+?~~|`.+?`)/s';

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [['type' => 'text', 'text' => $text]];
        }

        foreach ($parts as $part) {
            // Image
            if (preg_match('/^!\[(.*?)\]\((\S+?)(?:\s+"(.*?)")?\)$/', $part, $m)) {
                $attrs = ['src' => $m[2]];
                if ($m[1] !== '') {
                    $attrs['alt'] = $m[1];
                }
                if (!empty($m[3])) {
                    $attrs['title'] = $m[3];
                }
                $nodes[] = ['type' => 'image', 'attrs' => $attrs];
                continue;
            }

            // Link
            if (preg_match('/^\[(.*?)\]\((.*?)\)$/', $part, $m)) {
                $linkText = $m[1];
                $linkHref = $m[2];
                $nodes[] = [
                    'type' => 'text',
                    'text' => $linkText,
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => ['href' => $linkHref],
                        ],
                    ],
                ];
                continue;
            }

            // Bold + Italic: ***...***
            if (preg_match('/^(?:\*\*\*|___)(.+?)(?:\*\*\*|___)$/s', $part, $m)) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $m[1],
                    'marks' => [
                        ['type' => 'bold'],
                        ['type' => 'italic'],
                    ],
                ];
                continue;
            }

            // Bold: **...**
            if (preg_match('/^(?:\*\*|__)(.+?)(?:\*\*|__)$/s', $part, $m)) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $m[1],
                    'marks' => [
                        ['type' => 'bold'],
                    ],
                ];
                continue;
            }

            // Italic: *...*
            if (preg_match('/^(?:\*|_)(.+?)(?:\*|_)$/s', $part, $m)) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $m[1],
                    'marks' => [
                        ['type' => 'italic'],
                    ],
                ];
                continue;
            }

            // Strikethrough: ~~...~~
            if (preg_match('/^~~(.+?)~~$/s', $part, $m)) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $m[1],
                    'marks' => [
                        ['type' => 'strike'],
                    ],
                ];
                continue;
            }

            // Inline code: `...`
            if (preg_match('/^`(.+?)`$/s', $part, $m)) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => $m[1],
                    'marks' => [
                        ['type' => 'code'],
                    ],
                ];
                continue;
            }

            // Plain text
            $nodes[] = [
                'type' => 'text',
                'text' => $part,
            ];
        }

        return $nodes;
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>|null
     */
    private function parseTable(array $lines): ?array
    {
        if (count($lines) < 2) {
            return null;
        }

        $headerLine = array_shift($lines);
        $separatorLine = array_shift($lines);

        // Validate separator line has dashes
        if (!preg_match('/^\|(?:\s*:?-+:?\s*\|)+$/', $separatorLine)) {
            return null;
        }

        $parseCells = function (string $line): array {
            $trimmed = trim($line, '|');
            $rawCells = explode('|', $trimmed);
            return array_map('trim', $rawCells);
        };

        $headers = $parseCells($headerLine);
        $headerCells = [];
        foreach ($headers as $cell) {
            $headerCells[] = [
                'type' => 'tableHeader',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => $this->parseInline($cell),
                    ],
                ],
            ];
        }

        $rows = [
            [
                'type' => 'tableRow',
                'content' => $headerCells,
            ],
        ];

        foreach ($lines as $rowLine) {
            $cells = $parseCells($rowLine);
            $rowCells = [];
            foreach ($cells as $cell) {
                $rowCells[] = [
                    'type' => 'tableCell',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => $this->parseInline($cell),
                        ],
                    ],
                ];
            }
            $rows[] = [
                'type' => 'tableRow',
                'content' => $rowCells,
            ];
        }

        return [
            'type' => 'table',
            'content' => $rows,
        ];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>|null
     */
    private function parseDirective(string $directive, string $args, array $lines): ?array
    {
        switch ($directive) {
            case 'callout':
                // Format: :::callout [type] [optional title]
                $type = 'info';
                $title = null;
                if ($args !== '') {
                    $parts = preg_split('/\s+/', $args, 2);
                    $potentialType = strtolower($parts[0] ?? 'info');
                    if (in_array($potentialType, ['info', 'tip', 'warning', 'note'], true)) {
                        $type = $potentialType;
                        $title = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1], "[] ") : null;
                    } else {
                        $title = trim($args, "[] ");
                    }
                }
                $body = implode("\n", $lines);
                $innerDoc = $this->markdownToDocument($body);
                $node = [
                    'type' => 'callout',
                    'attrs' => ['type' => $type],
                    'content' => $innerDoc['content'] ?? [],
                ];
                if ($title !== null) {
                    $node['attrs']['title'] = $title;
                }
                return $node;

            case 'faq':
                // Format:
                // ### Question? or Q: Question?
                // Answer text.
                $items = [];
                $currentQ = null;
                $currentA = [];

                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    if (preg_match('/^(?:#{1,6}\s+|Q:\s*)(.+)$/i', $trimmedLine, $qMatch)) {
                        if ($currentQ !== null && !empty($currentA)) {
                            $items[] = [
                                'question' => $currentQ,
                                'answer' => implode(' ', $currentA),
                            ];
                        }
                        $currentQ = trim($qMatch[1]);
                        $currentA = [];
                    } elseif (preg_match('/^A:\s*(.+)$/i', $trimmedLine, $aMatch)) {
                        $currentA[] = trim($aMatch[1]);
                    } elseif ($currentQ !== null && $trimmedLine !== '') {
                        $currentA[] = $trimmedLine;
                    }
                }
                if ($currentQ !== null && !empty($currentA)) {
                    $items[] = [
                        'question' => $currentQ,
                        'answer' => implode(' ', $currentA),
                    ];
                }
                return [
                    'type' => 'faq',
                    'attrs' => ['items' => $items],
                ];

            case 'keyfacts':
            case 'facts':
                // Format:
                // - Statement | Value | [optional source url]
                $facts = [];
                foreach ($lines as $line) {
                    if (preg_match('/^-\s+(.+)$/', trim($line), $fMatch)) {
                        $cols = array_map('trim', explode('|', $fMatch[1]));
                        if (count($cols) >= 2) {
                            $fact = [
                                'statement' => $cols[0],
                                'value' => $cols[1],
                            ];
                            if (isset($cols[2]) && $cols[2] !== '') {
                                $fact['source_url'] = trim($cols[2], '()<>[]');
                            }
                            $facts[] = $fact;
                        }
                    }
                }
                return [
                    'type' => 'keyFacts',
                    'attrs' => ['facts' => $facts],
                ];

            case 'bookmark':
                $url = trim($args);
                $title = null;
                $desc = null;
                $image = null;
                foreach ($lines as $line) {
                    if (preg_match('/^title:\s*(.+)$/i', trim($line), $m)) {
                        $title = trim($m[1]);
                    } elseif (preg_match('/^description:\s*(.+)$/i', trim($line), $m)) {
                        $desc = trim($m[1]);
                    } elseif (preg_match('/^image:\s*(.+)$/i', trim($line), $m)) {
                        $image = trim($m[1]);
                    }
                }
                $attrs = ['url' => $url];
                if ($title !== null) {
                    $attrs['title'] = $title;
                }
                if ($desc !== null) {
                    $attrs['description'] = $desc;
                }
                if ($image !== null) {
                    $attrs['image'] = $image;
                }
                return [
                    'type' => 'bookmark',
                    'attrs' => $attrs,
                ];

            case 'embed':
                $src = trim($args);
                return [
                    'type' => 'embed',
                    'attrs' => ['src' => $src],
                ];

            case 'button':
                $url = '';
                $text = '';
                if (preg_match('/^\[(.*?)\]\((.*?)\)$/', trim($args), $m)) {
                    $text = $m[1];
                    $url = $m[2];
                } elseif (preg_match('/^\[(.*?)\]\s+(\S+)$/', trim($args), $m)) {
                    $text = $m[1];
                    $url = $m[2];
                } elseif (trim($args) !== '') {
                    $url = trim($args);
                    $text = trim(implode(' ', array_filter(array_map('trim', $lines))));
                } else {
                    $body = trim(implode("\n", array_filter(array_map('trim', $lines))));
                    if (preg_match('/^\[(.*?)\]\((.*?)\)$/', $body, $m)) {
                        $text = $m[1];
                        $url = $m[2];
                    }
                }
                if ($text === '') {
                    $text = 'Click Here';
                }
                return [
                    'type' => 'button',
                    'attrs' => ['text' => $text, 'url' => $url],
                ];

            default:
                return null;
        }
    }

    /**
     * Convert canonical Document JSON to clean Markdown.
     *
     * @param array<string, mixed> $document
     * @return string
     */
    public function documentToMarkdown(array $document): string
    {
        $content = $document['content'] ?? [];
        if (!is_array($content) || empty($content)) {
            return '';
        }

        $blocks = [];
        foreach ($content as $node) {
            if (!is_array($node)) {
                continue;
            }
            $rendered = $this->renderBlockNode($node);
            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderBlockNode(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $attrs = (array) ($node['attrs'] ?? []);
        /** @var list<array<string, mixed>> $content */
        $content = array_values(array_filter((array) ($node['content'] ?? []), 'is_array'));

        switch ($type) {
            case 'heading':
                $level = max(2, min(4, (int) ($attrs['level'] ?? 2)));
                $prefix = str_repeat('#', $level);
                return "{$prefix} " . $this->renderInlines($content);

            case 'paragraph':
                return $this->renderInlines($content);

            case 'blockquote':
                $inner = $this->documentToMarkdown(['content' => $content]);
                $lines = explode("\n", $inner);
                return implode("\n", array_map(fn ($l) => "> {$l}", $lines));

            case 'codeBlock':
                $lang = (string) ($attrs['language'] ?? '');
                $code = '';
                foreach ($content as $child) {
                    if (($child['type'] ?? '') === 'text') {
                        $code .= (string) ($child['text'] ?? '');
                    }
                }
                return "```{$lang}\n{$code}\n```";

            case 'horizontalRule':
                return '---';

            case 'bulletList':
                $items = [];
                foreach ($content as $item) {
                    if (($item['type'] ?? '') === 'listItem') {
                        /** @var list<array<string, mixed>> $itemContent */
                        $itemContent = array_values(array_filter((array) ($item['content'] ?? []), 'is_array'));
                        $itemText = $this->renderListItemContent($itemContent);
                        $items[] = "- {$itemText}";
                    }
                }
                return implode("\n", $items);

            case 'orderedList':
                $items = [];
                $num = (int) ($attrs['start'] ?? 1);
                foreach ($content as $item) {
                    if (($item['type'] ?? '') === 'listItem') {
                        /** @var list<array<string, mixed>> $itemContent */
                        $itemContent = array_values(array_filter((array) ($item['content'] ?? []), 'is_array'));
                        $itemText = $this->renderListItemContent($itemContent);
                        $items[] = "{$num}. {$itemText}";
                        $num++;
                    }
                }
                return implode("\n", $items);

            case 'taskList':
                $items = [];
                foreach ($content as $item) {
                    if (($item['type'] ?? '') === 'taskItem') {
                        $checked = !empty($item['attrs']['checked']) ? 'x' : ' ';
                        /** @var list<array<string, mixed>> $itemContent */
                        $itemContent = array_values(array_filter((array) ($item['content'] ?? []), 'is_array'));
                        $itemText = $this->renderListItemContent($itemContent);
                        $items[] = "- [{$checked}] {$itemText}";
                    }
                }
                return implode("\n", $items);

            case 'table':
                return $this->renderTable($content);

            case 'image':
                $alt = (string) ($attrs['alt'] ?? '');
                $src = (string) ($attrs['src'] ?? '');
                $title = isset($attrs['title']) && (string) $attrs['title'] !== '' ? ' "' . $attrs['title'] . '"' : '';
                return "![{$alt}]({$src}{$title})";

            case 'callout':
                $ctype = (string) ($attrs['type'] ?? 'info');
                $title = isset($attrs['title']) && (string) $attrs['title'] !== '' ? " [{$attrs['title']}]" : '';
                $body = $this->documentToMarkdown(['content' => $content]);
                return ":::callout {$ctype}{$title}\n{$body}\n:::";

            case 'faq':
                $faqLines = [":::faq"];
                $items = (array) ($attrs['items'] ?? []);
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['question'], $item['answer'])) {
                        $faqLines[] = "### {$item['question']}";
                        $faqLines[] = (string) $item['answer'];
                    }
                }
                $faqLines[] = ":::";
                return implode("\n", $faqLines);

            case 'keyFacts':
                $kfLines = [":::facts"];
                $facts = (array) ($attrs['facts'] ?? []);
                foreach ($facts as $fact) {
                    if (is_array($fact) && isset($fact['statement'], $fact['value'])) {
                        $source = isset($fact['source_url']) && (string) $fact['source_url'] !== '' ? " | {$fact['source_url']}" : '';
                        $kfLines[] = "- {$fact['statement']} | {$fact['value']}{$source}";
                    }
                }
                $kfLines[] = ":::";
                return implode("\n", $kfLines);

            case 'bookmark':
                $url = (string) ($attrs['url'] ?? '');
                $lines = [":::bookmark {$url}"];
                if (!empty($attrs['title'])) {
                    $lines[] = "title: {$attrs['title']}";
                }
                if (!empty($attrs['description'])) {
                    $lines[] = "description: {$attrs['description']}";
                }
                if (!empty($attrs['image'])) {
                    $lines[] = "image: {$attrs['image']}";
                }
                $lines[] = ":::";
                return implode("\n", $lines);

            case 'embed':
                $src = (string) ($attrs['src'] ?? '');
                return ":::embed {$src}\n:::";

            case 'button':
                $text = (string) ($attrs['text'] ?? 'Click Here');
                $url = (string) ($attrs['url'] ?? '#');
                return ":::button [{$text}] {$url}\n:::";

            default:
                return '';
        }
    }

    /**
     * @param list<array<string, mixed>> $inlines
     */
    private function renderInlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type === 'text') {
                $text = (string) ($node['text'] ?? '');
                /** @var list<array<string, mixed>> $marks */
                $marks = array_values(array_filter((array) ($node['marks'] ?? []), 'is_array'));
                $out .= $this->applyMarks($text, $marks);
            } elseif ($type === 'image') {
                $alt = (string) ($node['attrs']['alt'] ?? '');
                $src = (string) ($node['attrs']['src'] ?? '');
                $out .= "![{$alt}]({$src})";
            } elseif ($type === 'hardBreak') {
                $out .= "  \n";
            }
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $marks
     */
    private function applyMarks(string $text, array $marks): string
    {
        if (empty($marks)) {
            return $text;
        }

        $res = $text;
        foreach ($marks as $mark) {
            $mType = (string) ($mark['type'] ?? '');
            $mAttrs = (array) ($mark['attrs'] ?? []);
            switch ($mType) {
                case 'bold':
                    $res = "**{$res}**";
                    break;
                case 'italic':
                    $res = "*{$res}*";
                    break;
                case 'strike':
                    $res = "~~{$res}~~";
                    break;
                case 'code':
                    $res = "`{$res}`";
                    break;
                case 'link':
                    $href = (string) ($mAttrs['href'] ?? '');
                    $res = "[{$res}]({$href})";
                    break;
            }
        }
        return $res;
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function renderListItemContent(array $content): string
    {
        $parts = [];
        foreach ($content as $child) {
            $parts[] = $this->renderBlockNode($child);
        }
        return implode(' ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderTable(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $lines = [];
        $isFirst = true;

        foreach ($rows as $rowNode) {
            if (($rowNode['type'] ?? '') !== 'tableRow') {
                continue;
            }
            /** @var list<array<string, mixed>> $cells */
            $cells = array_values(array_filter((array) ($rowNode['content'] ?? []), 'is_array'));
            $cellTexts = [];
            foreach ($cells as $cell) {
                /** @var list<array<string, mixed>> $cellContent */
                $cellContent = array_values(array_filter((array) ($cell['content'] ?? []), 'is_array'));
                $cellTexts[] = $this->renderListItemContent($cellContent);
            }
            $lines[] = '| ' . implode(' | ', $cellTexts) . ' |';

            if ($isFirst) {
                $seps = array_fill(0, count($cellTexts), '---');
                $lines[] = '| ' . implode(' | ', $seps) . ' |';
                $isFirst = false;
            }
        }

        return implode("\n", $lines);
    }
}
