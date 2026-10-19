import { CmsBuildClient, type EntryItem } from '@bs/client';

export const CMS_URL = process.env.BS_CMS_URL || 'https://bskickstarter.com';
export const BUILD_TOKEN = process.env.BS_BUILD_TOKEN || '';
export const SITE_URL = process.env.SITE_URL || 'https://bskickstarter.com';

export const cmsClient = new CmsBuildClient({
  baseUrl: CMS_URL,
  buildToken: BUILD_TOKEN || undefined,
  cacheDir: '.cache/bs-cms',
});

export interface PostWithDerived extends EntryItem {
  html: string;
  markdown: string;
}

export function escapeJsonLd(json: unknown): string {
  const serialized = typeof json === 'string' ? json : JSON.stringify(json);
  // Prevent </script> injection breakouts in embedded JSON-LD scripts
  return serialized.replace(/<\/script/gi, '<\\/script');
}
