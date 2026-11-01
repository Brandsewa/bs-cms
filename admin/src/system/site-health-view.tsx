import type { FC } from 'react';
import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Breadcrumbs,
  Button,
  Badge,
  DataTable,
  type DataTableBulkAction,
  type DataTableColumn,
  type DataTableState,
  type StatusTab,
  PageContainer,
  PageHeader,
  Progress,
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  Spinner,
  toast,
} from '@bs/ui';
import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  Eye,
  EyeOff,
  ExternalLink,
  Info,
  RefreshCw,
  XCircle,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/datetime';

export interface HealthIssue {
  rule_id: string;
  severity: 'error' | 'warn' | 'warning' | 'info' | 'dismissed';
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
  dismissed_count?: number;
  dismissed_keys?: string[];
}

/** A browsable row: an active issue, or a dismissed key restored from the report. */
interface HealthRow {
  key: string;
  severity: 'error' | 'warn' | 'info' | 'dismissed';
  category: string;
  rule_id: string;
  message: string;
  entity_type: string;
  entity_id: string;
  entity_title: string | null;
  fix_hint: string;
  dismissed: boolean;
}



export const SiteHealthView: FC = () => {
  const [data, setData] = useState<SiteHealthReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [progressVal, setProgressVal] = useState<number | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [detail, setDetail] = useState<HealthRow | null>(null);
  const [tableState, setTableState] = useState<DataTableState>({
    pagination: { pageIndex: 0, pageSize: 50 },
    density: 'compact',
  });

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

  /** Active issues + dismissed keys merged into one browsable row set. */
  const rows = useMemo<HealthRow[]>(() => {
    if (!data) return [];
    const active: HealthRow[] = (data.issues ?? []).map((issue, idx) => ({
      key: `${issue.rule_id}|${issue.entity_id}|${idx}`,
      severity: issue.severity === 'info' ? 'info' : issue.severity === 'error' ? 'error' : 'warn',
      category: issue.category,
      rule_id: issue.rule_id,
      message: issue.message,
      entity_type: issue.entity_type,
      entity_id: issue.entity_id,
      entity_title: issue.entity_title,
      fix_hint: issue.fix_hint,
      dismissed: false,
    }));
    const activeKeys = new Set(active.map((r) => r.key));
    const dismissed: HealthRow[] = (data.dismissed_keys ?? [])
      .filter((k) => !activeKeys.has(k))
      .map((k) => {
        const [rule = 'rule', entity = ''] = k.split('|');
        return {
          key: k,
          severity: 'dismissed' as const,
          category: '—',
          rule_id: rule,
          message: `Dismissed finding (${rule}${entity ? ` on ${entity}` : ''}).`,
          entity_type: 'entry',
          entity_id: entity,
          entity_title: null,
          fix_hint: 'Restore this finding to review it again.',
          dismissed: true,
        };
      });
    return [...active, ...dismissed];
  }, [data]);

  const statusTabs = useMemo<StatusTab[]>(() => {
    const counts = {
      all: rows.length,
      error: rows.filter((r) => r.severity === 'error').length,
      warn: rows.filter((r) => r.severity === 'warn').length,
      info: rows.filter((r) => r.severity === 'info').length,
      dismissed: rows.filter((r) => r.dismissed).length,
    };
    const tabs: StatusTab[] = [
      { id: 'all', label: 'All', count: counts.all },
      { id: 'error', label: 'Errors', count: counts.error },
      { id: 'warn', label: 'Warnings', count: counts.warn },
      { id: 'info', label: 'Info', count: counts.info },
    ];
    if (counts.dismissed > 0 || data?.dismissed_keys?.length) {
      tabs.push({ id: 'dismissed', label: 'Dismissed', count: counts.dismissed });
    }
    return tabs;
  }, [rows, data?.dismissed_keys]);

  const columns = useMemo<Array<DataTableColumn<HealthRow>>>(
    () => [
      {
        id: 'severity',
        header: 'Severity',
        fieldType: 'status',
        accessor: (row) => row.severity,
        width: 110,
        cell: (row) => {
          const map = {
            error: { variant: 'destructive' as const, icon: XCircle, label: 'ERROR' },
            warn: { variant: 'outline' as const, icon: AlertTriangle, label: 'WARN' },
            info: { variant: 'secondary' as const, icon: Info, label: 'INFO' },
            dismissed: { variant: 'secondary' as const, icon: EyeOff, label: 'DISMISSED' },
          } as const;
          const { variant, icon: Icon, label } = map[row.severity];
          return (
            <Badge variant={variant} className="text-[11px] font-mono uppercase px-1.5 py-0 h-5">
              <Icon className="mr-1 h-3 w-3" />
              {label}
            </Badge>
          );
        },
      },
      {
        id: 'category',
        header: 'Category',
        fieldType: 'text',
        accessor: (row) => row.category,
        width: 100,
        cell: (row) => <Badge variant="outline" className="text-[11px] font-mono uppercase px-1.5 py-0 h-5">{row.category}</Badge>,
      },
      {
        id: 'rule_id',
        header: 'Rule',
        fieldType: 'text',
        accessor: (row) => row.rule_id,
        searchable: true,
        sortable: true,
        width: 200,
        cell: (row) => <span className="font-mono text-11 text-foreground-light">{row.rule_id}</span>,
      },
      {
        id: 'entity',
        header: 'Entity',
        fieldType: 'text',
        accessor: (row) => row.entity_title || row.entity_id,
        searchable: true,
        width: 200,
        cell: (row) => (
          <span className="text-12 text-foreground truncate">
            {row.entity_title || row.entity_id}
          </span>
        ),
      },
      {
        id: 'message',
        header: 'Issue',
        fieldType: 'text',
        accessor: (row) => row.message,
        searchable: true,
        cell: (row) => (
          <span className="truncate text-12 text-foreground" title={row.message}>
            {row.message}
          </span>
        ),
      },
    ],
    []
  );

  const issueKey = (row: HealthRow) => `${row.rule_id}|${row.entity_id}`;

  const bulkDismiss = async (selected: HealthRow[]) => {
    const keys = selected.map(issueKey);
    const res = await apiClient.request('POST', '/site-health/dismiss', { body: { keys } });
    if (!res.ok) throw new Error(res.problem?.detail || 'Failed to dismiss findings.');
    toast.success(`${selected.length} finding${selected.length === 1 ? '' : 's'} dismissed.`);
    void fetchHealth();
    return { succeeded: selected, failed: [] };
  };

  const bulkRestore = async (selected: HealthRow[]) => {
    const keys = selected.map(issueKey);
    const res = await apiClient.request('POST', '/site-health/restore', { body: { keys } });
    if (!res.ok) throw new Error(res.problem?.detail || 'Failed to restore findings.');
    toast.success(`${selected.length} finding${selected.length === 1 ? '' : 's'} restored.`);
    void fetchHealth();
    return { succeeded: selected, failed: [] };
  };

  const showingDismissed = tableState.statusTab === 'dismissed';

  const bulkActions = useMemo<Array<DataTableBulkAction<HealthRow>>>(() => {
    if (showingDismissed) {
      return [
        {
          id: 'restore',
          label: 'Restore selected',
          icon: Eye,
          confirm: 'confirm',
          run: bulkRestore,
        },
      ];
    }
    return [
      {
        id: 'dismiss',
        label: 'Dismiss selected',
        icon: EyeOff,
        confirm: 'confirm',
        run: bulkDismiss,
      },
    ];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showingDismissed, data?.dismissed_keys]);

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

      {/* Compact summary strip */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-lg border border-border bg-card p-3.5">
          <p className="text-xs font-medium text-foreground-lighter">Overall Status</p>
          <p className="mt-1.5">
            {loading ? (
              <Spinner className="h-4 w-4 text-primary" />
            ) : data?.summary.status === 'healthy' ? (
              <span className="text-success-text flex items-center gap-1.5 text-14 font-semibold">
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
            )}
          </p>
          <p className="mt-1 text-11 text-muted-foreground">
            {data?.scanned_at
              ? `Last scan: ${formatAdminDate(data.scanned_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`
              : 'Never scanned'}
          </p>
        </div>

        <div className="rounded-lg border border-border bg-card p-3.5">
          <p className="text-xs font-medium text-foreground-lighter">Errors / Warnings</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-foreground">
            {loading ? '–' : `${data?.summary.errors ?? 0} / ${data?.summary.warnings ?? 0}`}
          </p>
          <p className="mt-1 text-11 text-muted-foreground">Critical then SEO or structural</p>
        </div>

        <div className="rounded-lg border border-border bg-card p-3.5">
          <p className="text-xs font-medium text-foreground-lighter">Info Findings</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-foreground">
            {loading ? '–' : String(data?.summary.info ?? 0)}
          </p>
          <p className="mt-1 text-11 text-muted-foreground">GEO / AEO improvements</p>
        </div>

        <div className="rounded-lg border border-border bg-card p-3.5">
          <p className="text-xs font-medium text-foreground-lighter">Rules Evaluated</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-foreground">
            {loading ? '–' : String(data?.rules_checked ?? 15)}
          </p>
          <p className="mt-1 text-11 text-muted-foreground">
            {data?.dismissed_count ? `${data.dismissed_count} dismissed` : 'Content, SEO, technical & GEO'}
          </p>
        </div>
      </div>

      <DataTable<HealthRow>
        aria-label="Site health issues table"
        columns={columns}
        data={rows}
        getRowId={(row) => row.key}
        loading={loading}
        onRefresh={fetchHealth}
        onRowOpen={(row) => setDetail(row)}
        state={tableState}
        onStateChange={(next) => setTableState((prev) => ({ ...prev, ...next }))}
        statusTabs={{ tabs: statusTabs, active: tableState.statusTab ?? 'all', onChange: (id) => setTableState((s) => ({ ...s, statusTab: id })) }}
        bulkActions={bulkActions}
        emptyState={{
          title: rows.length > 0 ? 'No issues match this filter' : 'All site health checks passed',
          description:
            rows.length > 0
              ? 'Try a different severity tab to see other findings.'
              : 'No content gaps, duplicate titles, broken links, or SEO deficiencies were detected.',
          action: (
            <Button size="sm" onClick={handleRunNow} disabled={running}>
              Run scan again
            </Button>
          ),
        }}
        exportFileName="site-health"
        storageKey="bs-site-health-table"
      />

      {/* Detail drawer */}
      <Sheet open={detail !== null} onOpenChange={(open) => !open && setDetail(null)}>
        <SheetContent className="w-[480px] sm:w-[540px] overflow-y-auto bg-background" side="right">
          {detail && (
            <>
              <SheetHeader className="space-y-2 pb-4">
                <div className="flex items-center gap-2">
                  <Badge
                    variant={detail.severity === 'error' ? 'destructive' : 'outline'}
                    className="text-[11px] font-mono uppercase px-1.5 py-0 h-5"
                  >
                    {detail.severity.toUpperCase()}
                  </Badge>
                  <Badge variant="outline" className="text-[11px] font-mono uppercase px-1.5 py-0 h-5">
                    {detail.category}
                  </Badge>
                </div>
                <SheetTitle className="text-sm font-semibold leading-snug">{detail.message}</SheetTitle>
                <SheetDescription className="text-12">
                  {detail.entity_title ? `${detail.entity_title} (${detail.entity_type})` : detail.entity_type}
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-4 pb-6 text-13">
                <div>
                  <p className="text-11 font-medium uppercase tracking-wider text-muted-foreground">Rule</p>
                  <p className="mt-1 font-mono text-12 text-foreground">{detail.rule_id}</p>
                </div>
                <div>
                  <p className="text-11 font-medium uppercase tracking-wider text-muted-foreground">Entity ID</p>
                  <p className="mt-1 font-mono text-12 text-foreground">{detail.entity_id}</p>
                </div>
                <div className="rounded-md border border-border bg-surface-100/70 p-3">
                  <p className="flex items-center gap-1.5 font-medium text-foreground">
                    <Activity className="h-3.5 w-3.5" />
                    Recommended fix
                  </p>
                  <p className="mt-1.5 pl-5 leading-relaxed text-foreground-light">{detail.fix_hint}</p>
                </div>

                <div className="flex flex-col gap-2 pt-2">
                  {detail.entity_type === 'entry' && detail.entity_id && (
                    <Button asChild variant="outline" size="sm">
                      <a href={`/admin/entries/${detail.entity_id}`}>
                        Edit entry
                        <ExternalLink className="ml-1.5 h-3 w-3" />
                      </a>
                    </Button>
                  )}
                  <Button
                    variant={detail.dismissed ? 'default' : 'outline'}
                    size="sm"
                    onClick={async () => {
                      if (detail.dismissed) await bulkRestore([detail]);
                      else await bulkDismiss([detail]);
                      setDetail(null);
                    }}
                  >
                    {detail.dismissed ? <Eye className="h-3.5 w-3.5" /> : <EyeOff className="h-3.5 w-3.5" />}
                    {detail.dismissed ? 'Restore finding' : 'Dismiss finding'}
                  </Button>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </PageContainer>
  );
};
