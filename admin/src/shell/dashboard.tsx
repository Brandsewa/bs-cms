import { PageContainer, PageHeader, PageSection } from '@bs/ui';
import { useSession } from '../auth/session-context';

export function DashboardRoute() {
  const { session } = useSession();

  const primaryRole = session?.roleHandles?.[0] ?? 'User';

  return (
    <PageContainer width="default">
      <PageHeader
        title={`Welcome back, ${session?.user?.name || 'Administrator'}`}
        description="Manage your site, content, taxonomies, and digital assets."
      />

      <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <PageSection title="Quick actions" className="rounded-lg border border-border bg-card p-5">
          <ul className="mt-2 space-y-2 text-13">
            <li>
              <a href="/admin/entries" className="text-primary hover:underline">
                → Manage entries
              </a>
            </li>
            <li>
              <a href="/admin/media" className="text-primary hover:underline">
                → Upload media
              </a>
            </li>
            <li>
              <a href="/admin/taxonomies" className="text-primary hover:underline">
                → Organize terms
              </a>
            </li>
          </ul>
        </PageSection>

        <PageSection title="System overview" className="rounded-lg border border-border bg-card p-5">
          <div className="mt-2 space-y-1 text-13 text-muted-foreground">
            <p>
              Signed in as: <strong className="text-foreground">{session?.user?.email}</strong>
            </p>
            <p>
              Role: <strong className="text-foreground">{primaryRole}</strong>
            </p>
          </div>
        </PageSection>
      </div>
    </PageContainer>
  );
}
