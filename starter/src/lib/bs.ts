import { CmsBuildClient, type EntryItem } from '@bs/client';
import siteConfig from '../site.config.json';

export const CMS_URL = (process.env.BS_CMS_URL || 'https://bskickstarter.com').replace(/\/+$/, '');
export const BUILD_TOKEN = process.env.BS_BUILD_TOKEN || '';
export const SITE_URL = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');

export const cmsClient = new CmsBuildClient({
  baseUrl: CMS_URL,
  buildToken: BUILD_TOKEN || undefined,
  cacheDir: '.cache/bs-cms',
});

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

let cachedSiteMeta: { name: string; description: string } | null = null;

/**
 * Retrieves site name and description from CMS manifest if available, falling back to site.config.json.
 */
export async function getSiteMetadata(): Promise<{ name: string; description: string }> {
  if (cachedSiteMeta) return cachedSiteMeta;
  try {
    const manifest = await cmsClient.fetchManifest();
    if (manifest.site) {
      cachedSiteMeta = {
        name: manifest.site.name || siteConfig.name,
        description: manifest.site.description || siteConfig.description,
      };
      return cachedSiteMeta;
    }
  } catch {
    // Non-fatal, fallback to local site.config.json
  }

  cachedSiteMeta = {
    name: siteConfig.name,
    description: siteConfig.description,
  };
  return cachedSiteMeta;
}

