import { useEffect, useState } from 'react';
import { PageContainer, PageHeader, MetricCard } from '@bs/ui';
import { useSession } from '../auth/session-context';
import { apiClient } from '../lib/api';
import { DashboardData, MOCK_DASHBOARD_DATA, EMPTY_DASHBOARD_DATA } from './types';
import { QuickActionsWidget } from './quick-actions-widget';
import { OnboardingChecklistCard } from './onboarding-card';
import { RecentEditsWidget } from './recent-edits-widget';
import { ContentQueueWidget } from './content-queue-widget';
import { SystemAndHealthWidget } from './system-health-widget';
import { ShortcutsCard } from './shortcuts-card';

export function DashboardRoute() {
  const { session } = useSession();
  const [data, setData] = useState<DashboardData>(EMPTY_DASHBOARD_DATA);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [onboardingDismissed, setOnboardingDismissed] = useState<boolean>(false);

  // Role permissions detection
  const roles = session?.user?.roles ?? [];
  const isOwnerOrAdmin = roles.includes('owner') || roles.includes('admin') || roles.length === 0;
  const isAuthor = roles.includes('author');
  const isContributor = roles.includes('contributor');
  const isViewer = roles.includes('viewer');

  // Role-aware capabilities:
  // - Editors/Authors/Contributors can create content
  // - System widgets, AI review and Site Health are restricted for pure content editor/author/contributor/viewer roles
  const canCreateContent = !isViewer;
  const canUploadMedia = !isViewer && !isContributor;
  const canViewSystem = isOwnerOrAdmin;
  const canReviewAi = isOwnerOrAdmin;

  useEffect(() => {
    let mounted = true;

    async function loadDashboard() {
      try {
        // Attempt to fetch from backend aggregate endpoint if it exists
        const res = await apiClient.request<DashboardData>('GET', '/dashboard');
        if (mounted) {
          if (res.ok && res.data) {
            setData(res.data);
            setError(null);
          } else {
            // In test/gallery mock mode only (window.__BS_TEST_MOCKS__), allow mock fallback.
            // In production/live deployment, never fabricate data on 404/failure:
            // present honest empty/error state.
            const isTestMode = typeof window !== 'undefined' && Boolean((window as unknown as { __BS_TEST_MOCKS__?: boolean }).__BS_TEST_MOCKS__);
            if (isTestMode) {
              setData(MOCK_DASHBOARD_DATA);
              setError(null);
            } else {
              setData(EMPTY_DASHBOARD_DATA);
              if (res.status === 404) {
                setError('Dashboard data endpoint is not yet available.');
              } else {
                setError('Unable to load dashboard data.');
              }
            }
          }
        }
      } catch {
        if (mounted) {
          const isTestMode = typeof window !== 'undefined' && Boolean((window as unknown as { __BS_TEST_MOCKS__?: boolean }).__BS_TEST_MOCKS__);
          if (isTestMode) {
            setData(MOCK_DASHBOARD_DATA);
            setError(null);
          } else {
            setData(EMPTY_DASHBOARD_DATA);
            setError('Network error while loading dashboard.');
          }
        }
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    }

    loadDashboard();

    return () => {
      mounted = false;
    };
  }, []);

  const userName = session?.user?.name || data.greeting?.user_name || 'Administrator';
  const environment = data.greeting?.environment || 'production';

  return (
    <PageContainer width="default" className="space-y-6 pb-12" data-testid="dashboard-page">
      {/* Greeting and Top Section */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <PageHeader
          title={`Welcome back, ${userName}`}
          description={`Environment: ${environment} · Manage content, publishing, and digital assets.`}
        />
        <QuickActionsWidget
          canCreatePosts={canCreateContent}
          canCreatePages={canCreateContent && !isAuthor && !isContributor}
          canUploadMedia={canUploadMedia}
        />
      </div>

      {/* Honest error / unavailable notification if backend 404 occurs in production */}
      {error && !loading && (
        <div
          role="status"
          className="rounded-lg border border-border bg-muted/40 p-3 text-13 text-muted-foreground"
          data-testid="dashboard-status-banner"
        >
          {error}
        </div>
      )}

      {/* Metric Cards Row */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="dashboard-metrics-row">
        <MetricCard
          label="Drafts in progress"
          value={loading ? '-' : String(data.counts?.drafts ?? 0)}
          hint="Unpublished drafts across collections"
          loading={loading}
        />
        <MetricCard
          label="Scheduled for release"
          value={loading ? '-' : String(data.counts?.scheduled ?? 0)}
          hint="Automated upcoming publications"
          loading={loading}
        />
        {canReviewAi ? (
          <MetricCard
            label="Pending AI changes"
            value={loading ? '-' : String(data.counts?.pending_ai_changes ?? 0)}
            hint="Changes awaiting human approval"
            loading={loading}
          />
        ) : (
          <MetricCard
            label="Site Health status"
            value={loading ? '-' : String(data.site_health?.status ?? 'unscanned')}
            hint="Content and SEO validation"
            loading={loading}
          />
        )}
      </div>

      {/* Onboarding Checklist Card (Dismissible, only shown if not completed/dismissed) */}
      {!onboardingDismissed && data.onboarding && !data.onboarding.completed && canCreateContent && (
        <OnboardingChecklistCard
          steps={data.onboarding.steps}
          onDismiss={() => setOnboardingDismissed(true)}
        />
      )}

      {/* Main Widgets Grid */}
      <div className="grid gap-6 lg:grid-cols-3 min-w-0">
        {/* Left Column: Recent Edits (spans 2 cols on lg) */}
        <div className="space-y-6 lg:col-span-2 min-w-0">
          <RecentEditsWidget edits={data.recent_edits ?? []} />
          <ContentQueueWidget
            drafts={data.drafts_waiting ?? []}
            scheduled={data.scheduled_items ?? []}
            pendingAiChanges={data.pending_ai_changes ?? []}
            canReviewAi={canReviewAi}
          />
        </div>

        {/* Right Column: Site Health, System Telemetry, Shortcuts */}
        <div className="space-y-6 min-w-0">
          {canViewSystem && (
            <SystemAndHealthWidget
              siteHealth={data.site_health}
              systemStatus={data.system_status}
              activity={data.recent_activity ?? []}
            />
          )}
          <ShortcutsCard />
        </div>
      </div>
    </PageContainer>
  );
}
