import { CmsBuildClient, type EntryItem } from '@bs/client';
import siteConfig from '../site.config.json';

export const CMS_URL = (process.env.BS_CMS_URL || process.env.CMS_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
export const BUILD_TOKEN = process.env.BS_BUILD_TOKEN || process.env.BUILD_TOKEN || '';
export const SITE_URL = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
export const USE_FIXTURES = process.env.BS_USE_FIXTURES === '1' || process.env.BS_USE_FIXTURES === 'true';

export const cmsClient = new CmsBuildClient({
  baseUrl: CMS_URL,
  buildToken: BUILD_TOKEN || undefined,
  cacheDir: '.cache/bs-cms',
});

/**
 * Returns fixture benchmark entries when BS_USE_FIXTURES=1 is explicitly opted in.
 */
export function getBenchmarkFixtures(count = 200): EntryItem[] {
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
 * Loads published entries for a collection.
 * In real builds, throws an Error if the API request fails, halting the build.
 * Only returns benchmark fixtures if BS_USE_FIXTURES=1 is explicitly set.
 */
export async function loadCollectionEntries(collection: string): Promise<EntryItem[]> {
  if (USE_FIXTURES) {
    const postCount = parseInt(process.env.BENCHMARK_POSTS || '200', 10);
    return getBenchmarkFixtures(postCount);
  }

  try {
    const list = await cmsClient.fetchAllEntries(collection);
    return await Promise.all(
      list.map(async (item) => {
        try {
          const res = (await cmsClient.fetchEntry(collection, item.slug)) as any;
          const full = res && res.data ? res.data : res;
          return full || item;
        } catch (fetchErr) {
          throw new Error(`[BS Build] Failed to fetch full entry detail for '${item.slug}': ${fetchErr instanceof Error ? fetchErr.message : String(fetchErr)}`);
        }
      })
    );
  } catch (err) {
    throw new Error(`[BS Build] Fatal error fetching collection '${collection}' from CMS (${CMS_URL}): ${err instanceof Error ? err.message : String(err)}`);
  }
}

export interface PostWithDerived extends EntryItem {
  html: string;
  markdown: string;
}

/**
 * Returns a canonical URL with consistent trailing-slash policy matching Astro configuration.
 */
export function getCanonicalUrl(pathname: string): string {
  const cleanPath = pathname.replace(/^\/+/, '').replace(/\/+$/, '');
  return cleanPath ? `${SITE_URL}/${cleanPath}/` : `${SITE_URL}/`;
}

/**
 * Escapes attribute content for HTML attributes (e.g. meta description, og:description).
 * Specifically escapes &, <, >, ", and '.
 */
export function escapeAttr(str: unknown): string {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Escapes </script> breakouts within embedded JSON-LD blocks.
 */
export function escapeJsonLd(json: unknown): string {
  const serialized = typeof json === 'string' ? json : JSON.stringify(json);
  return serialized.replace(/<\/script/gi, '<\\/script');
}

export interface SiteMetaWithNav {
  name: string;
  description: string;
  tagline: string;
  navigation: Array<{ label: string; url: string; target?: string | null }>;
}

let cachedSiteData: SiteMetaWithNav | null = null;

/**
 * Retrieves site name, description, tagline, and navigation from CMS GET /api/v1/public/site,
 * falling back to manifest or local site.config.json.
 */
export async function getSiteMetadata(): Promise<SiteMetaWithNav> {
  if (cachedSiteData) return cachedSiteData;

  try {
    const site = await cmsClient.fetchSite();
    if (site && site.site_name) {
      cachedSiteData = {
        name: site.site_name || siteConfig.name,
        description: site.description || site.tagline || siteConfig.description,
        tagline: site.tagline || '',
        navigation: Array.isArray(site.navigation) && site.navigation.length > 0
          ? site.navigation
          : [
              { label: 'Home', url: '/' },
              { label: 'Blog', url: '/blog/' },
            ],
      };
      return cachedSiteData;
    }
  } catch {
    // Non-fatal fallback
  }

  try {
    const manifest = await cmsClient.fetchManifest();
    if (manifest.site) {
      cachedSiteData = {
        name: manifest.site.name || siteConfig.name,
        description: manifest.site.description || siteConfig.description,
        tagline: '',
        navigation: [
          { label: 'Home', url: '/' },
          { label: 'Blog', url: '/blog/' },
        ],
      };
      return cachedSiteData;
    }
  } catch {
    // Non-fatal, fallback to local site.config.json
  }

  cachedSiteData = {
    name: siteConfig.name,
    description: siteConfig.description,
    tagline: '',
    navigation: [
      { label: 'Home', url: '/' },
      { label: 'Blog', url: '/blog/' },
    ],
  };
  return cachedSiteData;
}

