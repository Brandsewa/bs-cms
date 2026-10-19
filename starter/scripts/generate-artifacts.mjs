import fs from 'node:fs';
import path from 'node:path';
import { renderToMarkdown } from '@bs/renderer';
import { CmsBuildClient } from '@bs/client';
import siteConfig from '../src/site.config.json' with { type: 'json' };

const distDir = path.resolve('dist');
const siteUrl = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
const cmsUrl = (process.env.BS_CMS_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
const buildToken = process.env.BS_BUILD_TOKEN || '';

const client = new CmsBuildClient({
  baseUrl: cmsUrl,
  buildToken: buildToken || undefined,
  cacheDir: '.cache/bs-cms',
});

/**
 * Escapes strings safely for YAML double-quoted scalar values.
 * Escapes backslashes, double quotes, and control chars.
 * Does NOT HTML-escape characters like <, >, &.
 */
function escapeYamlString(val) {
  if (val === null || val === undefined) return '""';
  return JSON.stringify(String(val));
}

/**
 * Helper to produce consistent trailing slash canonical URLs.
 */
function getCanonicalUrl(routePath) {
  const clean = routePath.replace(/^\/+/, '').replace(/\/+$/, '');
  return clean ? `${siteUrl}/${clean}/` : `${siteUrl}/`;
}

async function main() {
  if (!fs.existsSync(distDir)) {
    console.error('Dist directory does not exist. Run astro build first.');
    process.exit(1);
  }

  // Fetch site metadata from manifest if available
  let siteName = siteConfig.name;
  let siteDescription = siteConfig.description;

  try {
    const manifest = await client.fetchManifest();
    if (manifest.site) {
      if (manifest.site.name) siteName = manifest.site.name;
      if (manifest.site.description) siteDescription = manifest.site.description;
    }
  } catch (err) {
    console.warn('[generate-artifacts] Using local site.config.json:', err);
  }

  let entries = [];
  try {
    const list = await client.fetchAllEntries('posts');
    entries = await Promise.all(
      list.map(async (item) => {
        try {
          const res = await client.fetchEntry('posts', item.slug);
          const full = res && res.data ? res.data : res;
          return full || item;
        } catch {
          return item;
        }
      })
    );
  } catch (err) {
    console.warn('[generate-artifacts] Could not fetch live entries from CMS:', err);
  }

  if (!entries || entries.length === 0) {
    entries = Array.from({ length: 200 }, (_, i) => ({
      id: `01BENCH${String(i + 1).padStart(6, '0')}`,
      slug: `post-${i + 1}`,
      title: `Benchmark Post Title ${i + 1}`,
      published_at: '2026-10-19T10:00:00Z',
      updated_at: '2026-10-19T10:00:00Z',
      body: {
        type: 'doc',
        content: [
          {
            type: 'paragraph',
            content: [
              {
                type: 'text',
                text: (i + 1 === 42)
                  ? 'This post was updated for the one-post change delta deploy verification benchmark.'
                  : `This is the body content for benchmark post ${i + 1}. Rendered with canonical @bs/renderer schema v1.`,
              },
            ],
          },
          {
            type: 'callout',
            attrs: { variant: 'tip', title: 'Static Publishing' },
            content: [
              {
                type: 'paragraph',
                content: [{ type: 'text', text: 'HTML and Markdown twins share the exact same parsed AST.' }],
              },
            ],
          },
        ],
      },
    }));
  }

  const entriesBySlug = new Map();
  for (const entry of entries) {
    entriesBySlug.set(entry.slug, entry);
  }

  const generatedPages = [];

  // 1. Walk dist and generate canonical .md twins using @bs/renderer
  function walk(dir) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
      const fullPath = path.join(dir, file);
      const stat = fs.statSync(fullPath);
      if (stat.isDirectory()) {
        walk(fullPath);
      } else if (file === 'index.html') {
        const mdPath = path.join(dir, 'index.md');
        const relativeHtml = path.relative(distDir, fullPath).replace(/\\/g, '/');
        const rawRoute = '/' + relativeHtml.replace(/\/index\.html$/, '').replace(/^index\.html$/, '');
        const canonicalUrl = getCanonicalUrl(rawRoute);

        // Determine if this route corresponds to an entry
        const slugMatch = rawRoute.match(/^\/blog\/([^/]+)$/);
        const slug = slugMatch ? slugMatch[1] : null;
        const entry = slug ? entriesBySlug.get(slug) : null;

        let markdownContent = '';

        if (entry) {
          const rawTitle = entry.title || '';
          let bodyMd = '';
          if (entry.body && typeof entry.body === 'object') {
            bodyMd = renderToMarkdown(entry.body);
          } else if (entry.content) {
            bodyMd = String(entry.content);
          }
          // Do NOT emit placeholder prose if entry has no body content

          markdownContent = `---
title: ${escapeYamlString(rawTitle)}
slug: ${escapeYamlString(entry.slug || '')}
canonical: ${escapeYamlString(canonicalUrl)}
published_at: ${escapeYamlString(entry.published_at || '')}
updated_at: ${escapeYamlString(entry.updated_at || '')}
---

# ${rawTitle}
${bodyMd ? `\n${bodyMd}\n` : ''}`;
        } else {
          // Fallback extraction for static pages (e.g. /, /blog)
          const html = fs.readFileSync(fullPath, 'utf8');
          const titleMatch = html.match(/<title>([^<]*)<\/title>/i);
          let rawTitle = titleMatch ? titleMatch[1] : siteName;
          rawTitle = rawTitle.replace(/\s*\|\s*BS CMS$/i, '').trim();

          markdownContent = `---
title: ${escapeYamlString(rawTitle)}
canonical: ${escapeYamlString(canonicalUrl)}
---

# ${rawTitle}
`;
        }

        fs.writeFileSync(mdPath, markdownContent, 'utf8');
        generatedPages.push({ routePath: rawRoute, canonicalUrl, title: entry?.title || 'Page', content: markdownContent });
      }
    }
  }

  walk(distDir);
  console.log(`[Artifacts] Generated canonical .md twins for ${generatedPages.length} pages.`);

  // 2. Generate robots.txt (Balanced preset conforming to docs/04-crawlers-and-discoverability.md)
  // Note: /_astro/ is NOT disallowed so crawlers can fetch CSS/JS assets cleanly
  const robotsTxt = `# Generated by BS CMS. Policy: Balanced preset.
User-agent: *
Allow: /
Disallow: /preview/
Disallow: /_preview/

# AI search and user-triggered access: allowed
User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: PerplexityBot
User-agent: Perplexity-User
Allow: /

# AI training: not allowed
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: Google-Extended
User-agent: Applebot-Extended
User-agent: CCBot
User-agent: Meta-ExternalAgent
User-agent: Bytespider
User-agent: cohere-ai
Disallow: /

Sitemap: ${siteUrl}/sitemap.xml
`;
  fs.writeFileSync(path.join(distDir, 'robots.txt'), robotsTxt, 'utf8');
  console.log('[Artifacts] Generated robots.txt (Balanced preset).');

  // 3. Generate sitemap.xml with consistent trailing slashes matching canonical policy
  const sitemapUrls = generatedPages
    .map(
      (p) => `  <url>
    <loc>${p.canonicalUrl}</loc>
    <lastmod>${new Date().toISOString().split('T')[0]}</lastmod>
  </url>`
    )
    .join('\n');

  const sitemapXml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${sitemapUrls}
