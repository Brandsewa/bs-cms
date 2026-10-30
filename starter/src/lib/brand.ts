import type { BrandingTokens } from './bs';

/**
 * Curated font stacks for branding font keys. Web-safe/system pairings —
 * no remote font loading, so CSP and offline builds stay clean.
 */
export const FONT_STACKS: Record<string, string> = {
  'serif-display': "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', serif",
  'sans-modern': "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
  'sans-humanist': "'Segoe UI', Frutiger, 'Helvetica Neue', 'Apple Color Emoji', sans-serif",
  'serif-body': "'Iowan Old Style', 'Palatino Linotype', Georgia, 'Times New Roman', serif",
  'mono-technical': "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace",
};

const RADIUS: Record<string, string> = {
  none: '0px',
  small: '4px',
  medium: '10px',
  large: '16px',
  pill: '999px',
};

/** Relative luminance (WCAG) of a hex color, 0 (dark) to 1 (light). */
function luminance(hex: string): number | null {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return null;
  const n = parseInt(m[1], 16);
  const channel = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
}

/** Picks readable text (#fff or a near-black) for a given background color. */
export function readableOn(hex: string | undefined | null, fallback = '#ffffff'): string {
  const lum = hex ? luminance(hex) : null;
  if (lum === null) return fallback;
  return lum > 0.45 ? '#1c2a22' : '#ffffff';
}

const val = (v: string | undefined | null): string => (v || '').trim();

/** CSS declarations for one theme's palette + element tokens. */
function themeVars(t: BrandingTokens, theme: 'light' | 'dark'): string[] {
  // Schema field keys per theme
  const k: Record<string, string> = theme === 'light'
    ? {
        primary: 'color_primary',
        secondary: 'color_secondary',
        accent: 'color_accent',
        background: 'color_background',
        heading: 'color_heading',
        body_text: 'color_text',
        button_bg: 'color_button_bg',
        button_text: 'color_button_text',
        card_bg: 'color_card_bg',
        card_text: 'color_card_text',
      }
    : {
        primary: 'dark_primary',
        secondary: 'dark_secondary',
        accent: 'dark_accent',
        background: 'dark_background',
        heading: 'dark_heading',
        body_text: 'dark_body_text',
        button_bg: 'dark_button_bg',
        button_text: 'dark_button_text',
        card_bg: 'dark_card_bg',
        card_text: 'dark_card_text',
      };
  const get = (name: string): string => val(t[k[name] as keyof BrandingTokens] as string);
  const lines: string[] = [];
  const push = (name: string, value: string, transform?: (v: string) => string) => {
    if (!value) return;
    lines.push(`  ${name}: ${transform ? transform(value) : value};`);
  };

  push('--color-primary', get('primary'));
  push('--color-secondary', get('secondary'));
  push('--color-accent', get('accent'));
  push('--color-bg', get('background'));
  push('--color-heading', get('heading'));
  push('--color-text', get('body_text'));
  push('--color-text-secondary', get('body_text'), (v) => `color-mix(in srgb, ${v} 78%, var(--color-bg))`);
  push('--color-muted', get('body_text'), (v) => `color-mix(in srgb, ${v} 55%, var(--color-bg))`);
  push('--color-border', get('body_text'), (v) => `color-mix(in srgb, ${v} 16%, var(--color-bg))`);
  push('--color-surface', get('card_bg'));
  push('--btn-bg', get('button_bg'));
  push('--btn-fg', get('button_text'), (v) => v || readableOn(get('button_bg')));
  return lines;
}

function borderVars(t: BrandingTokens, group: 'button' | 'card' | 'image'): string[] {
  const lines: string[] = [];
  const radius = RADIUS[val(t[`${group}_radius` as keyof BrandingTokens] as string) || ''];
  if (radius) lines.push(`  --${group}-radius: ${radius};`);
  const bw = val(t[`${group}_border_width` as keyof BrandingTokens] as string);
  if (bw) lines.push(`  --${group}-bw: ${bw === 'none' ? '0' : bw};`);
  const bc = val(t[`${group}_border_color` as keyof BrandingTokens] as string);
  if (bc) lines.push(`  --${group}-bc: ${bc};`);
  return lines;
}

