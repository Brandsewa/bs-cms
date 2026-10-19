import { describe, it, expect, beforeEach } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { JSDOM } from 'jsdom';

describe('embed.js classic script loader', () => {
  const embedJsPath = path.resolve(__dirname, '../public/embed.js');
  const embedJsCode = fs.readFileSync(embedJsPath, 'utf8');

  it('contains zero ES module export statements', () => {
    expect(embedJsCode).not.toMatch(/\bexport\s+/);
    expect(embedJsCode).not.toMatch(/\bexport\s*\{/);
  });

  describe('Classic script browser execution via JSDOM', () => {
    let dom: JSDOM;
    let window: any;
    let document: Document;

    beforeEach(() => {
      dom = new JSDOM(
        `<!DOCTYPE html>
        <html>
          <head>
            <script>${embedJsCode}</script>
          </head>
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
        {
          runScripts: 'dangerously',
          url: 'https://bskickstarter.com/',
        }
      );
      window = dom.window;
      document = window.document;
    });

    it('attaches window.BSEmbed without throwing SyntaxError', () => {
      expect(window.BSEmbed).toBeDefined();
      expect(typeof window.BSEmbed.handleEmbedActivation).toBe('function');
      expect(typeof window.BSEmbed.isAllowedEmbedOrigin).toBe('function');
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
      expect(xssContainer.querySelector('.embed-load-button')).not.toBeNull();
    });
  });
});
