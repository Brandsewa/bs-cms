import {
  DashboardSkeleton,
  TablePageSkeleton,
  FormPageSkeleton,
  MediaGridSkeleton,
  TreePageSkeleton,
} from './components/page-skeletons';
import {
  CollectionsGridSkeleton,
  PagesTreeSkeleton,
  EntryEditorSkeleton,
  PageDesignerSkeleton,
} from './entries/entry-skeletons';
import React, { lazy, Suspense } from 'react';
import { createRoute, redirect, Outlet } from '@tanstack/react-router';
import { RequirePermission } from './shell/require-permission';
import { rootRoute } from './routing';
import { ProtectedAdmin } from './shell/protected-admin';
import { PageLoadingSkeleton } from '@bs/ui';

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
const BrandingView = lazy(() =>
  import('./settings/branding-view').then((m) => ({ default: m.BrandingView }))
);
const DeveloperHubView = lazy(() =>
  import('./developer/developer-hub-view').then((m) => ({ default: m.DeveloperHubView }))
);
const MarketplaceView = lazy(() =>
  import('./marketplace/marketplace-view').then((m) => ({ default: m.MarketplaceView }))
);
const FormsIndexView = lazy(() =>
  import('./forms/forms-index-view').then((m) => ({ default: m.FormsIndexView }))
);
const AiStudioView = lazy(() =>
  import('./ai/ai-studio-view').then((m) => ({ default: m.AiStudioView }))
);
const CollectionListRoute = lazy(() =>
  import('./entries').then((m) => ({ default: m.CollectionListRoute }))
);
const CollectionsIndexView = lazy(() =>
  import('./entries').then((m) => ({ default: m.CollectionsIndexView }))
);
const EntryEditorRoute = lazy(() =>
  import('./entries').then((m) => ({ default: m.EntryEditorRoute }))
);
const PageDesignerRoute = lazy(() =>
  import('./entries/page-designer-route').then((m) => ({ default: m.PageDesignerRoute }))
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

// SPRINT-A BEGIN
const SecuritySettingsView = lazy(() =>
  import('./settings/security-settings-view').then((m) => ({ default: m.SecuritySettingsView }))
);
const ApiTokensView = lazy(() =>
  import('./settings/api-tokens-view').then((m) => ({ default: m.ApiTokensView }))
);
const WebhooksView = lazy(() =>
  import('./settings/webhooks-view').then((m) => ({ default: m.WebhooksView }))
);
const EmailLogView = lazy(() =>
  import('./settings/email-log-view').then((m) => ({ default: m.EmailLogView }))
);
const PublishingSettingsView = lazy(() =>
  import('./settings/publishing-settings-view').then((m) => ({ default: m.PublishingSettingsView }))
);
const AiConnectionsView = lazy(() =>
  import('./settings/ai-connections-view').then((m) => ({ default: m.AiConnectionsView }))
);
const NotificationsPreferencesView = lazy(() =>
  import('./settings/notifications-preferences-view').then((m) => ({ default: m.NotificationsPreferencesView }))
);

const RedirectsView = lazy(() =>
  import('./system/redirects-view').then((m) => ({ default: m.RedirectsView }))
);

const JobsView = lazy(() =>
  import('./developer/jobs-view').then((m) => ({ default: m.JobsView }))
);
const SchemaBuilderView = lazy(() =>
  import('./developer/schema-builder-view').then((m) => ({ default: m.SchemaBuilderView }))
);
const ApiExplorerView = lazy(() =>
  import('./developer/api-explorer-view').then((m) => ({ default: m.ApiExplorerView }))
);
// SPRINT-A END

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
const SiteHealthView = lazy(() =>
  import('./system/site-health-view').then((m) => ({ default: m.SiteHealthView }))
);

// SPRINT-D BEGIN
// Account views (independently lazy-loaded chunks)
const MyAccountView = lazy(() =>
  import('./account/my-account-view').then((m) => ({ default: m.MyAccountView }))
);
// SPRINT-D END

// Shell fallbacks & placeholders
const ForbiddenRoute = lazy(() =>
  import('./shell/forbidden').then((m) => ({ default: m.ForbiddenRoute }))
);
const NotFoundRoute = lazy(() =>
  import('./shell/not-found').then((m) => ({ default: m.NotFoundRoute }))
);

function RouteSuspense({ children, fallback }: { children: React.ReactNode; fallback?: React.ReactNode }) {
  return (
    <Suspense
      fallback={fallback ?? <PageLoadingSkeleton lines={6} />}
    >
      {children}
    </Suspense>
  );
}


// Persistent shell: authentication + sidebar/topbar chrome render ONCE here
// (pathless layout route); child routes swap only the content area, so
// navigating between sections never remounts the shell.
export const shellRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: '_shell',
  component: () => (
    <ProtectedAdmin>
      <Outlet />
    </ProtectedAdmin>
  ),
});

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
  getParentRoute: () => shellRoute,
  path: '/',
  component: () => (
    <RouteSuspense fallback={<DashboardSkeleton />}>
        <DashboardRoute />
      </RouteSuspense>
  ),
});