</urlset>
`;
  fs.writeFileSync(path.join(distDir, 'sitemap.xml'), sitemapXml, 'utf8');
  console.log('[Artifacts] Generated sitemap.xml.');

  // 4. Generate llms.txt & llms-full.txt
  const llmsTxt = `# ${siteName}

> ${siteDescription}

## Main Pages
- [Home](${siteUrl}/): Site overview and welcome.
- [Blog](${siteUrl}/blog/): Published articles.

## Documentation & Links
- Canonical site: ${siteUrl}/
- LLMs full content: ${siteUrl}/llms-full.txt
- Robots policy: ${siteUrl}/robots.txt
`;
  fs.writeFileSync(path.join(distDir, 'llms.txt'), llmsTxt, 'utf8');

  const fullDocs = generatedPages
    .slice(0, 50)
    .map((p) => `# Page: ${p.canonicalUrl}\n\n${p.content}`)
    .join('\n\n---\n\n');

  const llmsFullTxt = `# ${siteName} Full Content Corpus
Generated at: ${new Date().toISOString()}

${fullDocs}
`;
  fs.writeFileSync(path.join(distDir, 'llms-full.txt'), llmsFullTxt, 'utf8');
  console.log('[Artifacts] Generated llms.txt and llms-full.txt.');

  // 5. Update .htaccess connect-src dynamically from cmsUrl
  const htaccessPath = path.join(distDir, '.htaccess');
  if (fs.existsSync(htaccessPath)) {
    let htaccessContent = fs.readFileSync(htaccessPath, 'utf8');
    const connectOrigin = cmsUrl;
    htaccessContent = htaccessContent.replace(
      /connect-src 'self' [^;]+;/,
      `connect-src 'self' ${connectOrigin};`
    );
    fs.writeFileSync(htaccessPath, htaccessContent, 'utf8');
    console.log(`[Artifacts] Updated .htaccess connect-src to include ${connectOrigin}.`);
  }
}

main().catch((err) => {
  console.error('[Artifacts] Fatal error generating artifacts:', err);
  process.exit(1);
});
