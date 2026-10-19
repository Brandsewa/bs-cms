/**
 * BS CMS Privacy-Friendly Embed Loader
 * Compliant with Content-Security-Policy: script-src 'self'
 * Zero third-party scripts loaded at build time or page load.
 * Only loads iframes upon explicit user activation (click).
 */

const ALLOWED_EMBED_DOMAINS = [
  'youtube.com',
  'www.youtube.com',
  'youtube-nocookie.com',
  'www.youtube-nocookie.com',
  'youtu.be',
  'vimeo.com',
  'player.vimeo.com',
  'twitter.com',
  'x.com',
  'open.spotify.com',
  'soundcloud.com',
  'w.soundcloud.com',
  'codepen.io',
];

export function isAllowedEmbedOrigin(rawUrl) {
  if (!rawUrl || typeof rawUrl !== 'string') return false;
  const trimmed = rawUrl.trim();
  if (!trimmed.startsWith('https://')) return false;

  try {
    const parsed = new URL(trimmed);
    if (parsed.protocol !== 'https:') return false;
    const hostname = parsed.hostname.toLowerCase();
    return ALLOWED_EMBED_DOMAINS.some(
      (domain) => hostname === domain || hostname.endsWith(`.${domain}`)
    );
  } catch {
    return false;
  }
}

export function handleEmbedActivation(targetElement) {
  const container = targetElement.closest('.embed-placeholder');
  if (!container) return;

  const src = container.getAttribute('data-embed-src');
  if (!src || !isAllowedEmbedOrigin(src)) {
    console.warn('[BS Embed] Blocked unauthorized or insecure embed source:', src);
    return;
  }

  // Create iframe programmatically using container's owner document
  const doc = container.ownerDocument || document;
  const iframe = doc.createElement('iframe');
  iframe.setAttribute('src', src);
  iframe.setAttribute('frameborder', '0');
  iframe.setAttribute('allowfullscreen', 'true');
  iframe.setAttribute(
    'allow',
    'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
  );
  iframe.className = 'embed-iframe';
  iframe.loading = 'lazy';

  // Replace placeholder container content with the safe iframe
  container.innerHTML = '';
  container.appendChild(iframe);
}

export function initEmbedLoader(root = document) {
  root.addEventListener('click', (event) => {
    const target = event.target;
    if (
      target &&
      typeof target === 'object' &&
      'classList' in target &&
      (target.classList.contains('embed-load-button') || (target.closest && target.closest('.embed-load-button')))
    ) {
      event.preventDefault();
      handleEmbedActivation(target);
    }
  });
}

// Auto-initialize when running in browser
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initEmbedLoader(document));
  } else {
    initEmbedLoader(document);
  }
}