// Content & Structure
export const entriesRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/entries',
  component: () => (
    <RequirePermission permission="collection:read">
      <RouteSuspense fallback={<CollectionsGridSkeleton />}>
        <CollectionsIndexView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const collectionEntriesRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/entries/$collection',
  component: () => {
    const params = collectionEntriesRoute.useParams();
    return (
      <RequirePermission permission="collection:read">
        <RouteSuspense fallback={params.collection === "pages" ? <PagesTreeSkeleton /> : <TablePageSkeleton />}>
          <CollectionListRoute collectionHandle={params.collection} />
        </RouteSuspense>
      </RequirePermission>
    );
  },
});

export const entryNewRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/entries/$collection/new',
  component: () => {
    const params = entryNewRoute.useParams();
    return (
      <RequirePermission permission="collection:create">
        <RouteSuspense fallback={<EntryEditorSkeleton collectionHandle={params.collection} />}>
          <EntryEditorRoute collectionHandle={params.collection} />
        </RouteSuspense>
      </RequirePermission>
    );
  },
});

export const entryEditRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/entries/$collection/$id',
  component: () => {
    const params = entryEditRoute.useParams();
    return (
      <RequirePermission permission="collection:update">
        <RouteSuspense fallback={<EntryEditorSkeleton collectionHandle={params.collection} />}>
          <EntryEditorRoute collectionHandle={params.collection} entryId={params.id} />
        </RouteSuspense>
      </RequirePermission>
    );
  },
});

// Full-screen visual designer for pages (Puck). Chrome-less: owns the viewport.
export const entryDesignRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/design/$collection/$id',
  component: () => {
    const params = entryDesignRoute.useParams();
    return (
      <ProtectedAdmin permission="collection:update" chrome={false}>
        <RouteSuspense fallback={<PageDesignerSkeleton />}>
          <PageDesignerRoute collectionHandle={params.collection} entryId={params.id} />
        </RouteSuspense>
      </ProtectedAdmin>
    );
  },
});

export const taxonomiesRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/taxonomies',
  component: () => (
    <RequirePermission permission="taxonomies:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Taxonomies" description="Manage hierarchical terms and categories." />}>
        <TaxonomiesRoute />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const mediaRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/media',
  component: () => (
    <RequirePermission permission="media:read">
      <RouteSuspense fallback={<MediaGridSkeleton count={12} />}>
        <MediaLibraryRoute />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const menusRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/menus',
  component: () => (
    <RequirePermission permission="menus:read">
      <RouteSuspense fallback={<TreePageSkeleton />}>
        <MenusRoute />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const brandingRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/branding',
  component: () => (
    <RequirePermission permission="site:read">
      <RouteSuspense>
        <BrandingView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const aiRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/ai',
  component: () => (
    <RequirePermission permission="ai:read">
      <RouteSuspense>
        <AiStudioView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

// Users & Roles Routes
export const usersRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/users',
  component: () => (
    <RequirePermission permission="users:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Users" description="Manage user accounts and invitations." />}>
        <UsersLayout activeTab="users">
          <UsersListView />
        </UsersLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const usersRolesRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/users/roles',
  component: () => (
    <RequirePermission permission="users:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Roles & Permissions" description="Capabilities matrix mapped across system roles." />}>
        <UsersLayout activeTab="roles">
          <RolesMatrixView />
        </UsersLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const developerRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/developer',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense>
        <DeveloperHubView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const marketplaceRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/marketplace',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense>
        <MarketplaceView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const formsRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/forms',
  component: () => (
    <RequirePermission permission="collection:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Forms & Inquiries" description="Manage contact forms and inquiries." />}>
        <FormsIndexView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

// Settings Routes
export const settingsRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings',
  beforeLoad: () => {
    throw redirect({ to: '/settings/general' });
  },
});

export const settingsGeneralRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/general',
  component: () => (
    <RequirePermission permission="settings.manage">
      <RouteSuspense fallback={<FormPageSkeleton title="General Settings" description="Configure website title, URL, and operational properties." />}>
        <SettingsLayout activeTab="general">
          <GeneralSettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsEmailRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/email',
  component: () => (
    <RequirePermission permission="settings.manage">
      <RouteSuspense fallback={<FormPageSkeleton title="Email Delivery" description="SMTP configuration, sending addresses, and delivery test runner." />}>
        <SettingsLayout activeTab="email">
          <EmailSettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

// System Routes
export const systemAuditRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/system/audit',
  component: () => (
    <RequirePermission permission="audit:read">
      <RouteSuspense>
        <AuditLogView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const systemHealthRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/system/health',
  component: () => (
    <RequirePermission permission="site:health">
      <RouteSuspense>
        <SiteHealthView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

// Redirect /settings/audit to /system/audit for dashboard and legacy link compatibility
export const settingsAuditRedirectRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/audit',
  beforeLoad: () => {
    throw redirect({ to: '/system/audit' });
  },
});

// Redirect /settings/health to /system/health for dashboard and legacy link compatibility
export const settingsHealthRedirectRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/health',
  beforeLoad: () => {
    throw redirect({ to: '/system/health' });
  },
});

// SPRINT-A BEGIN: Settings Sub-routes
export const settingsSecurityRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/security',
  component: () => (
    <RequirePermission permission="settings.manage">
      <RouteSuspense fallback={<FormPageSkeleton title="Security Settings" description="Manage session policies and authentication requirements." />}>
        <SettingsLayout activeTab="security">
          <SecuritySettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsTokensRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/tokens',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense fallback={<TablePageSkeleton title="API Tokens" description="Manage programmatic API keys and access tokens." />}>
        <SettingsLayout activeTab="tokens">
          <ApiTokensView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsWebhooksRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/webhooks',
  component: () => (
    <RequirePermission permission="settings.manage">
      <RouteSuspense fallback={<TablePageSkeleton title="Webhooks" description="Configure HTTP callbacks triggered by events." />}>
        <SettingsLayout activeTab="webhooks">
          <WebhooksView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsEmailLogRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/email-log',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Email Delivery Log" description="Audit transactional email attempts." />}>
        <SettingsLayout activeTab="email-log">
          <EmailLogView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsPublishingRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/publishing',
  component: () => (
    <RequirePermission permission="settings.manage">
      <RouteSuspense fallback={<FormPageSkeleton title="Publishing & Deployments" description="Static build pipelines, preview deployments, and webhooks." />}>
        <SettingsLayout activeTab="publishing">
          <PublishingSettingsView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsAiRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/ai',
  component: () => (
    <RequirePermission permission="ai:read">
      <RouteSuspense fallback={<FormPageSkeleton title="AI Connections" description="Configure Gemini API keys and AI generation models." />}>
        <SettingsLayout activeTab="ai">
          <AiConnectionsView />
        </SettingsLayout>
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const settingsNotificationsRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/settings/notifications',
  component: () => (
    <RouteSuspense>
        <SettingsLayout activeTab="notifications">
          <NotificationsPreferencesView />
        </SettingsLayout>
      </RouteSuspense>
  ),
});

// SPRINT-A: Redirects Route
export const redirectsRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/redirects',
  component: () => (
    <RequirePermission permission="site:read">
      <RouteSuspense fallback={<TablePageSkeleton title="URL Redirects" description="Manage HTTP 301 and 302 redirects." />}>
        <RedirectsView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

// SPRINT-A: Developer Routes
export const developerJobsRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/developer/jobs',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense fallback={<TablePageSkeleton title="Background Jobs" description="Inspect background job queues." />}>
        <JobsView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const developerSchemaRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/developer/schema',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense>
        <SchemaBuilderView />
      </RouteSuspense>
    </RequirePermission>
  ),
});

export const developerApiExplorerRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/developer/api',
  component: () => (
    <RequirePermission permission="settings:read">
      <RouteSuspense fallback={<TablePageSkeleton title="API Explorer" description="Interactive OpenAPI documentation." />}>
        <ApiExplorerView />
      </RouteSuspense>
    </RequirePermission>
  ),
});
// SPRINT-A END

