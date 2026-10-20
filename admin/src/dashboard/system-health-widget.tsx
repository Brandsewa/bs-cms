import { Card, CardHeader, CardTitle, CardContent, StatusDot } from '@bs/ui';
import { Activity, ShieldCheck, Server, AlertTriangle, ArrowRight } from 'lucide-react';

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
            <CardTitle className="text-15 font-semibold">Site health & system status</CardTitle>
          </div>
          <a
            href="/admin/settings/system"
            className="inline-flex items-center gap-1 text-12 font-medium text-primary hover:underline"
          >
            System info <ArrowRight className="h-3 w-3" />
          </a>
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
                      ? 'text-success'
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
                    ? 'bg-success/10 text-success'
                    : 'bg-warning/10 text-warning'
                }`}
              >
                {siteHealth.status}
              </span>
            </div>

            {siteHealth.top_issues && siteHealth.top_issues.length > 0 ? (
              <div className="mt-2 space-y-1">
                {siteHealth.top_issues.map((issue) => (
                  <p key={issue.rule_id} className="text-12 text-muted-foreground flex items-center gap-1.5">
                    <AlertTriangle className="h-3.5 w-3.5 text-warning shrink-0" />
                    <span className="truncate">{issue.message}</span>
                  </p>
                ))}
              </div>
            ) : (
              <p className="mt-1 text-12 text-muted-foreground">All content and SEO rules passing cleanly.</p>
            )}
          </div>
        )}

        {/* System telemetry overview (Cron, Mail, Disk, PHP) */}
        {systemStatus && (
          <div className="grid grid-cols-2 gap-2 text-12">
            <div className="rounded border border-border/60 p-2.5">
              <span className="text-muted-foreground block text-11">Background Cron</span>
              <div className="mt-1 flex items-center gap-1.5 font-medium text-foreground capitalize">
                <StatusDot
                  colour={systemStatus.cron === 'healthy' ? 'var(--success)' : 'var(--warning)'}
                  label={`Cron: ${systemStatus.cron}`}
                />
                {systemStatus.cron}
              </div>
            </div>
            <div className="rounded border border-border/60 p-2.5">
              <span className="text-muted-foreground block text-11">Mail Delivery</span>
              <div className="mt-1 flex items-center gap-1.5 font-medium text-foreground capitalize">
                <StatusDot
                  colour={systemStatus.mail === 'healthy' ? 'var(--success)' : 'var(--warning)'}
                  label={`Mail: ${systemStatus.mail}`}
                />
                {systemStatus.mail}
              </div>
            </div>
            <div className="rounded border border-border/60 p-2.5">
              <span className="text-muted-foreground block text-11">Storage Disk</span>
              <p className="mt-1 font-medium text-foreground tabular-nums">
                {systemStatus.disk_usage_percent}% used
              </p>
            </div>
            <div className="rounded border border-border/60 p-2.5">
              <span className="text-muted-foreground block text-11">PHP Runtime</span>
              <p className="mt-1 font-medium text-foreground tabular-nums">
                PHP {systemStatus.php_version}
              </p>
            </div>
          </div>
        )}

        {/* Recent Activity stream */}
        <div className="pt-2 border-t border-border/60">
          <div className="flex items-center gap-1.5 text-12 font-medium text-muted-foreground mb-2">
            <Activity className="h-3.5 w-3.5" /> Recent operations
          </div>
          {activity.length === 0 ? (
            <p className="text-12 text-muted-foreground italic">No recent log entries.</p>
          ) : (
            <ul className="space-y-1.5 text-12" role="list">
              {activity.slice(0, 3).map((act) => (
                <li key={act.id} className="flex items-center justify-between text-muted-foreground">
                  <span className="truncate max-w-[260px]">
                    <strong className="text-foreground font-medium">{act.actor}</strong> {act.action} {act.target}
                  </span>
                  <span className="text-11 tabular-nums whitespace-nowrap ml-2">
                    {formatDate(act.created_at)}
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
