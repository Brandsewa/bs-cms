import React, { lazy, Suspense } from 'react';
import { createRoute, redirect } from '@tanstack/react-router';
import { rootRoute } from './routing';
import { ProtectedAdmin } from './shell/protected-admin';
import { ComingSoonPage } from './shell/coming-soon';

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
const CollectionListRoute = lazy(() =>
  import('./entries').then((m) => ({ default: m.CollectionListRoute }))
);
const DashboardRoute = lazy(() =>
  import('./dashboard').then((m) => ({ default: m.DashboardRoute }))
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
    <ProtectedAdmin permission="collection:read">
      <RouteSuspense>
        <CollectionListRoute collectionHandle="posts" />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const collectionEntriesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/entries/$collection',
  component: () => {
    const params = collectionEntriesRoute.useParams();
    return (
      <ProtectedAdmin permission="collection:read">
        <RouteSuspense>
          <CollectionListRoute collectionHandle={params.collection} />
        </RouteSuspense>
      </ProtectedAdmin>
    );
  },
});

export const entryNewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/entries/$collection/new',
  component: () => {
    const params = entryNewRoute.useParams();
    return (
      <ProtectedAdmin permission="collection:create">
        <ComingSoonPage
          title={`Create ${params.collection === 'pages' ? 'Page' : 'Post'}`}
          description="The full Tiptap article schema editor with optimistic concurrency (If-Match) and SEO panel is arriving in Phase 6.2."
        />
      </ProtectedAdmin>
    );
  },
});

export const entryEditRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/entries/$collection/$id',
  component: () => {
    const params = entryEditRoute.useParams();
    return (
      <ProtectedAdmin permission="collection:update">
        <ComingSoonPage
          title={`Edit ${params.collection === 'pages' ? 'Page' : 'Post'} (${params.id})`}
          description="The full Tiptap article schema editor with optimistic concurrency (If-Match) and SEO panel is arriving in Phase 6.2."
        />
      </ProtectedAdmin>
    );
  },
});

export const taxonomiesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/taxonomies',
  component: () => (
    <ProtectedAdmin permission="taxonomy:read">
      <ComingSoonPage
        title="Taxonomies & Categories"
        description="Taxonomy management and term relationships will be configurable here."
      />
    </ProtectedAdmin>
  ),
});

export const mediaRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/media',
  component: () => (
    <ProtectedAdmin permission="media:read">
      <ComingSoonPage
        title="Media Library"
        description="Manage media assets, folders, and chunked uploads here."
      />
    </ProtectedAdmin>
  ),
});

export const menusRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/menus',
  component: () => (
    <ProtectedAdmin permission="site:read">
      <ComingSoonPage
        title="Site Navigation"
        description="Configure menus, header/footer links, and hierarchy here."
      />
    </ProtectedAdmin>
  ),
});

export const aiRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/ai',
  component: () => (
    <ProtectedAdmin permission="ai:read">
      <ComingSoonPage
        title="AI Studio & Pending Changes"
        description="Review AI agent suggestions, draft reviews, and automated changes."
      />
    </ProtectedAdmin>
  ),
});

export const usersRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/users',
  component: () => (
    <ProtectedAdmin permission="users:read">
      <ComingSoonPage
        title="User & Access Management"
        description="Invite users, assign roles, and inspect permissions."
      />
    </ProtectedAdmin>
  ),
});

export const developerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/developer',
  component: () => (
    <ProtectedAdmin permission="settings:read">
      <ComingSoonPage
        title="Developer Tools"
        description="API keys, webhooks, schema inspection, and MCP connections."
      />
    </ProtectedAdmin>
  ),
});

export const settingsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings',
  component: () => (
    <ProtectedAdmin permission="settings:read">
      <ComingSoonPage
        title="Site & System Settings"
        description="General configuration, email settings, backups, and publishing webhooks."
      />
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
    <ProtectedAdmin>
      <RouteSuspense>
        <NotFoundRoute />
      </RouteSuspense>
    </ProtectedAdmin>
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
    collectionEntriesRoute,
    entryNewRoute,
    entryEditRoute,
    taxonomiesRoute,
    mediaRoute,
    menusRoute,
    aiRoute,
    usersRoute,
    developerRoute,
    settingsRoute,
    forbiddenRoute,
    notFoundRoute,
  ];

  if (import.meta.env.DEV) {
    const { galleryRoute } = await import('./gallery/gallery-route');
    return rootRoute.addChildren([...routes, galleryRoute]);
  }
  return rootRoute.addChildren(routes);
}
