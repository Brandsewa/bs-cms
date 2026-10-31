import { describe, it, expect, beforeEach, vi } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { JSDOM } from 'jsdom';

describe('Contact Form Embed in embed.js', () => {
  const embedJsPath = path.resolve(__dirname, '../public/embed.js');
  const embedJsCode = fs.readFileSync(embedJsPath, 'utf8');

  it('exposes initContactForms function on window.BSEmbed', () => {
    const dom = new JSDOM(
      `<!DOCTYPE html><html><head><script>${embedJsCode}</script></head><body></body></html>`,
      { runScripts: 'dangerously', url: 'https://example.com/' }
    );
    expect(typeof (dom.window as any).BSEmbed.initContactForms).toBe('function');
  });

  it('renders form and handles submission flow', async () => {
    const mockFormSchema = {
      data: {
        handle: 'contact-us',
        title: 'Contact Us',
        submit_label: 'Send Message',
        fields: [
          { name: 'name', label: 'Full Name', type: 'text', required: true },
          { name: 'email', label: 'Email', type: 'email', required: true },
        ],
      },
    };

    const dom = new JSDOM(
      `<!DOCTYPE html>
      <html>
        <head><script>${embedJsCode}</script></head>
        <body>
          <div data-bs-form="contact-us"></div>
        </body>
      </html>`,
      { runScripts: 'dangerously', url: 'https://example.com/' }
    );

    const win = dom.window as any;
    win.fetch = vi.fn().mockImplementation((url: string, opts?: any) => {
      if (url.includes('/api/v1/public/forms/contact-us') && (!opts || opts.method !== 'POST')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(mockFormSchema),
        });
      }
      if (url.includes('/submit') && opts?.method === 'POST') {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, message: 'Message delivered!' }),
        });
      }
      return Promise.reject(new Error('Unknown url'));
    });

    win.BSEmbed.initContactForms(win.document);

    // Wait microtasks
    await new Promise((r) => setTimeout(r, 20));

    const form = win.document.querySelector('form.bs-dynamic-form');
    expect(form).not.toBeNull();
    const nameInput = form.querySelector('input[name="name"]');
    expect(nameInput).not.toBeNull();
    const hpInput = form.querySelector('input[name="_hp_check"]');
    expect(hpInput).not.toBeNull();

    // Trigger submit
    nameInput.value = 'Alice';
    const emailInput = form.querySelector('input[name="email"]');
    emailInput.value = 'alice@example.com';

    form.dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));

    await new Promise((r) => setTimeout(r, 20));

    expect(win.fetch).toHaveBeenCalledWith(
      '/api/v1/public/forms/contact-us/submit',
      expect.objectContaining({ method: 'POST' })
    );
  });
});
