import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';
import { initEmbedLoader, isAllowedEmbedOrigin } from '../public/embed.js';

describe('embed.js loader', () => {
  it('validates allowed embed domains over https', () => {
    expect(isAllowedEmbedOrigin('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')).toBe(true);
    expect(isAllowedEmbedOrigin('https://player.vimeo.com/video/12345')).toBe(true);
    expect(isAllowedEmbedOrigin('https://open.spotify.com/embed/track/123')).toBe(true);
    expect(isAllowedEmbedOrigin('http://youtube.com/embed/123')).toBe(false); // HTTP rejected
    expect(isAllowedEmbedOrigin('https://evil.example.com/embed')).toBe(false); // Unknown host rejected
    expect(isAllowedEmbedOrigin('javascript:alert(1)')).toBe(false); // Insecure scheme rejected
  });

  describe('DOM interaction', () => {
    let dom: JSDOM;
    let document: Document;

    beforeEach(() => {
      dom = new JSDOM(
        `<!DOCTYPE html>
        <html>
          <body>
            <div id="valid-embed" class="embed-placeholder" data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ">
              <button type="button" class="embed-load-button">Load interactive embed</button>
            </div>
            <div id="tampered-embed" class="embed-placeholder" data-embed-src="https://evil.example.com/malicious-iframe">
              <button type="button" class="embed-load-button">Load untrusted embed</button>
            </div>
            <div id="xss-embed" class="embed-placeholder" data-embed-src="javascript:alert(1)">
              <button type="button" class="embed-load-button">Load XSS embed</button>
            </div>
          </body>
        </html>`,
        { url: 'https://bskickstarter.com/' }
      );
      document = dom.window.document;
      initEmbedLoader(document);
    });

    it('creates an iframe when clicking a valid allow-listed embed', () => {
      const validContainer = document.getElementById('valid-embed')!;
      const button = validContainer.querySelector<HTMLButtonElement>('.embed-load-button')!;

      button.click();

      const iframe = validContainer.querySelector('iframe');
      expect(iframe).not.toBeNull();
      expect(iframe?.getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
      expect(iframe?.getAttribute('allowfullscreen')).toBe('true');
      expect(validContainer.querySelector('.embed-load-button')).toBeNull();
    });

    it('does nothing when clicking a tampered or unapproved embed', () => {
      const tamperedContainer = document.getElementById('tampered-embed')!;
      const button = tamperedContainer.querySelector<HTMLButtonElement>('.embed-load-button')!;

      button.click();

      const iframe = tamperedContainer.querySelector('iframe');
      expect(iframe).toBeNull();
      expect(tamperedContainer.querySelector('.embed-load-button')).not.toBeNull();
    });

    it('does nothing and blocks javascript: embed schemes', () => {
      const xssContainer = document.getElementById('xss-embed')!;
      const button = xssContainer.querySelector<HTMLButtonElement>('.embed-load-button')!;

      button.click();

      const iframe = xssContainer.querySelector('iframe');
      expect(iframe).toBeNull();
    });
  });
});
