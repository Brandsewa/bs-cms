import { Card, CardHeader, CardTitle, CardContent, StatusDot } from '@bs/ui';
import { ShieldCheck, Server } from 'lucide-react';

interface SiteHealthSummary {
  status: 'healthy' | 'warning' | 'critical';
  total_issues: number;
  errors: number;
  warnings: number;
  scanned_at?: string;
  top_issues?: Array<{
    rule_id: string;
    message: string;
    severity: 'error' | 'warn' | 'info';
  }>;
}

interface SystemStatusSummary {
  cron: 'healthy' | 'warning' | 'error' | 'disabled';
  mail: 'healthy' | 'warning' | 'error' | 'unconfigured';
  disk_usage_percent: number;
  php_version: string;
}

interface RecentActivityItem {
  id: string;
  action: string;
  actor: string;
  target: string;
  created_at: string;
}

function formatDate(iso: string) {
  try {
    const d = new Date(iso);
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  } catch {
    return iso;
  }
}

export function SystemAndHealthWidget({
  siteHealth,
  systemStatus,
  activity,
  className,
}: {
  siteHealth?: SiteHealthSummary;
  systemStatus?: SystemStatusSummary;
  activity: RecentActivityItem[];
  className?: string;
}) {
  return (
    <Card className={`min-w-0 ${className || ''}`} data-testid="system-health-widget">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Server className="h-4 w-4 text-muted-foreground" />
            <CardTitle className="text-15 font-semibold">Site & system health</CardTitle>
          </div>
          <span className="text-11 text-muted-foreground uppercase font-mono">Telemetry</span>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Site Health Overview */}
        {siteHealth && (
          <div className="rounded-md border border-border/80 bg-surface-100/30 p-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <ShieldCheck
                  className={`h-4 w-4 ${
                    siteHealth.status === 'healthy'
                      ? 'text-success-text'
                      : siteHealth.status === 'warning'
                      ? 'text-warning'
                      : 'text-destructive'
                  }`}
                />
                <span className="text-13 font-medium text-foreground">Content & SEO Health</span>
              </div>
              <span
                className={`text-11 font-semibold uppercase px-2 py-0.5 rounded ${
                  siteHealth.status === 'healthy'
                    ? 'bg-success/15 text-success-text'
                    : 'bg-warning/10 text-warning'
                }`}
              >
                {siteHealth.status}
              </span>
            </div>

            <div className="mt-2 grid grid-cols-2 gap-2 text-12 text-muted-foreground pt-2 border-t border-border/60">
              <div>
                <span>Issues: </span>
                <span className="font-medium text-foreground tabular-nums">
                  {siteHealth.total_issues}
                </span>
                {siteHealth.errors > 0 && (
                  <span className="ml-1 text-destructive font-medium">({siteHealth.errors} err)</span>
                )}
              </div>
              <div className="text-right">
                <a
                  href="/admin/settings/health"
                  className="text-primary hover:underline font-medium"
                >
                  View report
                </a>
              </div>
            </div>
          </div>
        )}

        {/* Runtime & Daemon Status */}
        {systemStatus && (
          <div className="space-y-2 text-12">
            <div className="flex items-center justify-between py-1 border-b border-border/50">
              <span className="text-muted-foreground">Background Cron</span>
              <div className="flex items-center gap-1.5 font-medium">
                <StatusDot
                  colour={
                    systemStatus.cron === 'healthy'
                      ? 'var(--success)'
                      : systemStatus.cron === 'warning'
                      ? 'var(--warning)'
                      : 'var(--destructive)'
                  }
                  label={`Cron: ${systemStatus.cron}`}
                />
                <span className="capitalize">{systemStatus.cron}</span>
              </div>
            </div>

            <div className="flex items-center justify-between py-1 border-b border-border/50">
              <span className="text-muted-foreground">Mail Delivery</span>
              <div className="flex items-center gap-1.5 font-medium">
                <StatusDot
                  colour={
                    systemStatus.mail === 'healthy'
                      ? 'var(--success)'
                      : systemStatus.mail === 'unconfigured'
                      ? 'var(--muted-foreground)'
                      : 'var(--warning)'
                  }
                  label={`Mail: ${systemStatus.mail}`}
                />
                <span className="capitalize">{systemStatus.mail}</span>
              </div>
            </div>

            <div className="flex items-center justify-between py-1 border-b border-border/50">
              <span className="text-muted-foreground">Disk Storage</span>
              <span className="tabular-nums font-medium text-foreground">
                {systemStatus.disk_usage_percent}% used
              </span>
            </div>

            <div className="flex items-center justify-between py-1">
              <span className="text-muted-foreground">PHP Runtime</span>
              <span className="font-mono text-11 text-muted-foreground">
                PHP {systemStatus.php_version}
              </span>
            </div>
          </div>
        )}

        {/* Recent Audit / Security Activity Feed */}
        <div>
          <div className="flex items-center justify-between text-12 font-medium text-muted-foreground mb-2">
            <span>Recent operations & audit</span>
            <a href="/admin/settings/audit" className="text-11 text-primary hover:underline">
              All logs
            </a>
          </div>
          {activity.length === 0 ? (
            <p className="text-12 text-muted-foreground italic">No recent system activity recorded.</p>
          ) : (
            <ul className="space-y-1.5 text-12" role="list">
              {activity.slice(0, 3).map((item) => (
                <li
                  key={item.id}
                  className="flex items-center justify-between rounded bg-surface-100/30 p-1.5"
                >
                  <div className="truncate pr-2">
                    <span className="font-medium text-foreground">{item.actor}</span>{' '}
                    <span className="text-muted-foreground">{item.action}</span>
                  </div>
                  <span className="text-10 text-muted-foreground whitespace-nowrap font-mono">
                    {formatDate(item.created_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
