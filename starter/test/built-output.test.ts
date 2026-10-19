import { describe, it, expect, beforeAll } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { execSync } from 'node:child_process';

describe('Built dist output verification', () => {
  const distDir = path.resolve(__dirname, '../dist');

  beforeAll(() => {
    // If dist doesn't exist (e.g. running test without prior manual build in CI), build it
    if (!fs.existsSync(distDir) || !fs.existsSync(path.join(distDir, 'index.html'))) {
      const rootStarter = path.resolve(__dirname, '..');
      execSync('npx astro build && npx tsx scripts/generate-artifacts.mjs', {
        cwd: rootStarter,
        env: { ...process.env, BENCHMARK_POSTS: '3' },
        stdio: 'pipe',
      });
    }
  }, 60000);

  it('dist directory exists and contains built output', () => {
    expect(fs.existsSync(distDir)).toBe(true);
  });

  it('no HTML file in dist/ contains localhost', () => {
    function checkDir(dir: string) {
      const files = fs.readdirSync(dir);
      for (const file of files) {
        const full = path.join(dir, file);
        if (fs.statSync(full).isDirectory()) {
          checkDir(full);
        } else if (file.endsWith('.html')) {
          const content = fs.readFileSync(full, 'utf8');
          expect(content).not.toMatch(/http:\/\/localhost/i);
          expect(content).not.toMatch(/https:\/\/localhost/i);
        }
      }
    }

    checkDir(distDir);
  });

  it('all HTML pages have consistent trailing slash canonical and og:url', () => {
    const homeHtml = fs.readFileSync(path.join(distDir, 'index.html'), 'utf8');
    expect(homeHtml).toMatch(/<link rel="canonical" href="https:\/\/[^"]+\/">/);
    expect(homeHtml).toMatch(/<meta property="og:url" content="https:\/\/[^"]+\/">/);

    const blogHtml = fs.readFileSync(path.join(distDir, 'blog/index.html'), 'utf8');
    expect(blogHtml).toMatch(/<link rel="canonical" href="https:\/\/[^"]+\/blog\/">/);
    expect(blogHtml).toMatch(/<meta property="og:url" content="https:\/\/[^"]+\/blog\/">/);
  });

  it('robots.txt does not disallow /_astro/ and allows AI search bots', () => {
    const robots = fs.readFileSync(path.join(distDir, 'robots.txt'), 'utf8');
    expect(robots).not.toMatch(/Disallow:\s*\/_astro\//);
    expect(robots).toMatch(/User-agent:\s*OAI-SearchBot/);
    expect(robots).toMatch(/Sitemap:\s*https:\/\/[^\s]+\/sitemap\.xml/);
  });

  it('sitemap.xml loc entries use consistent trailing slashes', () => {
    const sitemap = fs.readFileSync(path.join(distDir, 'sitemap.xml'), 'utf8');
    const locs = sitemap.match(/<loc>([^<]+)<\/loc>/g) || [];
    expect(locs.length).toBeGreaterThan(0);
    for (const loc of locs) {
      const url = loc.replace(/<\/?loc>/g, '');
      expect(url).toMatch(/\/$/);
      expect(url).not.toMatch(/localhost/);
    }
  });

  it('.htaccess contains matching frame-src and dynamic connect-src', () => {
    const htaccess = fs.readFileSync(path.join(distDir, '.htaccess'), 'utf8');
    expect(htaccess).toMatch(/https:\/\/www\.youtube-nocookie\.com/);
    expect(htaccess).toMatch(/https:\/\/player\.vimeo\.com/);
    expect(htaccess).toMatch(/connect-src 'self' https:\/\//);
  });

  it('.md twins use YAML/Markdown title escaping without HTML entities', () => {
    // Check post-1 or any generated md twin
    const postMd = fs.readFileSync(path.join(distDir, 'blog/post-1/index.md'), 'utf8');
    expect(postMd).toMatch(/^title:\s*"[^"]+"/m);
    expect(postMd).not.toMatch(/&lt;/);
    expect(postMd).not.toMatch(/&gt;/);
  });

  it('meta description attributes escape < characters', () => {
    const blogHtml = fs.readFileSync(path.join(distDir, 'blog/index.html'), 'utf8');
    expect(blogHtml).not.toMatch(/<meta name="description" content="[^"]*<[^"]*"/);
  });
});
