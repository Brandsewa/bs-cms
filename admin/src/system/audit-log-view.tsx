import { useState, useMemo, useEffect, useCallback } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  DataTable,
  type DataTableColumn,
  type DataTableFilter,
  type DataTableState,
  PageContainer,
  PageHeader,
} from '@bs/ui';
import {
  CheckCircle,
  XCircle,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { parseApiUtcDate, formatAdminDate } from '../lib/datetime';
import { AuditDetailSheet } from './audit-detail-sheet';
import type { AuditEntry, AuditListResponse } from '@bs/client';

export function AuditLogView() {
  const [items, setItems] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<{ message: string; details?: string } | null>(null);
  const [selectedEntry, setSelectedEntry] = useState<AuditEntry | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);

  // Filters & State
  const [tableState, setTableState] = useState<DataTableState>({
    pagination: { pageIndex: 0, pageSize: 50 },
    sorting: [{ id: 'id', desc: true }],
    density: 'compact',
  });

  const [activeFilters, setActiveFilters] = useState<import('@bs/ui').ActiveFilter[]>([]);

  // Distinct filter options collected dynamically from items
  const actorOptions = useMemo(() => {
    const set = new Set<string>();
    items.forEach((i) => {
      const label = i.actor_label || i.actor_id;
      if (label) set.add(label);
    });
    return Array.from(set).sort().map((v) => ({ value: v, label: v }));
  }, [items]);

  const actionOptions = useMemo(() => {
    const set = new Set<string>();
    items.forEach((i) => {
      if (i.action) set.add(i.action);
    });
    return Array.from(set).sort().map((v) => ({ value: v, label: v }));
  }, [items]);

  const subjectOptions = useMemo(() => {
    const set = new Set<string>();
    items.forEach((i) => {
      if (i.subject_type) set.add(i.subject_type);
    });
    return Array.from(set).sort().map((v) => ({ value: v, label: v }));
  }, [items]);

  const filterDefs = useMemo<DataTableFilter[]>(
    () => [
      {
        id: 'actor',
        label: 'Actor',
        type: 'enum',
        columnId: 'actor',
        options: actorOptions.length > 0 ? actorOptions : undefined,
      },
      {
        id: 'action',
        label: 'Action',
        type: 'enum',
        columnId: 'action',
        options: actionOptions.length > 0 ? actionOptions : undefined,
      },
      {
        id: 'subject',
        label: 'Subject Type',
        type: 'enum',
        columnId: 'subject',
        options: subjectOptions.length > 0 ? subjectOptions : undefined,
      },
      {
        id: 'result',
        label: 'Outcome',
        type: 'status',
        columnId: 'result',
        options: [
          { value: 'success', label: 'Success' },
          { value: 'failure', label: 'Failure' },
          { value: 'denied', label: 'Denied' },
        ],
      },
      {
        id: 'created_at',
        label: 'Date Range',
        type: 'date',
        columnId: 'created_at',
      },
    ],
    [actorOptions, actionOptions, subjectOptions]
  );

  const fetchAuditLogs = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      // Build query parameters
      const params: Record<string, string> = {
        limit: '100',
      };

      for (const f of activeFilters) {
        if (f.filterId === 'action' && f.value.type === 'enum' && f.value.values.length === 1) {
          params.action = f.value.values[0]!;
        } else if (f.filterId === 'subject' && f.value.type === 'enum' && f.value.values.length === 1) {
          params.subject_type = f.value.values[0]!;
        } else if (f.filterId === 'result' && f.value.type === 'enum' && f.value.values.length === 1) {
          params.result = f.value.values[0]!;
        } else if (f.filterId === 'created_at' && f.value.type === 'date') {
          if (f.value.from) params.from = f.value.from.toISOString();
          if (f.value.to) params.to = f.value.to.toISOString();
        }
      }

      const queryStr = new URLSearchParams(params).toString();
      const endpoint = queryStr ? `/audit?${queryStr}` : '/audit';
      const res = await apiClient.request<AuditListResponse>('GET', endpoint);
      if (res.ok) {
        setItems(res.data.items || []);
      } else {
        setError({ message: res.problem?.detail || 'Failed to fetch audit log entries.' });
      }
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to fetch audit log entries.';
      setError({ message });
    } finally {
      setLoading(false);
    }
  }, [activeFilters]);

  useEffect(() => {
    fetchAuditLogs();
  }, [fetchAuditLogs]);

  // Client-side filtering fallback for complex multi-value filters
  const filteredData = useMemo(() => {
    return items.filter((row) => {
      for (const f of activeFilters) {
        if (f.filterId === 'actor' && f.value.type === 'enum' && f.value.values.length > 0) {
          const rowActor = row.actor_label || row.actor_id;
          if (!rowActor || !f.value.values.includes(rowActor)) return false;
        }
        if (f.filterId === 'action' && f.value.type === 'enum' && f.value.values.length > 0) {
          if (!f.value.values.includes(row.action)) return false;
        }
        if (f.filterId === 'subject' && f.value.type === 'enum' && f.value.values.length > 0) {
          if (!f.value.values.includes(row.subject_type)) return false;
        }
        if (f.filterId === 'result' && f.value.type === 'enum' && f.value.values.length > 0) {
          if (!f.value.values.includes(row.result)) return false;
        }
        if (f.filterId === 'created_at' && f.value.type === 'date') {
          const rowDate = parseApiUtcDate(row.created_at);
          if (rowDate) {
            if (f.value.from && rowDate < f.value.from) return false;
            if (f.value.to && rowDate > f.value.to) return false;
          }
        }
      }
      return true;
    });
  }, [items, activeFilters]);

  const handleOpenRow = (entry: AuditEntry) => {
    setSelectedEntry(entry);
    setDetailOpen(true);
  };

  const columns = useMemo<Array<DataTableColumn<AuditEntry>>>(
    () => [
      {
        id: 'id',
        header: 'ID',
        fieldType: 'number',
        accessor: (row) => row.id,
        sortable: true,
        width: 70,
        cell: (row) => <span className="font-mono text-11 text-muted-foreground">#{row.id}</span>,
      },
      {
        id: 'created_at',
        header: 'Timestamp',
        fieldType: 'datetime',
        accessor: (row) => parseApiUtcDate(row.created_at),
        sortable: true,
        width: 160,
        cell: (row) => (
          <span className="font-mono text-11 text-foreground" title={row.created_at}>
            {formatAdminDate(row.created_at, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
            })}
          </span>
        ),
      },
      {
        id: 'action',
        header: 'Action',
        fieldType: 'text',
        accessor: (row) => row.action,
        searchable: true,
        sortable: true,
        width: 140,
        cell: (row) => (
          <span className="font-mono text-12 font-medium text-foreground">
            {row.action}
          </span>
        ),
      },
      {
        id: 'actor',
        header: 'Actor',
        fieldType: 'user',
        accessor: (row) => row.actor_label || row.actor_id || 'System',
        searchable: true,
        sortable: true,
        width: 170,
        cell: (row) => (
          <div className="flex items-center gap-1.5 truncate">
            <span className="truncate font-medium text-foreground text-12">
              {row.actor_label || row.actor_id || 'System'}
            </span>
            <Badge variant="outline" className="text-[10px] uppercase font-mono px-1 py-0 h-4">
              {row.actor_type}
            </Badge>
          </div>
        ),
      },
      {
        id: 'subject',
        header: 'Subject',
        fieldType: 'text',
        accessor: (row) => `${row.subject_type}${row.collection_key ? `/${row.collection_key}` : ''}`,
        searchable: true,
        sortable: true,
        width: 140,
        cell: (row) => (
          <span className="text-12 text-muted-foreground truncate">
            {row.subject_type}
            {row.collection_key ? ` (${row.collection_key})` : ''}
          </span>
        ),
      },
      {
        id: 'summary',
        header: 'Summary',
        fieldType: 'text',
        accessor: (row) => row.summary || '—',
        searchable: true,
        cell: (row) => (
          <span className="truncate text-12 text-foreground" title={row.summary || undefined}>
            {row.summary || '—'}
          </span>
        ),
      },
      {
        id: 'result',
        header: 'Outcome',
        fieldType: 'status',
        accessor: (row) => row.result,
        sortable: true,
        width: 100,
        cell: (row) => {
          const isSuccess = row.result === 'success';
          return (
            <Badge
              variant={isSuccess ? 'success' : 'destructive'}
              className="text-[11px] font-mono uppercase px-1.5 py-0 h-5"
            >
              {isSuccess ? <CheckCircle className="mr-1 h-3 w-3" /> : <XCircle className="mr-1 h-3 w-3" />}
              {row.result}
            </Badge>
          );
        },
      },
      {
        id: 'diff',
        header: 'Payload',
        fieldType: 'text',
        accessor: (row) => (row.diff ? 'Has Diff' : 'No Diff'),
        width: 90,
        cell: (row) =>
          row.diff ? (
            <Badge variant="secondary" className="font-mono text-[10px] px-1 py-0 h-4">
              DIFF
            </Badge>
          ) : (
            <span className="text-11 text-muted-foreground">—</span>
          ),
      },
    ],
    []
  );

  const breadcrumbs = [
    { label: 'Dashboard', href: '/admin/' },
    { label: 'System', href: '/admin/system/audit' },
    { label: 'Audit Log' },
  ];

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs items={breadcrumbs} />

      <PageHeader
        title="Audit Log"
        description="Immutable, append-only record of administrative, content, authorization, and AI mutations."
      />

      <DataTable<AuditEntry>
        aria-label="Audit Log Entries Table"
        columns={columns}
        data={filteredData}
        getRowId={(row) => String(row.id)}
        loading={loading}
        error={error}
        onRetry={fetchAuditLogs}
        onRefresh={fetchAuditLogs}
        onRowOpen={handleOpenRow}
        state={tableState}
        onStateChange={setTableState}
        filters={{
          defs: filterDefs,
          active: activeFilters,
          onChange: setActiveFilters,
        }}
        rowMenu={(row) => [
          {
            id: 'view-details',
            label: 'View details & diff',
            onSelect: () => handleOpenRow(row),
          },
        ]}
        emptyState={{
          title: 'No audit records found',
          description: 'No mutations have been recorded matching the active filters.',
          action: (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setActiveFilters([])}
            >
              Reset Filters
            </Button>
          ),
        }}
        exportFileName="audit-log"
        storageKey="bs-audit-table"
      />

      <AuditDetailSheet
        open={detailOpen}
        onOpenChange={setDetailOpen}
        entry={selectedEntry}
      />
    </PageContainer>
  );
}
