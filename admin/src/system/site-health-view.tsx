import type { FC } from 'react';
import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Badge,
  EmptyState,
  MetricCard,
  PageContainer,
  PageHeader,
  Progress,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@bs/ui';
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  ExternalLink,
  Info,
  RefreshCw,
  Search,
  ShieldCheck,
  XCircle,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/datetime';

export interface HealthIssue {
  rule_id: string;
  severity: 'error' | 'warn' | 'warning' | 'info';
  category: 'seo' | 'geo' | 'content' | 'links' | 'technical' | string;
  message: string;
  entity_type: string;
  entity_id: string;
  entity_title: string | null;
  fix_hint: string;
}

export interface HealthSummary {
  total: number;
  errors: number;
  warnings: number;
  info: number;
  status: 'healthy' | 'warning' | 'critical';
}

export interface SiteHealthReport {
  summary: HealthSummary;
  issues: HealthIssue[];
  rules_checked: number;
  scanned_at: string;
}

export const SiteHealthView: FC = () => {
  const [data, setData] = useState<SiteHealthReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [progressVal, setProgressVal] = useState<number | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);

  // Filter state
  const [severityFilter, setSeverityFilter] = useState<'all' | 'error' | 'warn' | 'info'>('all');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');

  const fetchHealth = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiClient.request<SiteHealthReport>('GET', '/site-health');
      if (res.ok) {
        setData(res.data);
      } else {
        setError(res.problem?.detail || 'Failed to fetch site health report.');
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to fetch site health report.');
    } finally {
      setLoading(false);
    }
  }, []);

  const handleRunNow = async () => {
    setRunning(true);
    setError(null);
    setProgressVal(15);

    const timer = setInterval(() => {
      setProgressVal((prev) => (prev && prev < 85 ? prev + 20 : prev));
    }, 180);

    try {
      const res = await apiClient.request<SiteHealthReport>('POST', '/site-health/run');
      clearInterval(timer);
      setProgressVal(100);
      if (res.ok) {
        setData(res.data);
      } else {
        setError(res.problem?.detail || 'Failed to trigger site health scan.');
      }
    } catch (err: unknown) {
      clearInterval(timer);
      setError(err instanceof Error ? err.message : 'Failed to trigger site health scan.');
    } finally {
      setTimeout(() => {
        setRunning(false);
        setProgressVal(undefined);
      }, 350);
    }
  };

  useEffect(() => {
    void fetchHealth();
  }, [fetchHealth]);

  const categories = useMemo(() => {
    if (!data?.issues) return [];
    const set = new Set<string>();
    data.issues.forEach((i) => {
      if (i.category) set.add(i.category);
    });
    return Array.from(set).sort();
  }, [data?.issues]);

  const filteredIssues = useMemo(() => {
    if (!data?.issues) return [];
    return data.issues.filter((issue) => {
      // Severity filter
      if (severityFilter === 'error' && issue.severity !== 'error') return false;
      if (
        severityFilter === 'warn' &&
        issue.severity !== 'warn' &&
        issue.severity !== 'warning'
      )
        return false;
      if (severityFilter === 'info' && issue.severity !== 'info') return false;

      // Category filter
      if (categoryFilter !== 'all' && issue.category !== categoryFilter) return false;

      // Search query
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchesMsg = issue.message.toLowerCase().includes(q);
        const matchesHint = issue.fix_hint.toLowerCase().includes(q);
        const matchesTitle = issue.entity_title?.toLowerCase().includes(q) ?? false;
        const matchesRule = issue.rule_id.toLowerCase().includes(q);
        if (!matchesMsg && !matchesHint && !matchesTitle && !matchesRule) return false;
      }

      return true;
    });
  }, [data?.issues, severityFilter, categoryFilter, searchQuery]);

  const breadcrumbs = [
    { label: 'Dashboard', href: '/admin/' },
    { label: 'System', href: '/admin/system/audit' },
    { label: 'Site Health' },
  ];

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs items={breadcrumbs} />

      <PageHeader
        title="Site Health"
        description="Automated audit of content, SEO metadata, answer engine optimization (GEO/AEO), and technical link integrity."
        actions={
          <div className="flex items-center gap-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={fetchHealth}
              disabled={loading || running}
            >
              <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <Button
              type="button"
              size="sm"
              onClick={handleRunNow}
              disabled={loading || running}
              data-testid="run-health-scan-btn"
            >
              <Activity className={`h-3.5 w-3.5 mr-1.5 ${running ? 'animate-spin' : ''}`} />
              {running ? 'Running scan…' : 'Run now'}
            </Button>
          </div>
        }
      />

      {running && (
        <div className="rounded-md border border-border bg-card p-4 space-y-2" data-testid="health-progress-bar">
          <div className="flex items-center justify-between text-12 text-muted-foreground font-mono">
            <span>Auditing {data?.rules_checked ?? 15} content and SEO rules…</span>
            <span>{progressVal ?? 50}%</span>
          </div>
          <Progress value={progressVal} aria-label="Site health scan progress" />
        </div>
      )}

      {error && (
        <div className="rounded-md border border-destructive/30 bg-destructive/10 p-4 text-13 text-destructive">
          <div className="flex items-center gap-2 font-medium">
            <XCircle className="h-4 w-4" />
            <span>Audit Error</span>
          </div>
          <p className="mt-1 text-12 opacity-90">{error}</p>
        </div>
      )}

      {/* Summary Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label="Overall Status"
          value={
            loading ? (
              '-'
            ) : data?.summary.status === 'healthy' ? (
              <span className="text-success-text flex items-center gap-1.5 text-16 font-semibold">
                <CheckCircle2 className="h-4 w-4" />
                Healthy
              </span>
            ) : data?.summary.status === 'warning' ? (
              <Badge variant="outline" className="text-12 font-semibold border-amber-600/30 text-amber-700 dark:text-amber-400 bg-amber-500/10">
                <AlertTriangle className="h-3.5 w-3.5 mr-1" />
                Warning
              </Badge>
            ) : (
              <Badge variant="destructive" className="text-12 font-semibold">
                <XCircle className="h-3.5 w-3.5 mr-1" />
                Critical
              </Badge>
            )
          }
          hint={
            data?.scanned_at
              ? `Last scan: ${formatAdminDate(data.scanned_at, {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                })}`
              : 'Never scanned'
          }
          loading={loading}
        />

        <MetricCard
          label="Critical Errors"
          value={loading ? '-' : String(data?.summary.errors ?? 0)}
          hint="Issues requiring immediate attention"
          loading={loading}
        />

        <MetricCard
          label="Warnings"
          value={loading ? '-' : String(data?.summary.warnings ?? 0)}
          hint="SEO or structural improvements"
          loading={loading}
        />

        <MetricCard
          label="Rules Evaluated"
          value={loading ? '-' : String(data?.rules_checked ?? 15)}
          hint="Content, SEO, technical & GEO checks"
          loading={loading}
        />
      </div>

      {/* Issues Section */}
      <div className="space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div
            role="tablist"
            aria-label="Filter issues by severity"
            className="inline-flex h-9 items-center justify-start gap-1 rounded-lg bg-surface-200 p-1 text-muted-foreground w-full sm:w-auto"
          >
            {(
              [
                { id: 'all', label: 'All', count: data?.issues.length ?? 0 },
                { id: 'error', label: 'Errors', count: data?.summary.errors ?? 0 },
                { id: 'warn', label: 'Warnings', count: data?.summary.warnings ?? 0 },
                { id: 'info', label: 'Info', count: data?.summary.info ?? 0 },
              ] as const
            ).map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={severityFilter === tab.id}
                onClick={() => setSeverityFilter(tab.id)}
                className={`inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-12 font-medium transition-colors ${
                  severityFilter === tab.id
                    ? 'bg-card text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-surface-300/50 hover:text-foreground'
                }`}
              >
                {tab.label} ({tab.count})
              </button>
            ))}
          </div>

          <div className="flex items-center gap-2">
            {categories.length > 0 && (
              <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                <SelectTrigger
                  aria-label="Filter by category"
                  className="h-8 w-36 text-12"
                  data-testid="category-filter-select"
                >
                  <SelectValue placeholder="All Categories" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Categories</SelectItem>
                  {categories.map((cat) => (
                    <SelectItem key={cat} value={cat}>
                      {cat.toUpperCase()}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}

            <div className="relative w-full sm:w-60">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <input
                type="search"
                placeholder="Search issues…"
                className="h-8 w-full rounded-md border border-border bg-background pl-8 pr-3 text-12 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
            </div>
          </div>
        </div>

        {/* List of Issue Cards or Empty State */}
        {filteredIssues.length === 0 ? (
          <Card>
            <CardContent className="p-8">
              <EmptyState
                icon={<ShieldCheck className="h-10 w-10 text-success-text" />}
                title={
                  data?.issues && data.issues.length > 0
                    ? 'No issues match your filters'
                    : 'All site health checks passed'
                }
                description={
                  data?.issues && data.issues.length > 0
                    ? 'Try adjusting your severity or category filters to see other issues.'
                    : 'No content gaps, duplicate titles, broken links, or SEO deficiencies were detected.'
                }
                action={
                  data?.issues && data.issues.length > 0 ? (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setSeverityFilter('all');
                        setCategoryFilter('all');
                        setSearchQuery('');
                      }}
                    >
                      Clear filters
                    </Button>
                  ) : (
                    <Button size="sm" onClick={handleRunNow} disabled={running}>
                      Run scan again
                    </Button>
                  )
                }
              />
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3" data-testid="health-issues-list">
            {filteredIssues.map((issue, idx) => {
              const isError = issue.severity === 'error';
              const isWarning = issue.severity === 'warn' || issue.severity === 'warning';

              return (
                <Card
                  key={`${issue.rule_id}-${issue.entity_id}-${idx}`}
                  className="overflow-hidden border border-border bg-card transition hover:border-border/80"
                >
                  <CardHeader className="p-4 pb-2">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div className="flex items-center gap-2 flex-wrap">
                        <Badge
                          variant={isError ? 'destructive' : isWarning ? 'outline' : 'secondary'}
                          className={`text-[11px] font-mono uppercase px-1.5 py-0 h-5 ${
                            isWarning
                              ? 'border-amber-600/30 text-amber-700 dark:text-amber-400 bg-amber-500/10'
                              : ''
                          }`}
                        >
                          {isError ? (
                            <XCircle className="mr-1 h-3 w-3" />
                          ) : isWarning ? (
                            <AlertTriangle className="mr-1 h-3 w-3" />
                          ) : (
                            <Info className="mr-1 h-3 w-3" />
                          )}
                          {issue.severity.toUpperCase()}
                        </Badge>

                        <Badge variant="outline" className="text-[11px] font-mono uppercase px-1.5 py-0 h-5">
                          {issue.category}
                        </Badge>

                        <span className="font-mono text-11 text-foreground-light">
                          {issue.rule_id}
                        </span>
                      </div>

                      {issue.entity_id && (
                        <span className="text-11 font-mono text-foreground-light truncate">
                          ID: {issue.entity_id}
                        </span>
                      )}
                    </div>

                    <CardTitle className="text-14 font-semibold text-foreground pt-2">
                      {issue.message}
                    </CardTitle>
                    {issue.entity_title && (
                      <CardDescription className="text-12 text-foreground-light">
                        Entity: <span className="font-medium text-foreground">{issue.entity_title}</span> ({issue.entity_type})
                      </CardDescription>
                    )}
                  </CardHeader>

                  <CardContent className="p-4 pt-2 space-y-3">
                    {/* Fix Hint & Resolution Guidance */}
                    <div className="rounded bg-surface-100/70 border border-border/80 p-3 text-12 space-y-1.5">
                      <div className="flex items-center gap-1.5 font-medium text-foreground">
                        <ArrowRight className="h-3.5 w-3.5 text-foreground" />
                        <span>Recommended fix:</span>
                      </div>
                      <p className="text-foreground-light leading-relaxed pl-5">
                        {issue.fix_hint}
                      </p>
                    </div>

                    {/* Direct entity edit link if entry or collection */}
                    {issue.entity_type === 'entry' && issue.entity_id && (
                      <div className="flex items-center justify-end pt-1">
                        <Button
                          variant="outline"
                          size="sm"
                          asChild
                          className="h-7 text-11"
                        >
                          <a href={`/admin/entries/${issue.entity_id}`}>
                            <span>Edit entry</span>
                            <ExternalLink className="h-3 w-3 ml-1" />
                          </a>
                        </Button>
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </div>
    </PageContainer>
  );
};
