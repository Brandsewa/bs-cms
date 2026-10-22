import { FC, useState, useEffect, useMemo } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  DataTable,
  type DataTableColumn,
  type DataTableFilter,
  type DataTableState,
  type DataTableBulkAction,
  type BulkActionOutcome,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  PageContainer,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@bs/ui';
import {
  ArrowRight,
  Download,
  Plus,
  Trash2,
  Upload,
  AlertTriangle,
  RotateCcw,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/datetime';
import { problemToast } from '../lib/problem-toast';

export interface RedirectItem {
  id: string;
  from_path: string;
  to_path: string;
  status_code: number;
  match_type: 'exact' | 'prefix';
  is_auto: boolean;
  hits: number;
  last_hit_at: string | null;
  created_by: string | null;
  created_at: string;
}

interface RedirectsListResponse {
  items: RedirectItem[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
}

export const RedirectsView: FC = () => {
  const [items, setItems] = useState<RedirectItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);

  // DataTable state
  const [tableState, setTableState] = useState<DataTableState>({
    pagination: { pageIndex: 0, pageSize: 50 },
    sorting: [{ id: 'created_at', desc: true }],
    density: 'compact',
    search: '',
  });

  // Dialog states
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<RedirectItem | null>(null);
  const [dialogLoading, setDialogLoading] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [formValues, setFormValues] = useState({
    from_path: '',
    to_path: '',
    status_code: 301,
    match_type: 'exact' as 'exact' | 'prefix',
  });

  // CSV Import Dialog
  const [importOpen, setImportOpen] = useState(false);
  const [importCsvText, setImportCsvText] = useState('');
  const [importResult, setImportResult] = useState<{
    total_processed?: number;
    created?: number;
    updated?: number;
    failed?: number;
    errors?: Array<{ row: number; error: string }>;
  } | null>(null);
  const [importLoading, setImportLoading] = useState(false);

  // Load redirects
  const loadRedirects = async () => {
    setLoading(true);
    try {
      const pageIndex = tableState.pagination?.pageIndex ?? 0;
      const pageSize = tableState.pagination?.pageSize ?? 50;
      const queryParams: Record<string, string | number> = {
        page: pageIndex + 1,
        per_page: pageSize,
      };

      if (tableState.search) {
        queryParams.search = tableState.search;
      }

      const res = await apiClient.request<RedirectsListResponse>('GET', '/redirects', {
        query: queryParams,
      });

      if (res.ok) {
        setItems(res.data.items || []);
        setTotal(res.data.total || 0);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load redirects' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error connecting to redirects service' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadRedirects();
  }, [
    tableState.pagination?.pageIndex,
    tableState.pagination?.pageSize,
    tableState.search,
  ]);

  // Loop & Chain Detection heuristics
  const { loops, chains } = useMemo(() => {
    const loopSet = new Set<string>();
    const chainSet = new Set<string>();
    const fromMap = new Map<string, string>();

    items.forEach((item) => {
      fromMap.set(item.from_path, item.to_path);
    });

    items.forEach((item) => {
      if (item.from_path === item.to_path) {
        loopSet.add(item.id);
      } else if (fromMap.get(item.to_path) === item.from_path) {
        loopSet.add(item.id);
      } else if (fromMap.has(item.to_path)) {
        chainSet.add(item.id);
      }
    });

    return { loops: loopSet, chains: chainSet };
  }, [items]);

  // Handle Create or Edit submission
  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setDialogLoading(true);
    setFormErrors({});

    try {
      if (editingItem) {
        const res = await apiClient.request<RedirectItem>('PATCH', `/redirects/${editingItem.id}`, {
          body: formValues,
        });
        if (res.ok) {
          setDialogOpen(false);
          loadRedirects();
        } else {
          if (res.problem?.errors) {
            setFormErrors(res.problem.errors);
          } else {
            problemToast(res.problem, { fallbackMessage: 'Failed to update redirect' });
          }
        }
      } else {
        const res = await apiClient.request<RedirectItem>('POST', '/redirects', {
          body: formValues,
        });
        if (res.ok) {
          setDialogOpen(false);
          loadRedirects();
        } else {
          if (res.problem?.errors) {
            setFormErrors(res.problem.errors);
          } else {
            problemToast(res.problem, { fallbackMessage: 'Failed to create redirect' });
          }
        }
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'An unexpected error occurred' });
    } finally {
      setDialogLoading(false);
    }
  };

  // Handle Single Delete
  const handleDelete = async (id: string) => {
    if (!confirm('Are you sure you want to delete this redirect rule?')) return;
    try {
      const res = await apiClient.request('DELETE', `/redirects/${id}`);
      if (res.ok) {
        loadRedirects();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to delete redirect' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error deleting redirect' });
    }
  };

  // Bulk Delete Actions for DataTable
  const bulkActions = useMemo<Array<DataTableBulkAction<RedirectItem>>>(() => [
    {
      id: 'delete-selected',
      label: 'Delete Selected',
      destructive: true,
      confirm: 'confirm',
      icon: Trash2,
      run: async (rows) => {
        const outcome: BulkActionOutcome<RedirectItem> = { succeeded: [], failed: [] };
        const ids = rows.map((r) => r.id);
        const res = await apiClient.request('POST', '/redirects/bulk', {
          body: { action: 'delete', ids },
        });

        if (res.ok) {
          outcome.succeeded = rows;
          loadRedirects();
        } else {
          rows.forEach((r) => {
            outcome.failed.push({ row: r, reason: res.problem?.detail || 'Delete failed' });
          });
        }
        return outcome;
      },
    },
  ], []);

  // CSV Export
  const handleExport = () => {
    window.open('/api/v1/redirects/export', '_blank');
  };

  // CSV Import
  const handleImportSubmit = async () => {
    if (!importCsvText.trim()) return;
    setImportLoading(true);
    setImportResult(null);

    try {
      const res = await apiClient.request<{ total_processed?: number; created?: number; updated?: number; failed?: number; errors?: Array<{ row: number; error: string }> }>('POST', '/redirects/import', {
        body: { csv: importCsvText },
      });
      if (res.ok) {
        setImportResult(res.data);
        loadRedirects();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to import CSV' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error importing CSV data' });
    } finally {
      setImportLoading(false);
    }
  };

  const columns = useMemo<Array<DataTableColumn<RedirectItem>>>(() => [
    {
      id: 'from_path',
      header: 'Source (From)',
      fieldType: 'text',
      accessor: (row) => row.from_path,
      sortable: true,
      cell: (row) => (
        <div className="flex items-center gap-2">
          <span className="font-mono text-13 font-medium text-foreground">{row.from_path}</span>
          {loops.has(row.id) && (
            <Badge variant="destructive" className="text-[10px] py-0 px-1.5 gap-1">
              <AlertTriangle className="h-3 w-3" /> Loop
            </Badge>
          )}
          {chains.has(row.id) && (
            <Badge variant="secondary" className="text-[10px] py-0 px-1.5 gap-1 text-warning">
              <RotateCcw className="h-3 w-3" /> Chain
            </Badge>
          )}
          {row.is_auto && (
            <Badge variant="outline" className="text-[10px] py-0 px-1.5 text-muted-foreground">
              Auto-slug
            </Badge>
          )}
        </div>
      ),
    },
    {
      id: 'to_path',
      header: 'Destination (To)',
      fieldType: 'text',
      accessor: (row) => row.to_path,
      sortable: true,
      cell: (row) => (
        <div className="flex items-center gap-1.5 font-mono text-13 text-muted-foreground">
          <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="text-foreground">{row.to_path}</span>
        </div>
      ),
    },
    {
      id: 'status_code',
      header: 'Status',
      fieldType: 'number',
      accessor: (row) => row.status_code,
      sortable: true,
      cell: (row) => {
        const isPerm = row.status_code === 301 || row.status_code === 308;
        return (
          <Badge variant={isPerm ? 'default' : 'secondary'} className="font-mono text-12">
            {row.status_code} {isPerm ? 'Permanent' : 'Temporary'}
          </Badge>
        );
      },
    },
    {
      id: 'match_type',
      header: 'Match',
      fieldType: 'text',
      accessor: (row) => row.match_type,
      cell: (row) => (
        <span className="capitalize text-12 text-muted-foreground">{row.match_type}</span>
      ),
    },
    {
      id: 'hits',
      header: 'Hits',
      fieldType: 'number',
      accessor: (row) => row.hits,
      sortable: true,
      cell: (row) => (
        <div className="text-12 tabular-nums">
          <span className="font-medium text-foreground">{row.hits}</span>
          {row.last_hit_at && (
            <span className="block text-[11px] text-muted-foreground">
              {formatAdminDate(row.last_hit_at, { month: 'short', day: 'numeric' })}
            </span>
          )}
        </div>
      ),
    },
    {
      id: 'actions',
      header: '',
      fieldType: 'text',
      align: 'right',
      accessor: () => '',
      cell: (row) => (
        <div className="flex items-center justify-end gap-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={(e) => {
              e.stopPropagation();
              setEditingItem(row);
              setFormValues({
                from_path: row.from_path,
                to_path: row.to_path,
                status_code: row.status_code,
                match_type: row.match_type,
              });
              setFormErrors({});
              setDialogOpen(true);
            }}
          >
            Edit
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="text-destructive hover:bg-destructive/10"
            onClick={(e) => {
              e.stopPropagation();
              handleDelete(row.id);
            }}
          >
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        </div>
      ),
    },
  ], [loops, chains]);

  const filterDefs: DataTableFilter[] = [
    {
      id: 'match_type',
      label: 'Match Type',
      type: 'enum',
      columnId: 'match_type',
      options: [
        { label: 'Exact Match', value: 'exact' },
        { label: 'Prefix Match', value: 'prefix' },
      ],
    },
    {
      id: 'status_code',
      label: 'Status Code',
      type: 'enum',
      columnId: 'status_code',
      options: [
        { label: '301 Permanent', value: '301' },
        { label: '302 Found', value: '302' },
        { label: '307 Temporary', value: '307' },
        { label: '308 Permanent', value: '308' },
      ],
    },
  ];

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Site', href: '/admin/menus' },
          { label: 'Redirects' },
        ]}
      />

      <PageHeader
        title="URL Redirects"
        description="Manage 301/302/307/308 redirects, route aliases, match patterns, and detect forwarding chains."
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={handleExport} aria-label="Export redirects as CSV">
              <Download className="mr-1.5 h-3.5 w-3.5" /> Export CSV
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setImportCsvText('');
                setImportResult(null);
                setImportOpen(true);
              }}
              aria-label="Import redirects from CSV"
            >
              <Upload className="mr-1.5 h-3.5 w-3.5" /> Import CSV
            </Button>
            <Button
              variant="default"
              size="sm"
              onClick={() => {
                setEditingItem(null);
                setFormValues({
                  from_path: '',
                  to_path: '',
                  status_code: 301,
                  match_type: 'exact',
                });
                setFormErrors({});
                setDialogOpen(true);
              }}
              data-testid="create-redirect-button"
            >
              <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Redirect
            </Button>
          </div>
        }
      />

      {loading && items.length === 0 ? (
        <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
          <Spinner size={28} />
          <p className="text-13 text-muted-foreground">Loading redirects...</p>
        </div>
      ) : (
        <DataTable<RedirectItem>
          aria-label="Redirects Table"
          columns={columns}
          data={items}
          getRowId={(r) => r.id}
          totalRows={total}
          state={tableState}
          onStateChange={setTableState}
          filters={{
            defs: filterDefs,
            active: tableState.filters || [],
            onChange: (f) => setTableState((s) => ({ ...s, filters: f })),
          }}
          bulkActions={bulkActions}
          emptyState={{
            title: 'No redirects configured',
            description: 'Add your first URL redirect rule or import from a CSV file.',
            action: (
              <Button
                size="sm"
                onClick={() => {
                  setEditingItem(null);
                  setFormValues({
                    from_path: '',
                    to_path: '',
                    status_code: 301,
                    match_type: 'exact',
                  });
                  setDialogOpen(true);
                }}
              >
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Redirect
              </Button>
            ),
          }}
        />
      )}

      {/* Create / Edit Dialog */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-lg">
          <form onSubmit={handleSave} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{editingItem ? 'Edit Redirect Rule' : 'New Redirect Rule'}</DialogTitle>
              <DialogDescription>
                Configure incoming path, target destination, HTTP status, and prefix matching.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3.5 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="from_path">From Path (Source)</Label>
                <Input
                  id="from_path"
                  placeholder="/old-page or /docs/v1"
                  value={formValues.from_path}
                  onChange={(e) => setFormValues((prev) => ({ ...prev, from_path: e.target.value }))}
                  required
                />
                {formErrors.from_path && (
                  <p className="text-12 text-destructive">{formErrors.from_path.join(' ')}</p>
                )}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="to_path">To Path or URL (Destination)</Label>
                <Input
                  id="to_path"
                  placeholder="/new-page or https://example.com"
                  value={formValues.to_path}
                  onChange={(e) => setFormValues((prev) => ({ ...prev, to_path: e.target.value }))}
                  required
                />
                {formErrors.to_path && (
                  <p className="text-12 text-destructive">{formErrors.to_path.join(' ')}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label htmlFor="status_code">HTTP Status</Label>
                  <Select
                    value={String(formValues.status_code)}
                    onValueChange={(val) => setFormValues((prev) => ({ ...prev, status_code: Number(val) }))}
                  >
                    <SelectTrigger id="status_code">
                      <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="301">301 - Moved Permanently</SelectItem>
                      <SelectItem value="302">302 - Found (Temporary)</SelectItem>
                      <SelectItem value="307">307 - Temporary Redirect</SelectItem>
                      <SelectItem value="308">308 - Permanent Redirect</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="match_type">Match Type</Label>
                  <Select
                    value={formValues.match_type}
                    onValueChange={(val: 'exact' | 'prefix') =>
                      setFormValues((prev) => ({ ...prev, match_type: val }))
                    }
                  >
                    <SelectTrigger id="match_type">
                      <SelectValue placeholder="Match type" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="exact">Exact Path Match</SelectItem>
                      <SelectItem value="prefix">Prefix Match</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={dialogLoading}>
                {editingItem ? 'Save Changes' : 'Create Redirect'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* CSV Import Dialog */}
      <Dialog open={importOpen} onOpenChange={setImportOpen}>
        <DialogContent className="max-w-xl">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Import Redirects from CSV</DialogTitle>
              <DialogDescription>
                Paste CSV data with headers: <code>from_path,to_path,status_code,match_type</code>
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-2">
              <textarea
                className="w-full h-44 rounded-md border border-border bg-background p-3 font-mono text-12 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                placeholder="from_path,to_path,status_code,match_type&#10;/old-blog,/blog,301,prefix&#10;/old-terms,/legal/terms,301,exact"
                value={importCsvText}
                onChange={(e) => setImportCsvText(e.target.value)}
              />
            </div>

            {importResult && (
              <div className="rounded-md border border-border bg-card p-3 space-y-2 text-12 font-mono">
                <div className="flex gap-4">
                  <span className="text-foreground font-semibold">
                    Processed: {importResult.total_processed ?? 0}
                  </span>
                  <span className="text-success-text">Created: {importResult.created ?? 0}</span>
                  <span className="text-foreground-light">Updated: {importResult.updated ?? 0}</span>
                  <span className="text-destructive font-semibold">Failed: {importResult.failed ?? 0}</span>
                </div>
                {importResult.errors && importResult.errors.length > 0 && (
                  <div className="max-h-28 overflow-y-auto space-y-1 text-destructive">
                    {importResult.errors.map((err, i) => (
                      <div key={i}>
                        Row {err.row}: {err.error}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            <DialogFooter>
              <Button variant="outline" onClick={() => setImportOpen(false)}>
                Close
              </Button>
              <Button loading={importLoading} onClick={handleImportSubmit} disabled={!importCsvText.trim()}>
                Import CSV
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>
    </PageContainer>
  );
};
