import { createRoute, lazyRouteComponent } from '@tanstack/react-router';
import { rootRoute } from '../routing';

export type GallerySectionId = (typeof gallerySections)[number]['id'];

export const gallerySections = [
  { id: 'foundations', name: 'Foundations' },
] as const;

/**
 * Development-only route (/admin/_gallery). Registered from main.tsx behind
 * import.meta.env.DEV, so this module never enters the production bundle.
 */
export const galleryRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '_gallery',
  validateSearch: (search: Record<string, unknown>): { section: string } => ({
    section: typeof search.section === 'string' ? search.section : 'foundations',
  }),
  component: lazyRouteComponent(() => import('./GalleryApp'), 'GalleryApp'),
});
