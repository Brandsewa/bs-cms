/**
 * BS CMS Privacy-Friendly Embed Loader (Classic Script)
 * Compliant with Content-Security-Policy: script-src 'self'
 * Zero third-party scripts loaded at build time or page load.
 * Only loads iframes upon explicit user activation (click).
 */
(function (global) {
  'use strict';

  var ALLOWED_EMBED_DOMAINS = [
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

  function isAllowedEmbedOrigin(rawUrl) {
    if (!rawUrl || typeof rawUrl !== 'string') return false;
    var trimmed = rawUrl.trim();
    if (!trimmed.startsWith('https://')) return false;

    try {
      var parsed = new URL(trimmed);
      if (parsed.protocol !== 'https:') return false;
      var hostname = parsed.hostname.toLowerCase();
      for (var i = 0; i < ALLOWED_EMBED_DOMAINS.length; i++) {
        var domain = ALLOWED_EMBED_DOMAINS[i];
        if (hostname === domain || hostname.endsWith('.' + domain)) {
          return true;
        }
      }
      return false;
    } catch (e) {
      return false;
    }
  }

  function handleEmbedActivation(targetElement) {
    if (!targetElement || typeof targetElement.closest !== 'function') return;
    var container = targetElement.closest('.embed-placeholder');
    if (!container) return;

    var src = container.getAttribute('data-embed-src');
    if (!src || !isAllowedEmbedOrigin(src)) {
      console.warn('[BS Embed] Blocked unauthorized or insecure embed source:', src);
      return;
    }

    var doc = container.ownerDocument || (typeof document !== 'undefined' ? document : null);
    if (!doc) return;

    var iframe = doc.createElement('iframe');
    iframe.setAttribute('src', src);
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('allowfullscreen', 'true');
    iframe.setAttribute(
      'allow',
      'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
    );
    iframe.className = 'embed-iframe';
    iframe.loading = 'lazy';

    container.innerHTML = '';
    container.appendChild(iframe);
  }

  function initEmbedLoader(root) {
    var doc = root || (typeof document !== 'undefined' ? document : null);
    if (!doc || typeof doc.addEventListener !== 'function') return;

    doc.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || typeof target !== 'object') return;

      var isButton = false;
      if (target.classList && target.classList.contains('embed-load-button')) {
        isButton = true;
      } else if (typeof target.closest === 'function' && target.closest('.embed-load-button')) {
        isButton = true;
      }

      if (isButton) {
        if (typeof event.preventDefault === 'function') {
          event.preventDefault();
        }
        handleEmbedActivation(target);
      }
    });
  }

  var BSEmbed = {
    ALLOWED_EMBED_DOMAINS: ALLOWED_EMBED_DOMAINS,
    isAllowedEmbedOrigin: isAllowedEmbedOrigin,
    handleEmbedActivation: handleEmbedActivation,
    initEmbedLoader: initEmbedLoader,
  };

  // Attach to window / global
  if (typeof global !== 'undefined') {
    global.BSEmbed = BSEmbed;
  }

  // Node / CommonJS environment support for tests
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = BSEmbed;
  }

  // Auto-initialize when running in browser
  if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        initEmbedLoader(document);
      });
    } else {
      initEmbedLoader(document);
    }
  }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : this);
