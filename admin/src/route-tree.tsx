import React, { lazy, Suspense } from 'react';
import { createRoute, redirect } from '@tanstack/react-router';
import { rootRoute } from './routing';
import { ProtectedAdmin } from './shell/protected-admin';

// Code-split routes to keep the initial bundle size strictly minimal (< 200 KB gzipped)
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
const EntryEditorRoute = lazy(() =>
  import('./entries').then((m) => ({ default: m.EntryEditorRoute }))
);
const DashboardRoute = lazy(() =>
  import('./dashboard').then((m) => ({ default: m.DashboardRoute }))
);

// Settings views (independently lazy-loaded chunks)
// SPRINT-B BEGIN
const MediaLibraryRoute = lazy(() =>
  import('./media').then((m) => ({ default: m.MediaLibraryRoute }))
);
const TaxonomiesRoute = lazy(() =>
  import('./taxonomies').then((m) => ({ default: m.TaxonomiesRoute }))
);
const MenusRoute = lazy(() =>
  import('./menus').then((m) => ({ default: m.MenusRoute }))
);
// SPRINT-B END
const SettingsLayout = lazy(() =>
  import('./settings/settings-layout').then((m) => ({ default: m.SettingsLayout }))
);
const GeneralSettingsView = lazy(() =>
  import('./settings/general-settings-view').then((m) => ({ default: m.GeneralSettingsView }))
);
const EmailSettingsView = lazy(() =>
  import('./settings/email-settings-view').then((m) => ({ default: m.EmailSettingsView }))
);

// Users & Roles views (independently lazy-loaded chunks)
const UsersLayout = lazy(() =>
  import('./users/users-layout').then((m) => ({ default: m.UsersLayout }))
);
const UsersListView = lazy(() =>
  import('./users/users-list-view').then((m) => ({ default: m.UsersListView }))
);
const RolesMatrixView = lazy(() =>
  import('./users/roles-matrix-view').then((m) => ({ default: m.RolesMatrixView }))
);

// System views (independently lazy-loaded chunks)
const AuditLogView = lazy(() =>
  import('./system/audit-log-view').then((m) => ({ default: m.AuditLogView }))
);

// Shell fallbacks & placeholders
const ComingSoonPage = lazy(() =>
  import('./shell/coming-soon').then((m) => ({ default: m.ComingSoonPage }))
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
  path: '/accept-invitation',
  component: () => (
    <RouteSuspense>
      <AcceptInvitationRoute />
    </RouteSuspense>
  ),
});

export const oauthReturnRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/oauth-consent-return',
  component: () => (
    <RouteSuspense>
      <OAuthConsentReturnRoute />
    </RouteSuspense>
  ),
});

// Authenticated Routes
export const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  component: () => (
    <ProtectedAdmin>
      <RouteSuspense>
        <DashboardRoute />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

// Content & Structure
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
        <RouteSuspense>
          <EntryEditorRoute collectionHandle={params.collection} />
        </RouteSuspense>
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
        <RouteSuspense>
          <EntryEditorRoute collectionHandle={params.collection} entryId={params.id} />
        </RouteSuspense>
      </ProtectedAdmin>
    );
  },
});

export const taxonomiesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/taxonomies',
  component: () => (
    <ProtectedAdmin permission="taxonomies:read">
      <RouteSuspense>
        {/* SPRINT-B BEGIN */}
        <TaxonomiesRoute />
        {/* SPRINT-B END */}
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const mediaRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/media',
  component: () => (
    <ProtectedAdmin permission="media:read">
      <RouteSuspense>
        {/* SPRINT-B BEGIN */}
        <MediaLibraryRoute />
        {/* SPRINT-B END */}
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const menusRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/menus',
  component: () => (
    <ProtectedAdmin permission="menus:read">
      <RouteSuspense>
        {/* SPRINT-B BEGIN */}
        <MenusRoute />
        {/* SPRINT-B END */}
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const aiRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/ai',
  component: () => (
    <ProtectedAdmin permission="ai:read">
      <RouteSuspense>
        <ComingSoonPage
          title="AI Studio & Pending Changes"
          description="Review AI agent suggestions, draft reviews, and automated changes."
        />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

// Users & Roles Routes
export const usersRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/users',
  component: () => (
    <ProtectedAdmin permission="users:read">
      <RouteSuspense>
        <UsersLayout activeTab="users">
          <UsersListView />
        </UsersLayout>
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const usersRolesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/users/roles',
  component: () => (
    <ProtectedAdmin permission="users:read">
      <RouteSuspense>
        <UsersLayout activeTab="roles">
          <RolesMatrixView />
        </UsersLayout>
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const developerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/developer',
  component: () => (
    <ProtectedAdmin permission="settings:read">
      <RouteSuspense>
        <ComingSoonPage
          title="Developer Tools"
          description="API keys, webhooks, schema inspection, and MCP connections."
        />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

// Settings Routes
export const settingsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings',
  beforeLoad: () => {
    throw redirect({ to: '/settings/general' });
  },
});

export const settingsGeneralRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings/general',
  component: () => (
    <ProtectedAdmin permission="settings.manage">
      <RouteSuspense>
        <SettingsLayout activeTab="general">
          <GeneralSettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

export const settingsEmailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings/email',
  component: () => (
    <ProtectedAdmin permission="settings.manage">
      <RouteSuspense>
        <SettingsLayout activeTab="email">
          <EmailSettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

// System Routes
export const systemAuditRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/system/audit',
  component: () => (
    <ProtectedAdmin permission="audit:read">
      <RouteSuspense>
        <AuditLogView />
      </RouteSuspense>
    </ProtectedAdmin>
  ),
});

// Redirect /settings/audit to /system/audit for dashboard and legacy link compatibility
export const settingsAuditRedirectRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings/audit',
  beforeLoad: () => {
    throw redirect({ to: '/system/audit' });
  },
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
    usersRolesRoute,
    developerRoute,
    settingsRoute,
    settingsGeneralRoute,
    settingsEmailRoute,
    systemAuditRoute,
    settingsAuditRedirectRoute,
    forbiddenRoute,
    notFoundRoute,
  ];

  if (import.meta.env.DEV) {
    const { galleryRoute } = await import('./gallery/gallery-route');
    return rootRoute.addChildren([...routes, galleryRoute]);
  }
  return rootRoute.addChildren(routes);
}