function typeScale(t: BrandingTokens, device: 'desktop' | 'tablet' | 'mobile'): string[] {
  const lines: string[] = [];
  let any = false;
  const mediaOpen = device !== 'desktop' ? (device === 'tablet' ? '@media (max-width: 1024px) {' : '@media (max-width: 640px) {') : '';
  if (mediaOpen) lines.push(mediaOpen);
  for (let i = 1; i <= 6; i++) {
    const size = val(t[`fs_h${i}_${device}` as keyof BrandingTokens] as string);
    if (size) {
      lines.push(`  h${i} { font-size: ${size}; }`);
      any = true;
    }
  }
  if (mediaOpen) lines.push('}');
  return any ? lines : [];
}

/**
 * Builds the branding `<style>` block: light palette (default), dark palette
 * inside prefers-color-scheme, element token hooks, site width and the
 * responsive heading scale. Only tokens the user set are emitted.
 */
export function brandCssVars(branding?: BrandingTokens | null): string {
  if (!branding) return '';
  const blocks: string[] = [];

  // Light (default)
  const light = [
    ...themeVars(branding, 'light'),
    ...borderVars(branding, 'button'),
    ...borderVars(branding, 'card'),
    ...borderVars(branding, 'image'),
  ];
  const heading = FONT_STACKS[branding.font_heading || ''];
  if (heading) light.push(`  --font-serif: ${heading};`);
  const body = FONT_STACKS[branding.font_body || ''];
  if (body) light.push(`  --font-sans: ${body};`);
  const siteWidth = val(branding.site_width);
  if (siteWidth) light.push(`  --site-width: ${siteWidth};`);
  if (light.length > 0) blocks.push(`:root:root {\n${light.join('\n')}\n}`);

  // Dark (system preference)
  const dark = themeVars(branding, 'dark');
  if (dark.length > 0) {
    blocks.push(`@media (prefers-color-scheme: dark) {\n:root:root {\n${dark.join('\n')}\n}\n}`);
  }

  // Element hooks: buttons, cards, images, headings, site width
  const hooks: string[] = [];
  hooks.push(`html .enquire-btn, .footer-btn, .btn-brand {
  background: var(--btn-bg, transparent);
  color: var(--btn-fg, var(--color-text));
  border: var(--btn-bw, 1px) solid var(--btn-bc, currentColor);
  border-radius: var(--btn-radius, 2px);
}`);
  hooks.push(`html .post-card, html .icard, html .card-token {
  background: var(--color-surface, var(--color-surface));
  border: var(--card-bw, 1px) solid var(--card-bc, var(--color-border));
  border-radius: var(--card-radius, 10px);
}`);
  hooks.push(`html .article img, html .post-content img, html .hero-img, html .rcard img {
  border-radius: var(--image-radius, 10px);
  border: var(--image-bw, 0) solid var(--image-bc, transparent);
}`);
  hooks.push(`main, .site-nav, .footer-grid { max-width: var(--site-width, 1380px); }`);
  if (branding.color_heading) hooks.push(`h1, h2, h3, h4, h5, h6 { color: var(--color-heading); }`);
  blocks.push(hooks.join('\n'));

  // Responsive heading scale
  const desktop = typeScale(branding, 'desktop');
  const tablet = typeScale(branding, 'tablet');
  const mobile = typeScale(branding, 'mobile');
  if (desktop.length > 0) blocks.push(desktop.join('\n'));
  if (tablet.length > 0) blocks.push(tablet.join('\n'));
  if (mobile.length > 0) blocks.push(mobile.join('\n'));

  return blocks.join('\n\n');
}
