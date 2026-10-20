import React, { lazy, Suspense } from 'react';
import { createRoute, redirect } from '@tanstack/react-router';
import { rootRoute } from './routing';
import { ProtectedAdmin } from './shell/protected-admin';

// Code-split routes to keep the bundle size well within 200 KB gzipped
const LoginRoute = lazy(() => import('./auth/login').then((m) => ({ default: m.LoginRoute })));
const ForgotPasswordRoute = lazy(() =>
  import('./auth/forgot-password').then((m) => ({ default: m.ForgotPasswordRoute }))
);
const ResetPasswordRoute = lazy(() =>
  import('./auth/reset-password').then((m) => ({ default: m.ResetPasswordRoute }))
);
const AcceptInvitationRoute = lazy(() =>
  import('./auth/accept-invitation').then((m) => ({ default: m.AcceptInvitationRoute }))
);
const OAuthConsentReturnRoute = lazy(() =>
  import('./auth/oauth-consent-return').then((m) => ({ default: m.OAuthConsentReturnRoute }))
);
const DashboardRoute = lazy(() =>
  import('./shell/dashboard').then((m) => ({ default: m.DashboardRoute }))
);
const ForbiddenRoute = lazy(() =>
  import('./shell/forbidden').then((m) => ({ default: m.ForbiddenRoute }))
);
const NotFoundRoute = lazy(() =>
  import('./shell/not-found').then((m) => ({ default: m.NotFoundRoute }))
);

function RouteSuspense({ children }: { children: React.ReactNode }) {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-[300px] items-center justify-center p-6">
          <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      }
    >
      {children}
    </Suspense>
  );
}

// Public Auth Routes
export const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: () => (
    <RouteSuspense>
      <LoginRoute />
    </RouteSuspense>
  ),
});

export const forgotPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/forgot-password',
  component: () => (
    <RouteSuspense>
      <ForgotPasswordRoute />
    </RouteSuspense>
  ),
});

export const resetPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/reset-password',
  component: () => (
    <RouteSuspense>
      <ResetPasswordRoute />
    </RouteSuspense>
  ),
});

export const acceptInvitationRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/invitations/accept',
  component: () => (
    <RouteSuspense>
      <AcceptInvitationRoute />
    </RouteSuspense>
  ),
});

export const oauthReturnRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/oauth/return',
  component: () => (
    <RouteSuspense>
      <OAuthConsentReturnRoute />
    </RouteSuspense>
  ),
});

// Protected Shell Routes
export const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  beforeLoad: () => {
    // In dev mode when requested via ?gallery=1 or /_gallery
    if (import.meta.env.DEV && window.location.search.includes('gallery=1')) {
      throw redirect({ to: '/_gallery' });
    }
  },
  component: () => (
    <ProtectedAdmin>
      <RouteSuspense>
        <DashboardRoute />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const entriesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/entries',
  component: () => (
    <ProtectedAdmin permission="entries.read">
      <div className="p-6">
        <h1 className="text-lg font-semibold">Entries</h1>
        <p className="text-13 text-muted-foreground mt-1">Entries management screen.</p>
      </div>
    </ProtectedAdmin>
  ),
});

export const mediaRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/media',
  component: () => (
    <ProtectedAdmin permission="media.read">
      <div className="p-6">
        <h1 className="text-lg font-semibold">Media Library</h1>
        <p className="text-13 text-muted-foreground mt-1">Media library screen.</p>
      </div>
    </ProtectedAdmin>
  ),
});

export const forbiddenRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/403',
  component: () => (
    <RouteSuspense>
      <ForbiddenRoute />
    </RouteSuspense>
  ),
});

export const notFoundRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '*',
  component: () => (
    <RouteSuspense>
      <NotFoundRoute />
    </RouteSuspense>
  ),
});

export async function buildRouteTree() {
  const routes = [
    indexRoute,
    loginRoute,
    forgotPasswordRoute,
    resetPasswordRoute,
    acceptInvitationRoute,
    oauthReturnRoute,
    entriesRoute,
    mediaRoute,
    forbiddenRoute,
    notFoundRoute,
  ];

  if (import.meta.env.DEV) {
    const { galleryRoute } = await import('./gallery/gallery-route');
    return rootRoute.addChildren([...routes, galleryRoute]);
  }
  return rootRoute.addChildren(routes);
}
