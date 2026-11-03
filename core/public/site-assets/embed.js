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

  function initContactForms(root) {
    var doc = root || (typeof document !== 'undefined' ? document : null);
    if (!doc || typeof doc.querySelectorAll !== 'function') return;

    var containers = doc.querySelectorAll('[data-bs-form]');
    for (var i = 0; i < containers.length; i++) {
      (function (container) {
        if (container.getAttribute('data-bs-form-initialized')) return;
        container.setAttribute('data-bs-form-initialized', 'true');

        var handle = container.getAttribute('data-bs-form');
        if (!handle) return;

        // Render loading state
        container.innerHTML = '<div style="padding: 1rem; color: #6b7280; font-size: 0.9rem;">Loading form...</div>';

        var apiUrl = '/api/v1/public/forms/' + encodeURIComponent(handle);
        fetch(apiUrl)
          .then(function (res) {
            if (!res.ok) throw new Error('Form not found or unpublished');
            return res.json();
          })
          .then(function (json) {
            var formDef = json.data;
            if (!formDef) throw new Error('Invalid form response');

            var formHtml = '<form class="bs-dynamic-form" style="display:flex; flex-direction:column; gap:1rem;">';
            
            // Honeypot field (hidden from real users)
            formHtml += '<div style="display:none !important;" aria-hidden="true">' +
              '<input type="text" name="_hp_check" tabindex="-1" autocomplete="off" />' +
              '</div>';

            var fields = formDef.fields || [];
            for (var j = 0; j < fields.length; j++) {
              var f = fields[j];
              var reqMark = f.required ? ' <span style="color:#ef4444;">*</span>' : '';
              formHtml += '<div class="bs-form-group" style="display:flex; flex-direction:column; gap:0.25rem;">';
              formHtml += '<label style="font-size:0.875rem; font-weight:500;">' + (f.label || f.name) + reqMark + '</label>';

              if (f.type === 'textarea') {
                formHtml += '<textarea name="' + f.name + '" ' + (f.required ? 'required' : '') +
                  ' placeholder="' + (f.placeholder || '') + '" rows="4"' +
                  ' style="width:100%; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:6px; font-family:inherit; font-size:0.9rem; background:transparent; color:inherit;"></textarea>';
              } else if (f.type === 'select') {
                formHtml += '<select name="' + f.name + '" ' + (f.required ? 'required' : '') +
                  ' style="width:100%; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:6px; font-family:inherit; font-size:0.9rem; background:transparent; color:inherit;">';
                var options = f.options || [];
                for (var o = 0; o < options.length; o++) {
                  formHtml += '<option value="' + options[o] + '">' + options[o] + '</option>';
                }
                formHtml += '</select>';
              } else {
                var inputType = f.type === 'email' ? 'email' : (f.type === 'phone' ? 'tel' : 'text');
                formHtml += '<input type="' + inputType + '" name="' + f.name + '" ' + (f.required ? 'required' : '') +
                  ' placeholder="' + (f.placeholder || '') + '"' +
                  ' style="width:100%; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:6px; font-family:inherit; font-size:0.9rem; background:transparent; color:inherit;" />';
              }

              formHtml += '<span class="bs-field-error" style="color:#ef4444; font-size:0.8rem; display:none;"></span>';
              formHtml += '</div>';
            }

            formHtml += '<div class="bs-form-status" style="display:none; padding:0.75rem; border-radius:6px; font-size:0.875rem;"></div>';
            formHtml += '<button type="submit" class="btn btn-brand" style="align-self:flex-start; padding:0.6rem 1.25rem; font-weight:600; cursor:pointer;">' +
              (formDef.submit_label || 'Send Message') +
              '</button>';
            formHtml += '</form>';

            container.innerHTML = formHtml;

            var formEl = container.querySelector('form');
            var statusEl = container.querySelector('.bs-form-status');

            formEl.addEventListener('submit', function (ev) {
              ev.preventDefault();

              var btn = formEl.querySelector('button[type=submit]');
              if (btn) {
                btn.disabled = true;
                btn.textContent = 'Sending...';
              }

              // Clear previous errors
              var errorSpans = formEl.querySelectorAll('.bs-field-error');
              for (var k = 0; k < errorSpans.length; k++) {
                errorSpans[k].textContent = '';
                errorSpans[k].style.display = 'none';
              }
              if (statusEl) statusEl.style.display = 'none';

              var formData = new FormData(formEl);
              var payload = {};
              formData.forEach(function (value, key) {
                payload[key] = value;
              });

              fetch('/api/v1/public/forms/' + encodeURIComponent(handle) + '/submit', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
              })
                .then(function (subRes) {
                  return subRes.json().then(function (subData) {
                    return { ok: subRes.ok, data: subData };
                  });
                })
                .then(function (result) {
                  if (btn) {
                    btn.disabled = false;
                    btn.textContent = formDef.submit_label || 'Send Message';
                  }

                  if (result.ok && result.data.success) {
                    formEl.innerHTML = '<div style="padding:1.5rem; background:rgba(52,211,153,0.15); border:1px solid #34d399; border-radius:8px; color:#065f46; text-align:center;">' +
                      '<h4 style="margin:0 0 0.5rem; font-size:1.1rem; font-weight:600;">Message Received</h4>' +
                      '<p style="margin:0;">' + (result.data.message || 'Thank you! Your message has been sent.') + '</p>' +
                      '</div>';
                  } else {
                    if (result.data.errors) {
                      for (var errKey in result.data.errors) {
                        var input = formEl.querySelector('[name="' + errKey + '"]');
                        if (input && input.parentElement) {
                          var errSpan = input.parentElement.querySelector('.bs-field-error');
                          if (errSpan) {
                            errSpan.textContent = result.data.errors[errKey].join(' ');
                            errSpan.style.display = 'block';
                          }
                        }
                      }
                    } else if (statusEl) {
                      statusEl.textContent = result.data.message || 'Failed to submit form. Please try again.';
                      statusEl.style.background = 'rgba(248,113,113,0.15)';
                      statusEl.style.color = '#991b1b';
                      statusEl.style.display = 'block';
                    }
                  }
                })
                .catch(function () {
                  if (btn) {
                    btn.disabled = false;
                    btn.textContent = formDef.submit_label || 'Send Message';
                  }
                  if (statusEl) {
                    statusEl.textContent = 'An unexpected network error occurred.';
                    statusEl.style.background = 'rgba(248,113,113,0.15)';
                    statusEl.style.color = '#991b1b';
                    statusEl.style.display = 'block';
                  }
                });
            });
          })
          .catch(function (err) {
            container.innerHTML = '<div style="padding: 1rem; color: #991b1b; background: rgba(248,113,113,0.1); border-radius: 6px; font-size: 0.875rem;">' +
              'Unable to load form (' + (err.message || 'error') + ').' +
              '</div>';
          });
      })(containers[i]);
    }
  }

  var BSEmbed = {
    ALLOWED_EMBED_DOMAINS: ALLOWED_EMBED_DOMAINS,
    isAllowedEmbedOrigin: isAllowedEmbedOrigin,
    handleEmbedActivation: handleEmbedActivation,
    initEmbedLoader: initEmbedLoader,
    initContactForms: initContactForms,
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
        initContactForms(document);
      });
    } else {
      initEmbedLoader(document);
      initContactForms(document);
    }
  }
})(typeof globalThis !== 'undefined' ? globalThis : typeof window !== 'undefined' ? window : this);
