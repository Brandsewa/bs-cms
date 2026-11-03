<?php

declare(strict_types=1);

namespace BsCms\Site;

/**
 * PHP port of the starter's BaseLayout + brand.ts: the shared HTML shell
 * (head/meta/nav/footer) and the branding CSS-variable style block. Markup
 * and class names match the Astro output; the hard-coded business copy from
 * the demo starter (seed-shop footer links, Enquire button) is generalized
 * to be content-driven instead.
 */
final class PageLayout
{
    private const FONT_STACKS = [
        'serif-display' => "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', serif",
        'sans-modern' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
        'sans-humanist' => "'Segoe UI', Frutiger, 'Helvetica Neue', 'Apple Color Emoji', sans-serif",
        'serif-body' => "'Iowan Old Style', 'Palatino Linotype', Georgia, 'Times New Roman', serif",
        'mono-technical' => "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace",
    ];

    private const RADIUS = ['none' => '0px', 'small' => '4px', 'medium' => '10px', 'large' => '16px', 'pill' => '999px'];

    /**
     * @param array<string, mixed> $site site data from SiteDataProvider
     * @param array<string, mixed>|null $entry
     */
    public static function render(
        array $site,
        string $title,
        string $bodyHtml,
        string $path,
        ?array $entry = null
    ): string {
        $brand = is_array($site['branding'] ?? null) ? $site['branding'] : null;
        $siteName = (string) $site['site_name'];
        $tagline = (string) ($site['tagline'] ?? '');
        $entryDescription = is_array($entry) ? (string) ($entry['seo_description'] ?? '') : '';
        $description = $entryDescription !== ''
            ? $entryDescription
            : (string) ($site['description'] ?? '');
        $canonical = self::canonicalUrl($site, $path);
        $lang = (string) ($site['default_language'] ?? 'en');
        $logo = (string) ($brand['logo_url'] ?? '');
        $logoDark = (string) ($brand['logo_dark_url'] ?? $logo);
        $logoAlt = (string) ($brand['logo_alt'] ?? $siteName . ' logo');
        $favicon = (string) ($brand['favicon_url'] ?? '');

        $brandCss = self::brandCssVars($brand);
        $onPrimary = self::readableOn((string) ($brand['color_primary'] ?? ''));
        $hoverCss = '';
        if ($brand !== null && ($brand['color_primary'] ?? '') !== '') {
            $primary = TiptapRenderer::escapeHtml((string) $brand['color_primary']);
            $hoverCss = "\n    <style>.enquire-btn:hover, .footer-btn:hover { background: {$primary}; border-color: {$primary}; color: {$onPrimary}; }</style>";
        }

        $esc = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
        $faviconTag = $favicon !== '' ? "\n    <link rel=\"icon\" href=\"{$esc($favicon)}\" />" : '';
        $robotsEntry = is_array($entry['seo_robots'] ?? null) ? $entry['seo_robots'] : null;
        $robots = ($robotsEntry['noindex'] ?? false) ? 'noindex' : 'index';
        $robots .= (is_array($robotsEntry) && ($robotsEntry['nofollow'] ?? false)) ? ', nofollow' : ', follow';
        $robots .= ', max-image-preview:large';

        $brandHtml = self::renderBrand($siteName, $logo, $logoAlt);
        $navHtml = self::renderNav($site);
        $footerBrandHtml = self::renderFooterBrand($siteName, $tagline, $logoDark, $logoAlt);
        $footerLinksHtml = self::renderFooterLinks($site);
        $year = date('Y');

        return <<<HTML
<!doctype html>
<html lang="{$esc($lang)}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$esc($title)}</title>
    <meta name="description" content="{$esc($description)}" />
    <meta name="robots" content="{$robots}" />
    <link rel="canonical" href="{$esc($canonical)}" />{$faviconTag}
    <link rel="stylesheet" href="/site-assets/tokens.css" />
    <link rel="stylesheet" href="/site-assets/page-builder.css" />{$hoverCss}
    <style>{$brandCss}</style>

    <meta property="og:title" content="{$esc($title)}" />
    <meta property="og:description" content="{$esc($description)}" />
    <meta property="og:url" content="{$esc($canonical)}" />
    <meta property="og:type" content="website" />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="{$esc($title)}" />
    <meta name="twitter:description" content="{$esc($description)}" />
  </head>
  <body>
    <header>
      <nav class="site-nav" aria-label="Main Navigation">
        <a href="/" class="site-brand">
{$brandHtml}
        </a>
        <ul class="nav-links">
{$navHtml}
        </ul>
      </nav>
    </header>

    <main>
{$bodyHtml}
    </main>

    <footer>
      <div class="footer-grid">
        <div class="footer-brand">
{$footerBrandHtml}
        </div>
        <div class="footer-col">
          <p class="footer-h">Quick Links</p>
          <ul>
{$footerLinksHtml}
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        <p>&copy; {$year} {$esc($siteName)}. All rights reserved.</p>
        <div class="footer-links">
          <a href="/sitemap.xml">Sitemap</a>
        </div>
      </div>
    </footer>

    <script src="/site-assets/embed.js"></script>
  </body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $site
     */
    public static function render404(array $site, string $path): string
    {
        $siteName = TiptapRenderer::escapeHtml((string) $site['site_name']);
        $body = <<<HTML
  <section class="bs-hero">
    <div class="bs-hero__inner">
      <h1 class="bs-hero__heading">Page not found</h1>
      <p class="bs-hero__text">The page you requested could not be found. It may have been unpublished or moved.</p>
      <a class="bs-hero__cta bs-btn" href="/">Back to home</a>
    </div>
  </section>
HTML;

        return self::render($site, "Page Not Found | {$siteName}", $body, $path, ['seo_robots' => ['noindex' => true, 'nofollow' => true]]);
    }

    private static function renderBrand(string $siteName, string $logo, string $logoAlt): string
    {
        $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
        if ($logo !== '') {
            return "\n          <img class=\"brand-logo\" src=\"{$e($logo)}\" alt=\"{$e($logoAlt)}\" />\n        ";
        }

        return "\n          <span class=\"brand-leaf\" aria-hidden=\"true\">&#10087;</span>\n          <span class=\"brand-text\"><span class=\"brand-name\">{$e(mb_strtoupper($siteName))}</span></span>\n        ";
    }

    /**
     * @param array<string, mixed> $site
     */
    private static function renderNav(array $site): string
    {
        $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
        $html = '';
        foreach ($site['navigation'] ?? [] as $item) {
            $target = !empty($item['target'])
                ? " target=\"{$e($item['target'])}\" rel=\"noopener noreferrer\"" : '';
            $html .= "          <li><a href=\"{$e($item['url'])}\"{$target}>{$e($item['label'])}</a></li>\n";
        }

        return $html;
    }

    private static function renderFooterBrand(string $siteName, string $tagline, string $logoDark, string $logoAlt): string
    {
        $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
        $blurb = $tagline !== '' ? "\n          <p class=\"footer-blurb\">{$e($tagline)}</p>" : '';
        if ($logoDark !== '') {
            return "\n          <img class=\"footer-logo-img\" src=\"{$e($logoDark)}\" alt=\"{$e($logoAlt)}\" />{$blurb}\n        ";
        }

        return "\n          <p class=\"footer-logo\">&#10087; {$e(mb_strtoupper($siteName))}</p>{$blurb}\n        ";
    }

    /**
     * @param array<string, mixed> $site
     */
    private static function renderFooterLinks(array $site): string
    {
        $e = fn ($v) => TiptapRenderer::escapeHtml((string) ($v ?? ''));
        $html = '';
        foreach (array_slice($site['navigation'] ?? [], 0, 5) as $item) {
            $html .= "            <li><a href=\"{$e($item['url'])}\">{$e($item['label'])}</a></li>\n";
        }

        return $html;
    }

    /** @param array<string, mixed> $site */
    private static function canonicalUrl(array $site, string $path): string
    {
        $base = rtrim((string) ($site['canonical_url'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }
        $trimmed = '/' . trim($path, '/');

        return $base . ($trimmed === '/' ? '/' : $trimmed . '/');
    }

    /**
     * Port of brand.ts brandCssVars — light palette, dark palette under
     * prefers-color-scheme, element hooks and the responsive heading scale.
     *
     * @param array<string, mixed>|null $brand
     */
    /**
     * @param array<string, mixed>|null $brand
     */
    public static function brandCssVars(?array $brand): string
    {
        if ($brand === null) {
            return '';
        }
        $blocks = [];

        $light = self::themeVars($brand, false);
        foreach (['button', 'card', 'image'] as $group) {
            $light = [...$light, ...self::borderVars($brand, $group)];
        }
        $headingFont = self::FONT_STACKS[(string) ($brand['font_heading'] ?? '')] ?? null;
        if ($headingFont !== null) {
            $light[] = "  --font-serif: {$headingFont};";
        }
        $bodyFont = self::FONT_STACKS[(string) ($brand['font_body'] ?? '')] ?? null;
        if ($bodyFont !== null) {
            $light[] = "  --font-sans: {$bodyFont};";
        }
        $siteWidth = trim((string) ($brand['site_width'] ?? ''));
        if ($siteWidth !== '') {
            $light[] = "  --site-width: {$siteWidth};";
        }
        if ($light !== []) {
            $blocks[] = ":root:root {\n" . implode("\n", $light) . "\n}";
        }

        $dark = self::themeVars($brand, true);
        if ($dark !== []) {
            $blocks[] = "@media (prefers-color-scheme: dark) {\n:root:root {\n" . implode("\n", $dark) . "\n}\n}";
        }

        $blocks[] = <<<'CSS'
html .enquire-btn, .footer-btn, .btn-brand {
  background: var(--btn-bg, transparent);
  color: var(--btn-fg, var(--color-text));
  border: var(--btn-bw, 1px) solid var(--btn-bc, currentColor);
  border-radius: var(--btn-radius, 2px);
}
html .post-card, html .icard, html .card-token {
  background: var(--color-surface, var(--color-surface));
  border: var(--card-bw, 1px) solid var(--card-bc, var(--color-border));
  border-radius: var(--card-radius, 10px);
}
html .article img, html .post-content img, html .hero-img, html .rcard img {
  border-radius: var(--image-radius, 10px);
  border: var(--image-bw, 0) solid var(--image-bc, transparent);
}
main, .site-nav, .footer-grid { max-width: var(--site-width, 1380px); }
CSS;
        if (($brand['color_heading'] ?? '') !== '') {
            $blocks[] = 'h1, h2, h3, h4, h5, h6 { color: var(--color-heading); }';
        }

        foreach (['desktop' => null, 'tablet' => '1024px', 'mobile' => '640px'] as $device => $maxWidth) {
            $lines = [];
            if ($maxWidth !== null) {
                $lines[] = "@media (max-width: {$maxWidth}px) {";
            }
            for ($i = 1; $i <= 6; $i++) {
                $size = trim((string) ($brand["fs_h{$i}_{$device}"] ?? ''));
                if ($size !== '') {
                    $lines[] = "  h{$i} { font-size: {$size}; }";
                }
            }
            if ($maxWidth !== null) {
                $lines[] = '}';
            }
            if (count($lines) > ($maxWidth !== null ? 2 : 0)) {
                $blocks[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<string, mixed> $brand */
    /**
     * @param array<string, mixed> $brand
     * @return list<string>
     */
    private static function themeVars(array $brand, bool $dark): array
    {
        // Field keys per theme (dark body text is dark_body_text, matching brand.ts).
        $keys = $dark
            ? ['primary' => 'dark_primary', 'secondary' => 'dark_secondary', 'accent' => 'dark_accent',
               'background' => 'dark_background', 'heading' => 'dark_heading', 'text' => 'dark_body_text',
               'button_bg' => 'dark_button_bg', 'button_text' => 'dark_button_text',
               'card_bg' => 'dark_card_bg', 'card_text' => 'dark_card_text']
            : ['primary' => 'color_primary', 'secondary' => 'color_secondary', 'accent' => 'color_accent',
               'background' => 'color_background', 'heading' => 'color_heading', 'text' => 'color_text',
               'button_bg' => 'color_button_bg', 'button_text' => 'color_button_text',
               'card_bg' => 'color_card_bg', 'card_text' => 'color_card_text'];
        $get = fn (string $key): string => trim((string) ($brand[$keys[$key]] ?? ''));
        $lines = [];
        $push = function (string $name, string $value, ?\Closure $transform = null) use (&$lines): void {
            if ($value === '') {
                return;
            }
            $lines[] = "  {$name}: " . ($transform !== null ? $transform($value) : $value) . ';';
        };

        $push('--color-primary', $get('primary'));
        $push('--color-secondary', $get('secondary'));
        $push('--color-accent', $get('accent'));
        $push('--color-bg', $get('background'));
        $push('--color-heading', $get('heading'));
        $push('--color-text', $get('text'));
        $push('--color-text-secondary', $get('text'), fn ($v) => "color-mix(in srgb, {$v} 78%, var(--color-bg))");
        $push('--color-muted', $get('text'), fn ($v) => "color-mix(in srgb, {$v} 55%, var(--color-bg))");
        $push('--color-border', $get('text'), fn ($v) => "color-mix(in srgb, {$v} 16%, var(--color-bg))");
        $push('--color-surface', $get('card_bg'));
        $push('--btn-bg', $get('button_bg'));
        $push('--btn-fg', $get('button_text'), fn ($v) => $v !== '' ? $v : self::readableOn($get('button_bg')));

        return $lines;
    }

    /** @param array<string, mixed> $brand */
    /**
     * @param array<string, mixed> $brand
     * @return list<string>
     */
    private static function borderVars(array $brand, string $group): array
    {
        $lines = [];
        $radius = self::RADIUS[trim((string) ($brand[$group . '_radius'] ?? ''))] ?? null;
        if ($radius !== null) {
            $lines[] = "  --{$group}-radius: {$radius};";
        }
        $bw = trim((string) ($brand[$group . '_border_width'] ?? ''));
        if ($bw !== '') {
            $lines[] = '  --' . $group . "-bw: " . ($bw === 'none' ? '0' : $bw) . ';';
        }
        $bc = trim((string) ($brand[$group . '_border_color'] ?? ''));
        if ($bc !== '') {
            $lines[] = "  --{$group}-bc: {$bc};";
        }

        return $lines;
    }

    public static function readableOn(string $hex): string
    {
        $hex = trim($hex);
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) {
            return '#ffffff';
        }
        $n = hexdec($m[1]);
        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $lum = 0.2126 * $channel(($n >> 16) & 255) + 0.7152 * $channel(($n >> 8) & 255) + 0.0722 * $channel($n & 255);

        return $lum > 0.45 ? '#1c2a22' : '#ffffff';
    }
}
