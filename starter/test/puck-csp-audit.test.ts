import { describe, it, expect, beforeAll } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import * as crypto from 'node:crypto';
import { execSync } from 'node:child_process';

/**
 * Empirical CSP audit over the built dist.
 *
 * The site enforces CSP via a per-page <meta http-equiv="content-security-policy">
 * emitted by Astro (script-src 'self' + build-computed hashes; style-src
 * 'self' 'unsafe-inline') plus a server header (.htaccess) that owns
 * frame-ancestors/img-src/frame-src/connect-src only.
 *
 * This suite fails when:
 *  - a hand-written inline script (e.g. search.astro's is:inline block) drifts
 *    from the hash pinned in astro.config.mjs;
 *  - Astro emits an inline script that its hashing does not cover;
 *  - a content page accidentally hydrates (astro-island) or the preview page
 *    loses its island;
 *  - the .htaccess header re-takes script/style policy ownership (its
 *    constraints would intersect-block the meta policy).
 */

const starterRoot = path.resolve(__dirname, '..');
const distDir = path.join(starterRoot, 'dist');

function walkHtml(dir: string, found: string[] = []): string[] {
  for (const file of fs.readdirSync(dir)) {
    const full = path.join(dir, file);
    if (fs.statSync(full).isDirectory()) walkHtml(full, found);
    else if (file.endsWith('.html')) found.push(full);
  }
  return found;
}

interface InlineScript {
  attrs: string;
  text: string;
}

function inlineExecutableScripts(html: string): InlineScript[] {
  const out: InlineScript[] = [];
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(html))) {
    if (/\bsrc=/.test(m[1])) continue;
    const typeMatch = m[1].match(/type=["']([^"']*)["']/);
    const type = typeMatch ? typeMatch[1] : '';
    // Data blocks (e.g. application/ld+json) are never executed by the browser.
    if (type && type !== 'text/javascript' && type !== 'module') continue;
    out.push({ attrs: m[1], text: m[2] });
  }
  return out;
}

function scriptHashes(metaCsp: string): string[] {
  const match = metaCsp.match(/script-src[^;]*/);
  if (!match) return [];
  return match[0].match(/'sha256-[^']+'|'unsafe-inline'/g) || [];
}

function getMetaCsp(html: string): string {
  const match = html.match(/<meta http-equiv="content-security-policy" content="([^"]*)"/);
  return match ? match[1] : '';
}

beforeAll(() => {
  // The audit needs the fixture build (puck-demo + preview island output).
  if (!fs.existsSync(path.join(distDir, 'puck-demo', 'index.html'))) {
    execSync('npx astro build', {
      cwd: starterRoot,
      env: {
        ...process.env,
        BS_USE_FIXTURES: '1',
        BENCHMARK_POSTS: '2',
        BS_CMS_URL: 'http://127.0.0.1:59999',
        CMS_URL: 'http://127.0.0.1:59999',
      },
      stdio: 'pipe',
    });
  }
}, 240000);

describe('CSP audit over built dist', () => {
  // Lazy: dist does not exist until beforeAll builds the fixture output
  // (and CI's node job never pre-builds it).
  const htmlFiles = () => walkHtml(distDir);

  it('every page emits a meta CSP with script-src and style-src', () => {
    expect(htmlFiles().length).toBeGreaterThan(0);
    for (const file of htmlFiles()) {
      const csp = getMetaCsp(fs.readFileSync(file, 'utf8'));
      expect(csp, path.relative(distDir, file)).toMatch(/script-src 'self'/);
      expect(csp, path.relative(distDir, file)).toMatch(/style-src 'self' 'unsafe-inline'/);
      expect(csp, path.relative(distDir, file)).toMatch(/default-src 'self'/);
    }
  });

  it('every inline executable script is covered by a script-src hash', () => {
    for (const file of htmlFiles()) {
      const html = fs.readFileSync(file, 'utf8');
      const allowed = scriptHashes(getMetaCsp(html));
      for (const script of inlineExecutableScripts(html)) {
        const digest =
          'sha256-' + crypto.createHash('sha256').update(script.text, 'utf8').digest('base64');
        expect(
          allowed,
          `${path.relative(distDir, file)} contains inline script (${script.text.trim().slice(0, 60)}...) not covered by the meta CSP. If you edited an is:inline script, recompute the hash in astro.config.mjs.`,
        ).toContain(`'${digest}'`);
      }
    }
  });

  it('all script and stylesheet references are same-origin', () => {
    for (const file of htmlFiles()) {
      const html = fs.readFileSync(file, 'utf8');
      const srcs = [...html.matchAll(/<script[^>]*\ssrc="([^"]+)"/g)].map((m) => m[1]);
      const links = [...html.matchAll(/<link[^>]*\srel="stylesheet"[^>]*\shref="([^"]+)"/g)].map(
        (m) => m[1],
      );
      for (const url of [...srcs, ...links]) {
        expect(url.startsWith('/'), `${path.relative(distDir, file)}: ${url}`).toBe(true);
      }
    }
  });

  it('content pages render Puck markup with no hydration runtime', () => {
    for (const page of ['puck-demo/index.html', 'home/index.html', 'index.html']) {
      const html = fs.readFileSync(path.join(distDir, page), 'utf8');
      expect(html, page).toContain('bs-hero');
      expect(html, page).not.toContain('astro-island');
      expect(html, page).toContain('class="bs-');
    }
  });

  it('preview page mounts exactly one React island with same-origin bundles', () => {
    const html = fs.readFileSync(path.join(distDir, 'preview', 'index.html'), 'utf8');
    const islands = html.match(/<astro-island[^>]*>/g) || [];
    expect(islands.length).toBe(1);
    expect(islands[0]).toMatch(/component-url="\/_astro\/PreviewRenderer\./);
    expect(islands[0]).toMatch(/client="only"/);
    // The island bootstrap must be hash-covered (checked globally above); the
    // hydration entry points must be bundled, not inline third-party code.
    expect(html).toMatch(/renderer-url="\/_astro\/client\./);
  });

  it('htaccess header keeps frame/img/connect policy but no script/style/default ownership', () => {
    const htaccess = fs.readFileSync(path.join(distDir, '.htaccess'), 'utf8');
    const csp = htaccess.match(/Header set Content-Security-Policy "([^"]*)"/)?.[1] || '';
    expect(csp).toMatch(/frame-ancestors 'self'/);
    expect(csp).toMatch(/connect-src 'self' https:\/\//);
    expect(csp).toMatch(/img-src 'self' data: https:/);
    expect(csp).not.toMatch(/default-src/);
    expect(csp).not.toMatch(/script-src/);
    expect(csp).not.toMatch(/style-src/);
  });
});
