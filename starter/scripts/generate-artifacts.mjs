import fs from 'node:fs';
import path from 'node:path';
import { renderToMarkdown } from '@bs/renderer';
import { CmsBuildClient } from '@bs/client';
import siteConfig from '../src/site.config.json' with { type: 'json' };

const distDir = path.resolve('dist');
const siteUrl = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
const cmsUrl = (process.env.BS_CMS_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
const buildToken = process.env.BS_BUILD_TOKEN || '';

const useFixtures = process.env.BS_USE_FIXTURES === '1' || process.env.BS_USE_FIXTURES === 'true';

const client = new CmsBuildClient({
  baseUrl: cmsUrl,
  buildToken: buildToken || undefined,
  cacheDir: '.cache/bs-cms',
});

function getBenchmarkFixtures(count = 200) {
  return Array.from({ length: count }, (_, i) => ({
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

  // Fetch site metadata from /api/v1/public/site or manifest if available
  let siteName = siteConfig.name;
  let siteDescription = siteConfig.description;
  let navigation = [];

  try {
    const site = await client.fetchSite();
    if (site && site.site_name) {
      siteName = site.site_name;
      siteDescription = site.description || site.tagline || siteConfig.description;
      if (Array.isArray(site.navigation)) {
        navigation = site.navigation;
      }
    }
  } catch {
    try {
      const manifest = await client.fetchManifest();
      if (manifest.site) {
        if (manifest.site.name) siteName = manifest.site.name;
        if (manifest.site.description) siteDescription = manifest.site.description;
      }
    } catch (err) {
      console.warn('[generate-artifacts] Using local site.config.json:', err);
    }
  }

  let entries = [];
  if (useFixtures) {
    const postCount = parseInt(process.env.BENCHMARK_POSTS || '200', 10);
    entries = getBenchmarkFixtures(postCount);
  } else {
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
      console.error('[generate-artifacts] Fatal error fetching live entries from CMS:', err);
      throw err;
    }
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
          // Fallback extraction for static pages (e.g. /, /blog, /about, /404)
          const html = fs.readFileSync(fullPath, 'utf8');
          const titleMatch = html.match(/<title>([^<]*)<\/title>/i);
          let rawTitle = titleMatch ? titleMatch[1] : siteName;
          rawTitle = rawTitle.replace(/\s*\|\s*BS CMS.*$/i, '').trim();

          markdownContent = `---
title: ${escapeYamlString(rawTitle)}
canonical: ${escapeYamlString(canonicalUrl)}
---

# ${rawTitle}
`;
        }

        const pageTitle = entry?.title || (rawRoute === '/' ? 'Home' : (rawRoute === '/blog' ? 'Blog' : rawRoute.replace(/^\//, '')));
        fs.writeFileSync(mdPath, markdownContent, 'utf8');
        generatedPages.push({ routePath: rawRoute, canonicalUrl, title: pageTitle, content: markdownContent });
      }
    }
  }

  walk(distDir);
  console.log(`[Artifacts] Generated canonical .md twins for ${generatedPages.length} pages.`);

  // 2. Generate robots.txt (Balanced preset conforming to docs/04-crawlers-and-discoverability.md)
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

  // 3. Generate sitemap.xml with consistent trailing slashes matching canonical policy (skip 404 and preview)
  const sitemapUrls = generatedPages
    .filter((p) => !p.routePath.includes('404') && !p.routePath.includes('preview'))
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
  let mainPagesList = '';
  if (navigation.length > 0) {
    mainPagesList = navigation
      .map((item) => `- [${item.label}](${getCanonicalUrl(item.url)})`)
      .join('\n');
  } else {
    // Generate from discovered pages in generatedPages (excluding 404, preview, and individual blog posts)
    const staticNavPages = generatedPages.filter(
      (p) => !p.routePath.includes('404') && !p.routePath.includes('preview') && !p.routePath.startsWith('/blog/')
    );
    if (staticNavPages.length > 0) {
      mainPagesList = staticNavPages
        .map((p) => `- [${p.title}](${p.canonicalUrl})`)
        .join('\n');
    } else {
      mainPagesList = `- [Home](${siteUrl}/)\n- [Blog](${siteUrl}/blog/)`;
    }
  }

  const llmsTxt = `# ${siteName}

> ${siteDescription}

## Main Pages
${mainPagesList}

## Documentation & Links
- Canonical site: ${siteUrl}/
- LLMs full content: ${siteUrl}/llms-full.txt
- Robots policy: ${siteUrl}/robots.txt
`;
  fs.writeFileSync(path.join(distDir, 'llms.txt'), llmsTxt, 'utf8');

  const fullDocs = generatedPages
    .filter((p) => !p.routePath.includes('404'))
    .slice(0, 50)
    .map((p) => `# Page: ${p.canonicalUrl}\n\n${p.content}`)
    .join('\n\n---\n\n');

  const llmsFullTxt = `# ${siteName} Full Content Corpus
Generated at: ${new Date().toISOString()}

${fullDocs}
`;
  fs.writeFileSync(path.join(distDir, 'llms-full.txt'), llmsFullTxt, 'utf8');

  // 5. Ensure 404.html exists at root of dist for static hosts
  const notFoundDir = path.join(distDir, '404');
  if (fs.existsSync(path.join(notFoundDir, 'index.html')) && !fs.existsSync(path.join(distDir, '404.html'))) {
    fs.copyFileSync(path.join(notFoundDir, 'index.html'), path.join(distDir, '404.html'));
  }

  // 5b. Generate RSS 2.0 Feed (rss.xml) and Atom Feed (atom.xml)
  const rssItems = entries
    .filter((e) => e.published_at)
    .slice(0, 30)
    .map((e) => {
      const pubDate = new Date(e.published_at).toUTCString();
      const postUrl = getCanonicalUrl(`/blog/${e.slug}/`);
      const excerpt = e.summary || e.excerpt || `${e.title} on ${siteName}.`;
      return `    <item>
      <title>${escapeYamlString(e.title).slice(1, -1)}</title>
      <link>${postUrl}</link>
      <guid isPermaLink="true">${postUrl}</guid>
      <pubDate>${pubDate}</pubDate>
      <description><![CDATA[${excerpt}]]></description>
    </item>`;
    })
    .join('\n');

  const rssXml = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>${siteName}</title>
    <link>${siteUrl}/</link>
    <description>${siteDescription}</description>
    <language>en</language>
    <atom:link href="${siteUrl}/rss.xml" rel="self" type="application/rss+xml" />
${rssItems}
  </channel>
</rss>
`;
  fs.writeFileSync(path.join(distDir, 'rss.xml'), rssXml, 'utf8');
  console.log('[Artifacts] Generated rss.xml feed.');

  // 5c. Pagefind build-time search index stub / generator
  // Creates a clean search payload pagefind/pagefind.js & index.json so client search works seamlessly
  const pagefindDir = path.join(distDir, 'pagefind');
  if (!fs.existsSync(pagefindDir)) {
    fs.mkdirSync(pagefindDir, { recursive: true });
  }
  const searchRecords = generatedPages
    .filter((p) => !p.routePath.includes('404') && !p.routePath.includes('preview'))
    .map((p, idx) => ({
      id: idx + 1,
      url: p.canonicalUrl,
      title: p.title,
      content: p.content.slice(0, 500).replace(/[#*`\n]/g, ' '),
    }));
  fs.writeFileSync(path.join(pagefindDir, 'index.json'), JSON.stringify(searchRecords, null, 2), 'utf8');
  const pagefindJs = `// BS CMS Lightweight Client Search Adapter
export async function search(term) {
  const res = await fetch('/pagefind/index.json');
  const records = await res.json();
  const q = term.toLowerCase();
  return {
    results: records
      .filter(r => r.title.toLowerCase().includes(q) || r.content.toLowerCase().includes(q))
      .map(r => ({
        url: r.url,
        data: async () => ({ meta: { title: r.title }, excerpt: r.content.slice(0, 160) + '...' })
      }))
  };
}
`;
  fs.writeFileSync(path.join(pagefindDir, 'pagefind.js'), pagefindJs, 'utf8');
  console.log('[Artifacts] Generated build-time Pagefind search index & adapter.');

  // 5d. IndexNow key file
  const indexNowKey = process.env.BS_INDEXNOW_KEY || 'bs-indexnow-default-key-2026';
  fs.writeFileSync(path.join(distDir, `${indexNowKey}.txt`), indexNowKey, 'utf8');
  console.log(`[Artifacts] Generated IndexNow key file ${indexNowKey}.txt.`);

  // 6. Write deploy manifest containing relative paths of all built files
  const manifestFiles = [];
  function recordManifest(dir) {
    const files = fs.readdirSync(dir);
    for (const file of files) {
      const full = path.join(dir, file);
      if (fs.statSync(full).isDirectory()) {
        recordManifest(full);
      } else {
        const rel = path.relative(distDir, full).replace(/\\/g, '/');
        // Do not include the manifest itself in the list
        if (rel !== 'deploy-manifest.json') {
          manifestFiles.push(rel);
        }
      }
    }
  }
  recordManifest(distDir);
  const deployManifest = {
    generated_at: new Date().toISOString(),
    site_url: siteUrl,
    page_count: generatedPages.length,
    file_count: manifestFiles.length,
    files: manifestFiles.sort(),
  };
  fs.writeFileSync(path.join(distDir, 'deploy-manifest.json'), JSON.stringify(deployManifest, null, 2), 'utf8');
  console.log(`[Artifacts] Wrote deploy-manifest.json with ${manifestFiles.length} files (${generatedPages.length} pages).`);
}

main().catch((err) => {
  console.error('[Artifacts] Fatal error generating artifacts:', err);
  process.exit(1);
});
