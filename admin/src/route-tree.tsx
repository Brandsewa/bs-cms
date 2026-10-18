import { createRoute, redirect } from '@tanstack/react-router';
import { rootRoute } from './routing';

/**
 * M1 intermediate entry screen. The full application shell (sidebar, top bar,
 * auth, session bootstrap) is milestone 3 of phase 5; the gallery route that
 * exercises the design system exists only in development builds.
 */
function HomeRoute() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-background p-6">
      <div className="w-full max-w-md rounded-lg border border-border bg-card p-8 shadow-sm">
        <h1 className="text-lg font-semibold text-card-foreground">BS CMS admin</h1>
        <p className="mt-2 text-13 text-muted-foreground">
          The admin application shell is delivered in phase 5, milestone 3. This
          intermediate build contains the design system and its gallery; the
          gallery is only present in development builds.
        </p>
      </div>
    </main>
  );
}

export const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  beforeLoad: () => {
    // In development the gallery is the entry point of the admin.
    if (import.meta.env.DEV) {
      throw redirect({ to: '/_gallery' });
    }
  },
  component: HomeRoute,
});

/**
 * The full route tree. Development-only: the gallery (and every mock it
 * renders) must not enter the production bundle. import.meta.env.DEV is
 * statically replaced at build time, so this import is removed from the
 * production graph.
 */
export async function buildRouteTree() {
  if (import.meta.env.DEV) {
    const { galleryRoute } = await import('./gallery/gallery-route');
    return rootRoute.addChildren([indexRoute, galleryRoute]);
  }
  return rootRoute.addChildren([indexRoute]);
}