// SPRINT-D BEGIN
// User Account Route
export const accountRoute = createRoute({
  getParentRoute: () => shellRoute,
  path: '/account',
  component: () => (
    <RouteSuspense fallback={<FormPageSkeleton title="My Account" description="Manage your personal profile, security credentials, and active sessions." />}>
      <MyAccountView />
    </RouteSuspense>
  ),
});
// SPRINT-D END

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
  const shellChildren = [
    indexRoute,
    entriesRoute,
    collectionEntriesRoute,
    entryNewRoute,
    entryEditRoute,
    taxonomiesRoute,
    mediaRoute,
    menusRoute,
    brandingRoute,
    aiRoute,
    usersRoute,
    usersRolesRoute,
    developerRoute,
    marketplaceRoute,
    formsRoute,
    settingsRoute,
    settingsGeneralRoute,
    settingsEmailRoute,
    settingsSecurityRoute,
    settingsTokensRoute,
    settingsWebhooksRoute,
    settingsEmailLogRoute,
    settingsPublishingRoute,
    settingsAiRoute,
    settingsNotificationsRoute,
    redirectsRoute,
    developerJobsRoute,
    developerSchemaRoute,
    developerApiExplorerRoute,
    systemAuditRoute,
    settingsAuditRedirectRoute,
    systemHealthRoute,
    settingsHealthRedirectRoute,
    accountRoute,
  ];

  const shellGroup = shellRoute.addChildren(shellChildren);

  if (import.meta.env.DEV) {
    const { galleryRoute } = await import('./gallery/gallery-route');
    return rootRoute.addChildren([
      loginRoute,
      forgotPasswordRoute,
      resetPasswordRoute,
      acceptInvitationRoute,
      oauthReturnRoute,
      forbiddenRoute,
      notFoundRoute,
      entryDesignRoute,
      shellGroup,
      galleryRoute,
    ]);
  }

  return rootRoute.addChildren([
    loginRoute,
    forgotPasswordRoute,
    resetPasswordRoute,
    acceptInvitationRoute,
    oauthReturnRoute,
    forbiddenRoute,
    notFoundRoute,
    entryDesignRoute,
    shellGroup,
  ]);
}
