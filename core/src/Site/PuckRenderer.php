<?php

declare(strict_types=1);

namespace BsCms\Site;

/**
 * PHP port of @bs/page-builder's server-side Puck renderer. Emits the exact
 * same `.bs-*` markup as the React components so the on-the-fly site renderer
 * produces pages visually identical to the Astro static build. Unknown block
 * types are dropped (same contract as sanitizePuckData).
 */
final class PuckRenderer
{
    /** @param mixed $data PuckData {content:[{type,props}],root} */
    public function render($data): string
    {
        if (!is_array($data) || !isset($data['content']) || !is_array($data['content'])) {
            return '';
        }

        $blocks = [];
        foreach ($data['content'] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html = $this->block((string) ($block['type'] ?? ''), is_array($block['props'] ?? null) ? $block['props'] : []);
            if ($html !== '') {
                $blocks[] = $html;
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function block(string $type, array $props): string
    {
        $id = isset($props['id']) ? ' id="' . TiptapRenderer::escapeHtml((string) $props['id']) . '"' : '';
        $str = fn (string $key): string => TiptapRenderer::escapeHtml((string) ($props[$key] ?? ''));

        switch ($type) {
            case 'Hero':
                $cta = ($props['ctaLabel'] ?? '') !== ''
                    ? "\n      <a class=\"bs-hero__cta bs-btn\" href=\"{$str('ctaUrl')}\">{$str('ctaLabel')}</a>\n    " : '';
                $text = ($props['text'] ?? '') !== '' ? "\n      <p class=\"bs-hero__text\">{$str('text')}</p>" : '';

                return "<section{$id} class=\"bs-hero\">\n    <div class=\"bs-hero__inner\">\n      <h1 class=\"bs-hero__heading\">{$str('heading')}</h1>{$text}{$cta}\n    </div>\n  </section>";

            case 'RichText':
                // Trusted CMS-authored HTML, verbatim (dangerouslySetInnerHTML parity).
                return '<div class="bs-richtext">' . (string) ($props['html'] ?? '') . '</div>';

            case 'Callout':
                $variant = in_array(($props['variant'] ?? ''), ['info', 'tip', 'warning', 'note'], true)
                    ? (string) $props['variant'] : 'info';
                $title = ($props['title'] ?? '') !== '' ? "\n      <p class=\"bs-callout__title\">{$str('title')}</p>" : '';

                return "<aside class=\"bs-callout bs-callout--{$variant}\">{$title}\n      <div class=\"bs-callout__content\">{$str('content')}</div>\n    </aside>";

            case 'Faq':
                $itemsHtml = [];
                foreach (is_array($props['items'] ?? null) ? $props['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $q = TiptapRenderer::escapeHtml((string) ($item['question'] ?? ''));
                    $a = TiptapRenderer::escapeHtml((string) ($item['answer'] ?? ''));
                    $itemsHtml[] = "    <li class=\"bs-faq__item\">\n      <h3 class=\"bs-faq__question\">{$q}</h3>\n      <div class=\"bs-faq__answer\">{$a}</div>\n    </li>";
                }

                return "<section class=\"bs-faq\">\n  <ul class=\"bs-faq__list\">\n" . implode("\n", $itemsHtml) . "\n  </ul>\n</section>";

            case 'CtaBanner':
                $align = in_array(($props['align'] ?? ''), ['left', 'center'], true) ? (string) $props['align'] : 'center';
                $text = ($props['text'] ?? '') !== '' ? "\n      <p class=\"bs-cta__text\">{$str('text')}</p>" : '';
                $btn = ($props['buttonLabel'] ?? '') !== ''
                    ? "\n      <a class=\"bs-cta__button bs-btn\" href=\"{$str('buttonUrl')}\">{$str('buttonLabel')}</a>" : '';

                return "<section{$id} class=\"bs-cta bs-cta--{$align}\">\n    <div class=\"bs-cta__inner\">\n      <h2 class=\"bs-cta__heading\">{$str('heading')}</h2>{$text}{$btn}\n    </div>\n  </section>";

            case 'FeaturesGrid':
                $columns = in_array((string) ($props['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $props['columns'] : '3';
                $itemsHtml = [];
                foreach (is_array($props['items'] ?? null) ? $props['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $t = TiptapRenderer::escapeHtml((string) ($item['title'] ?? ''));
                    $desc = TiptapRenderer::escapeHtml((string) ($item['description'] ?? ''));
                    $itemsHtml[] = "    <li class=\"bs-features__item\">\n      <h3 class=\"bs-features__title\">{$t}</h3>\n      <p class=\"bs-features__description\">{$desc}</p>\n    </li>";
                }

                return "<section{$id} class=\"bs-features bs-features--{$columns}\">\n  <ul class=\"bs-features__grid\">\n" . implode("\n", $itemsHtml) . "\n  </ul>\n</section>";

            case 'ImageBlock':
                $src = (string) ($props['src'] ?? '');
                $img = $src !== ''
                    ? "\n    <img class=\"bs-image__img\" src=\"{$str('src')}\" alt=\"{$str('alt')}\" loading=\"lazy\" />" : '';
                $caption = ($props['caption'] ?? '') !== ''
                    ? "\n    <figcaption class=\"bs-image__caption\">{$str('caption')}</figcaption>" : '';

                return "<figure{$id} class=\"bs-image\">{$img}{$caption}\n  </figure>";

            case 'Spacer':
                $height = max(0, (int) ($props['height'] ?? 64));

                return "<div class=\"bs-spacer\" style=\"height:{$height}px\" aria-hidden=\"true\"></div>";

            case 'ContactForm':
                $handle = ($props['formHandle'] ?? '') !== '' ? (string) $props['formHandle'] : 'contact-us';
                $handleAttr = TiptapRenderer::escapeHtml($handle);
                $heading = ($props['heading'] ?? '') !== ''
                    ? "\n      <h2 class=\"bs-contact-form__heading\">{$str('heading')}</h2>" : '';
                $sub = ($props['subheading'] ?? '') !== ''
                    ? "\n      <p class=\"bs-contact-form__subheading\">{$str('subheading')}</p>" : '';

                return "<section{$id} class=\"bs-contact-form\">\n    <div class=\"bs-contact-form__inner\">{$heading}{$sub}\n      <div class=\"bs-contact-form__placeholder\" data-bs-form=\"{$handleAttr}\">\n        <p class=\"bs-contact-form__placeholder-title\">Contact form</p>\n        <p class=\"bs-contact-form__placeholder-handle\">Embeds the published form &ldquo;{$handleAttr}&rdquo;</p>\n      </div>\n    </div>\n  </section>";

            default:
                // Unknown block type — dropped, matching sanitizePuckData.
                return '';
        }
    }
}
