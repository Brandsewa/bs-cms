import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Download,
  GripVertical,
  Inbox,
  MoreHorizontal,
  RefreshCw,
  Search,
  SlidersHorizontal,
  X,
} from 'lucide-react';
import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from 'react';
import { useVirtualizer } from '@tanstack/react-virtual';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Checkbox } from '../components/checkbox';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../components/dropdown-menu';
import { Input } from '../components/input';
import { Pagination } from '../layout/banner-stepper-pagination';
import { EmptyState, ErrorState } from '../layout/states';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '../components/sheet';
import { Tooltip } from '../components/tooltip';
import { FieldCell, formatExportValue } from './cell-renderers';
import { BulkBar } from './bulk-bar';
import { FilterBar, applyFilters } from './filter-bar';
import { loadPrefs, savePrefs, useMediaQuery, type DataTablePrefs } from './table-utils';
import type {
  ActiveFilter,
  CursorInfo,
  DataTableBulkAction,
  DataTableColumn,
  DataTableError,
  Density,
  RowMenuItem,
  SavedView,
  StatusTab,
} from './data-table-types';

const PAGE_SIZES = [10, 25, 50, 100];
const DENSITY_HEIGHT: Record<Density, number> = { compact: 36, default: 44, comfortable: 52 };

export type TableSorting = Array<{ id: string; desc: boolean }>;

export interface DataTableState {
  search?: string;
  sorting?: TableSorting;
  pagination?: { pageIndex: number; pageSize: number };
  filters?: ActiveFilter[];
  density?: Density;
  statusTab?: string;
  viewId?: string | null;
}

export interface DataTableProps<T> {
  columns: Array<DataTableColumn<T>>;
  data: T[];
  getRowId: (row: T) => string;
  'aria-label': string;

  loading?: boolean;
  error?: DataTableError | null;
  onRetry?: () => void;
  onRefresh?: () => void;
  lastUpdated?: Date | null;

  /** Empty state before any filtering ("No entries yet" + create button). */
  emptyState?: { title: string; description?: string; action?: ReactNode };
  /** Row target for the real link (middle-click/cmd-click open in a new tab). */
  rowHref?: (row: T) => string;
  /** Invoked on row click and Enter (screens navigate via their router). */
  onRowOpen?: (row: T) => void;
  /** Row kebab menu items (permission-aware via hidden). */
  rowMenu?: (row: T) => Array<RowMenuItem<T>>;
  /** Quick edit sheet content (title/slug/status without leaving the list). */
  quickEdit?: (row: T) => ReactNode;

  bulkActions?: Array<DataTableBulkAction<T>>;

  filters?: {
    defs: import('./data-table-types').DataTableFilter[];
    active: ActiveFilter[];
    onChange: (filters: ActiveFilter[]) => void;
  };
  statusTabs?: { tabs: StatusTab[]; active: string; onChange: (id: string) => void };
  savedViews?: {
    views: SavedView[];
    activeId: string | null;
    onSelect: (id: string | null) => void;
    onSave: (label: string) => void;
    onDelete?: (id: string) => void;
  };

  /** Manual (server) pagination: `data` is the current page. */
  manualPagination?: boolean;
  pageCount?: number;
  /** Approximate total for cursor/manual modes. */
  totalRows?: number;
  /** Cursor mode: the footer renders only next/previous. */
  cursor?: CursorInfo | null;
  onCursorChange?: (direction: 'next' | 'previous') => void;

  /** Manual ordering (collection feature manual_order). */
  manualOrder?: { onReorder: (orderedIds: string[]) => void };

  /** Persisted per user per collection (columns, order, sizes, density). */
  storageKey?: string;

  /** URL-sync adapter: controlled state + change callback (router search params). */
  state?: DataTableState;
  onStateChange?: (patch: DataTableState) => void;

  virtualizeThreshold?: number;
  exportFileName?: string;
  className?: string;
}

const EMPTY_FILTERS: ActiveFilter[] = [];

/**
 * Controlled-when-`controlled`-given, uncontrolled otherwise. Every state
 * slice change still flows to onStateChange for URL sync.
 */
function useSlice<T>(controlled: T | undefined, initial: T): [T, (next: T) => void] {
  const [internal, setInternal] = useState<T>(initial);
  const value = controlled !== undefined ? controlled : internal;
  const set = (next: T): void => {
    if (controlled === undefined) setInternal(next);
  };
  return [value, set];
}

/**
 * The one table for every list (docs/02-ui-spec.md 3.1). Client-side
 * sorting/filtering/pagination over `data`; `manualPagination` and `cursor`
 * switch the footer to server modes. Table core is implemented directly on
 * the data (sorting/filtering/pagination are thin, fully typed and fully
 * tested) — TanStack Table v9's atom-based rewrite does not fit a
 * controlled-props API and is therefore not used (NOTES-UI.md §7.8).
 */
export function DataTable<T>({
  columns,
  data,
  getRowId,
  'aria-label': ariaLabel,
  loading = false,
  error = null,
  onRetry,
  onRefresh,
  lastUpdated = null,
  emptyState,
  rowHref,
  onRowOpen,
  rowMenu,
  quickEdit,
  bulkActions = [],
  filters,
  statusTabs,
  savedViews,
  manualPagination = false,
  pageCount,
  totalRows,
  cursor = null,
  onCursorChange,
  manualOrder,
  storageKey,
  state: controlled,
  onStateChange,
  virtualizeThreshold = 200,
  exportFileName = 'export',
  className,
}: DataTableProps<T>) {
  const { t } = useI18n();
  const searchInputId = useId();
  const prefs = useMemo(() => loadPrefs(storageKey), [storageKey]);

  const search = controlled?.search ?? '';
  const [searchInput, setSearchInput] = useState(search);
  const [debouncedSearch, setDebouncedSearch] = useState(search);

  const [sorting, setSortingSlice] = useSlice<TableSorting>(controlled?.sorting, []);
  const [pagination, setPaginationSlice] = useSlice(
    controlled?.pagination,
    { pageIndex: 0, pageSize: 10 }
  );
  const [activeFilters, setActiveFiltersSlice] = useSlice<ActiveFilter[]>(
    controlled?.filters,
    EMPTY_FILTERS
  );
  const [density, setDensitySlice] = useSlice<Density>(controlled?.density, 'compact');
  const [activeStatusTab, setStatusTabSlice] = useSlice<string>(controlled?.statusTab, 'all');
  const activeViewId = controlled?.viewId ?? null;

  const applyPatch = (next: Partial<DataTableState>) => {
    // Route every slice change through onStateChange for URL sync, and keep
    // the uncontrolled slices in step.
    if (next.sorting !== undefined) setSortingSlice(next.sorting);
    if (next.pagination !== undefined) setPaginationSlice(next.pagination);
    if (next.filters !== undefined) setActiveFiltersSlice(next.filters);
    if (next.density !== undefined) setDensitySlice(next.density);
    if (next.statusTab !== undefined) setStatusTabSlice(next.statusTab);
    onStateChange?.(next);
  };
  const patch = applyPatch;

  const [columnVisibility, setColumnVisibility] = useState<Record<string, boolean>>(
    () => prefs.visibility ?? {}
  );
  const [columnOrder, setColumnOrder] = useState<string[]>(() => prefs.order ?? []);
  const [columnSizes, setColumnSizes] = useState<Record<string, number>>(() => prefs.sizes ?? {});

  const persist = (next: DataTablePrefs) => {
    savePrefs(storageKey, {
      order: next.order ?? columnOrder,
      visibility: next.visibility ?? columnVisibility,
      sizes: next.sizes ?? columnSizes,
      density: next.density ?? density,
    });
  };

  // Debounced search (250 ms, docs/02-ui-spec.md 6), cancel on retype.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchInput);
      if (searchInput !== search) patch({ search: searchInput });
    }, 250);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  useEffect(() => {
    setSearchInput(search);
  }, [search]);

  // '/' focuses the search box (docs/02-ui-spec.md 3.1).
  const searchRef = useRef<HTMLInputElement>(null);
  useEffect(() => {
    const handler = (event: globalThis.KeyboardEvent) => {
      const target = event.target as HTMLElement;
      const typing =
        ['INPUT', 'TEXTAREA'].includes(target.tagName) || target.isContentEditable;
      if (event.key === '/' && !typing) {
        event.preventDefault();
        searchRef.current?.focus();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  const isCard = useMediaQuery('(max-width: 767px)');

  // ---- status tab filter -------------------------------------------------
  const statusColumn = columns.find((column) => column.fieldType === 'status');
  const statusFiltered = useMemo(() => {
    if (manualPagination || activeStatusTab === 'all' || !statusColumn) return data;
    return data.filter((row) => {
      const value = statusColumn.accessor(row);
      const key =
        typeof value === 'object' && value !== null && 'key' in (value as object)
          ? String((value as { key: unknown }).key)
          : String(value ?? '');
      return key === activeStatusTab;
    });
  }, [data, activeStatusTab, statusColumn, manualPagination]);

  // ---- filters -----------------------------------------------------------
  const filterDefs = useMemo(() => filters?.defs ?? [], [filters]);
  const accessorFor = useCallback(
    (row: T, columnId: string): unknown => {
      const column = columns.find((candidate) => candidate.id === columnId);
      return column ? column.accessor(row) : undefined;
    },
    [columns]
  );

  const filtered = useMemo(() => {
    if (manualPagination) return statusFiltered;
    let rows = statusFiltered;
    const query = debouncedSearch.trim().toLowerCase();
    if (query) {
      rows = rows.filter((row) =>
        columns
          .filter((column) => column.searchable)
          .some((column) =>
            String(column.accessor(row) ?? '')
              .toLowerCase()
              .includes(query)
          )
      );
    }
    if (activeFilters.length > 0) {
      rows = applyFilters(rows, filterDefs, activeFilters, accessorFor);
    }
    return rows;
  }, [statusFiltered, manualPagination, debouncedSearch, activeFilters, filterDefs, accessorFor, columns]);

  // ---- sorting -----------------------------------------------------------
  const compareValues = (a: unknown, b: unknown): number => {
    if (a instanceof Date && b instanceof Date) return a.getTime() - b.getTime();
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    if (typeof a === 'boolean' && typeof b === 'boolean') return Number(a) - Number(b);
    return String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric: true });
  };

  const sorted = useMemo(() => {
    if (sorting.length === 0) return filtered;
    const rows = [...filtered];
    rows.sort((rowA, rowB) => {
      for (const sort of sorting) {
        const column = columns.find((candidate) => candidate.id === sort.id);
        if (!column) continue;
        const result = compareValues(column.accessor(rowA), column.accessor(rowB));
        if (result !== 0) return sort.desc ? -result : result;
      }
      return 0;
    });
    return rows;
  }, [filtered, sorting, columns]);

  // ---- pagination --------------------------------------------------------
  const filteredCount = manualPagination ? (totalRows ?? sorted.length) : sorted.length;
  const effectivePageCount = manualPagination
    ? (pageCount ?? 1)
    : Math.max(1, Math.ceil(sorted.length / pagination.pageSize));
  const pagedRows = manualPagination
    ? sorted
    : sorted.slice(
        pagination.pageIndex * pagination.pageSize,
        (pagination.pageIndex + 1) * pagination.pageSize
      );

  // ---- selection ---------------------------------------------------------
  const [selection, setSelection] = useState<Set<string>>(new Set());
  const [matchingAll, setMatchingAll] = useState(false);
  const anchorRef = useRef<number | null>(null);

  const clearSelection = () => {
    setSelection(new Set());
    setMatchingAll(false);
  };

  const pageRowIds = pagedRows.map((row) => getRowId(row));
  const pageSelectedCount = pageRowIds.filter((id) => selection.has(id)).length;
  const allPageSelected = pageRowIds.length > 0 && pageSelectedCount === pageRowIds.length;
  const somePageSelected = pageSelectedCount > 0 && !allPageSelected;

  const selectedRows = useMemo(() => {
    if (matchingAll) return sorted;
    return sorted.filter((row) => selection.has(getRowId(row)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [matchingAll, selection, sorted]);

  const selectedCount = matchingAll ? filteredCount : selection.size;

  const toggleRow = (rowId: string, rowIndex: number, shiftKey: boolean) => {
    const next = new Set(selection);
    if (shiftKey && anchorRef.current !== null) {
      const [from, to] = [
        Math.min(anchorRef.current, rowIndex),
        Math.max(anchorRef.current, rowIndex),
      ];
      const targetIds = pageRowIds.slice(from, to + 1);
      const anyOutside = targetIds.some((id) => !next.has(id));
      for (const id of targetIds) {
        if (anyOutside) next.add(id);
        else next.delete(id);
      }
    } else if (next.has(rowId)) {
      next.delete(rowId);
      anchorRef.current = null;
    } else {
      next.add(rowId);
      anchorRef.current = rowIndex;
    }
    setSelection(next);
    setMatchingAll(false);
  };

  const toggleAllPage = () => {
    if (allPageSelected) {
      const next = new Set(selection);
      for (const id of pageRowIds) next.delete(id);
      setSelection(next);
      setMatchingAll(false);
    } else {
      const next = new Set(selection);
      for (const id of pageRowIds) next.add(id);
      setSelection(next);
    }
  };

  // Selection clears when filters/search change (docs/02-ui-spec.md 3.1).
  const previousFilterKey = useRef('');
  const filterKey = JSON.stringify(activeFilters) + activeStatusTab + debouncedSearch;
  useEffect(() => {
    if (previousFilterKey.current === '') {
      previousFilterKey.current = filterKey;
      return;
    }
    if (previousFilterKey.current !== filterKey) {
      clearSelection();
    }
    previousFilterKey.current = filterKey;
  }, [filterKey]);

  // ---- keyboard row navigation -------------------------------------------
  const [focusedRowId, setFocusedRowId] = useState<string | null>(null);
  // ---- virtualization ----------------------------------------------------
  const scrollRef = useRef<HTMLDivElement>(null);
  const shouldVirtualize = !isCard && pagedRows.length > virtualizeThreshold;
  const rowHeight = DENSITY_HEIGHT[density];
  const virtualizer = useVirtualizer({
    count: shouldVirtualize ? pagedRows.length : 0,
    getScrollElement: () => scrollRef.current,
    estimateSize: () => rowHeight,
    overscan: 10,
  });

  // ---- manual order ------------------------------------------------------
  const dragDisabled =
    sorting.length > 0 || activeFilters.length > 0 || debouncedSearch.trim().length > 0;
  const dragRow = useRef<number | null>(null);
  const onDragStart = (index: number) => {
    if (dragDisabled) return;
    dragRow.current = index;
  };
  const onDragOver = (index: number) => {
    if (dragRow.current === null || dragRow.current === index) return;
    const ids = [...pageRowIds];
    const moved = ids.splice(dragRow.current, 1)[0];
    if (moved === undefined) return;
    ids.splice(index, 0, moved);
    dragRow.current = index;
    // Reorder live through the parent's ordered ids.
    manualOrder?.onReorder(ids);
  };
  const onDragEnd = () => {
    dragRow.current = null;
  };

  // ---- export ------------------------------------------------------------
  const exportRows = (format: 'csv' | 'json') => {
    const visibleColumns = columns.filter((column) => columnVisibility[column.id] !== false);
    let payload: string;
    if (format === 'json') {
      payload = JSON.stringify(
        sorted.map((row) =>
          Object.fromEntries(visibleColumns.map((column) => [column.id, column.accessor(row)]))
        ),
        null,
        2
      );
    } else {
      const header = visibleColumns
        .map((column) => `"${column.header.replace(/"/g, '""')}"`)
        .join(',');
      const lines = sorted.map((row) =>
        visibleColumns
          .map((column) => `"${formatExportValue(column.accessor(row)).replace(/"/g, '""')}"`)
          .join(',')
      );
      payload = [header, ...lines].join('\n');
    }
    const blob = new Blob([payload], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `${exportFileName}.${format}`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const showSelectionBanner =
    allPageSelected && !matchingAll && filteredCount > pageRowIds.length && pageRowIds.length > 0;

  // ---- header rendering --------------------------------------------------
  const resolvedOrder: string[] = useMemo(() => {
    const fixed = [...(manualOrder ? ['__drag'] : []), ...(bulkActions.length > 0 ? ['__select'] : [])];
    const dataIds = columns.map((column) => column.id);
    const stored = (prefs.order ?? columnOrder).filter((id) => dataIds.includes(id));
    const rest = dataIds.filter((id) => !stored.includes(id));
    return [...fixed, ...stored, ...rest];
  }, [columns, prefs.order, columnOrder, manualOrder, bulkActions.length]);

  const renderHeaderCell = (columnId: string) => {
    if (columnId === '__select') {
      return (
        <th scope="col" key={columnId} className="w-9 border-b border-border bg-card px-2 py-1.5">
          <Checkbox
            aria-label={
              allPageSelected || matchingAll
                ? t('dataTable.clearSelection')
                : t('dataTable.selectAllMatching', { count: filteredCount })
            }
            checked={matchingAll || allPageSelected ? true : somePageSelected ? 'indeterminate' : false}
            onCheckedChange={() => {
              if (matchingAll) setMatchingAll(false);
              else toggleAllPage();
            }}
          />
        </th>
      );
    }
    if (columnId === '__drag') {
      return (
        <th scope="col" key={columnId} className="w-7 border-b border-border bg-card px-1 py-1.5">
          <span className="sr-only">{t('dataTable.manualOrder')}</span>
        </th>
      );
    }
    const column = columns.find((candidate) => candidate.id === columnId);
    if (!column || columnVisibility[columnId] === false) return null;
    const sortable = column.sortable !== false;
    const sortEntry = sorting.find((entry) => entry.id === columnId);
    const sortIndex = sorting.findIndex((entry) => entry.id === columnId);
    const ariaSort =
      sortEntry === undefined ? undefined : sortEntry.desc ? 'descending' : 'ascending';

    return (
      <th
        scope="col"
        key={columnId}
        aria-label={column.header}
        aria-sort={ariaSort}
        style={{ width: columnSizes[columnId] ?? column.width ?? 160, minWidth: column.minWidth }}
        className={cn(
          'group relative select-none border-b border-border bg-card px-2 py-1.5 text-left align-middle',
          column.align === 'right' && 'text-right',
          column.sticky && 'sticky left-0 z-10'
        )}
      >
        {sortable ? (
          <button
            type="button"
            onClick={(event) => {
              const next: TableSorting = event.shiftKey
                ? sortEntry !== undefined
                  ? sorting.map((entry) =>
                      entry.id === columnId ? { id: columnId, desc: !entry.desc } : entry
                    )
                  : [...sorting, { id: columnId, desc: false }]
                : sortEntry === undefined
                  ? [{ id: columnId, desc: false }]
                  : sortEntry.desc
                    ? []
                    : [{ id: columnId, desc: true }];
              patch({ sorting: next });
            }}
            className={cn(
              'flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              column.align === 'right' && 'justify-end w-full'
            )}
          >
            {column.header}
            {sortEntry === undefined ? (
              <ArrowUpDown className="h-3.5 w-3.5 opacity-40" aria-hidden="true" />
            ) : sortEntry.desc ? (
              <ArrowDown className="h-3.5 w-3.5" aria-hidden="true" />
            ) : (
              <ArrowUp className="h-3.5 w-3.5" aria-hidden="true" />
            )}
            {sorting.length > 1 && sortIndex !== -1 ? (
              <span className="text-[10px] tabular-nums">{sortIndex + 1}</span>
            ) : null}
          </button>
        ) : (
          <span className="text-xs font-medium text-muted-foreground">{column.header}</span>
        )}
        {/* resize handle */}
        <span
          role="separator"
          aria-label={`Resize ${column.header}`}
          aria-orientation="vertical"
          aria-valuenow={columnSizes[columnId] ?? column.width ?? 160}
          aria-valuemin={60}
          tabIndex={0}
          onPointerDown={(event) => {
            event.preventDefault();
            const startX = event.clientX;
            const startSize = columnSizes[columnId] ?? column.width ?? 160;
            const move = (moveEvent: PointerEvent) => {
              const nextSize = Math.max(60, startSize + (moveEvent.clientX - startX));
              setColumnSizes((previous) => ({ ...previous, [columnId]: nextSize }));
            };
            const stop = () => {
              window.removeEventListener('pointermove', move);
              window.removeEventListener('pointerup', stop);
              setColumnSizes((previous) => {
                const next = { ...previous, [columnId]: previous[columnId] ?? startSize };
                persist({ sizes: next });
                return next;
              });
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', stop);
          }}
          onKeyDown={(event) => {
            const current = columnSizes[columnId] ?? column.width ?? 160;
            if (event.key === 'ArrowLeft') {
              event.preventDefault();
              const next = Math.max(60, current - 20);
              setColumnSizes((previous) => ({ ...previous, [columnId]: next }));
              persist({ sizes: { ...columnSizes, [columnId]: next } });
            } else if (event.key === 'ArrowRight') {
              event.preventDefault();
              const next = current + 20;
              setColumnSizes((previous) => ({ ...previous, [columnId]: next }));
              persist({ sizes: { ...columnSizes, [columnId]: next } });
            }
          }}
          className="absolute inset-y-0 right-0 w-1.5 cursor-col-resize touch-none bg-transparent after:absolute after:inset-y-0 after:right-0 after:w-px after:bg-transparent hover:after:bg-border-strong focus-visible:outline-none focus-visible:bg-border-strong"
        />
      </th>
    );
  };

  const renderCell = (
    row: T,
    columnId: string,
    rowIndex: number,
    rowId: string
  ): ReactNode => {
    if (columnId === '__select') {
      return (
        <td key={columnId} className="px-2" style={{ height: rowHeight }}>
          <Checkbox
            aria-label={`${titleColumnLabel(row)} — ${t('common.selected', { count: selection.has(rowId) ? 1 : 0 })}`}
            checked={selection.has(rowId)}
            onClick={(event) => {
              event.stopPropagation();
              if (event.shiftKey) {
                // Radix would toggle the single row; the range handles it.
                event.preventDefault();
                toggleRow(rowId, rowIndex, true);
              }
            }}
            onCheckedChange={() => toggleRow(rowId, rowIndex, false)}
          />
        </td>
      );
    }
    if (columnId === '__drag') {
      return (
        <td key={columnId} className="px-1" style={{ height: rowHeight }}>
          <Tooltip content={dragDisabled ? t('dataTable.dragDisabledTooltip') : t('dataTable.manualOrder')}>
            <span
              tabIndex={dragDisabled ? -1 : 0}
              role="button"
              aria-label={t('dataTable.manualOrder')}
              aria-disabled={dragDisabled}
              draggable={!dragDisabled}
              onDragStart={() => onDragStart(rowIndex)}
              onDragOver={(event) => {
                if (!dragDisabled) event.preventDefault();
                onDragOver(rowIndex);
              }}
              onDragEnd={onDragEnd}
              onDrop={onDragEnd}
              className={cn(
                'mx-auto flex h-6 w-6 items-center justify-center text-muted-foreground',
                dragDisabled ? 'cursor-not-allowed opacity-40' : 'cursor-grab'
              )}
            >
              <GripVertical className="h-4 w-4" aria-hidden="true" />
            </span>
          </Tooltip>
        </td>
      );
    }
    const column = columns.find((candidate) => candidate.id === columnId);
    if (!column || columnVisibility[columnId] === false) return null;
    return (
      <td
        key={columnId}
        style={{ height: rowHeight }}
        className={cn(
          'max-w-72 truncate border-b border-border-muted px-2 align-middle text-13 text-foreground',
          column.align === 'right' && 'text-right',
          column.sticky && 'sticky left-0 bg-card'
        )}
      >
        {column.cell ? column.cell(row) : <FieldCell value={column.accessor(row)} fieldType={column.fieldType} />}
      </td>
    );
  };

  function titleColumnLabel(row: T): string {
    const column = columns[0];
    return column ? String(column.accessor(row) ?? getRowId(row)) : getRowId(row);
  }

  const openRow = (row: T) => {
    onRowOpen?.(row);
  };

  const isEmptyData = data.length === 0;
  const isFilteredEmpty = !isEmptyData && !manualPagination && sorted.length === 0;

  const emptyBody = loading ? null : error ? (
    <ErrorState message={error.message} requestId={error.requestId} onRetry={onRetry} />
  ) : isEmptyData ? (
    <EmptyState
      icon={<Inbox />}
      title={emptyState?.title ?? t('states.emptyTitle')}
      description={emptyState?.description}
      action={emptyState?.action}
    />
  ) : isFilteredEmpty ? (
    <EmptyState
      title={t('states.noResultsTitle')}
      action={
        <Button
          size="sm"
          variant="outline"
          onClick={() => {
            patch({ filters: [], search: '' });
            setSearchInput('');
            setDebouncedSearch('');
          }}
        >
          {t('dataTable.clearFilters')}
        </Button>
      }
    />
  ) : null;

  const renderRow = (row: T, rowIndex: number, style?: CSSProperties) => {
    const rowId = getRowId(row);
    const items = (rowMenu?.(row) ?? []).filter((item) => item.hidden?.(row) !== true);
    const href = rowHref?.(row);
    return (
      <tr
        key={rowId}
        data-row-id={rowId}
        tabIndex={focusedRowId === rowId ? 0 : -1}
        onFocus={() => setFocusedRowId(rowId)}
        onKeyDown={(event) => {
          if (event.key === 'Enter' && href) {
            event.preventDefault();
            openRow(row);
            return;
          }
          if (event.key === ' ') {
            event.preventDefault();
            toggleRow(rowId, rowIndex, false);
            return;
          }
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            const delta = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : event.key === 'Home' ? -pagedRows.length : pagedRows.length;
            const next = Math.max(0, Math.min(pagedRows.length - 1, rowIndex + delta));
            const nextId = pageRowIds[next];
            setFocusedRowId(nextId ?? null);
            requestAnimationFrame(() => {
              scrollRef.current
                ?.querySelector<HTMLElement>(`[data-row-id="${nextId}"]`)
                ?.focus();
            });
          }
        }}
        onClick={(event) => {
          const target = event.target as HTMLElement;
          if (['INPUT', 'BUTTON', 'A', 'LABEL'].includes(target.tagName)) return;
          openRow(row);
        }}
        className={cn(
          'transition-colors hover:bg-surface-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
          focusedRowId === rowId && 'bg-surface-100'
        )}
        style={style}
      >
        {resolvedOrder.map((columnId) => renderCell(row, columnId, rowIndex, rowId))}
        {rowMenu ? (
          <td className="border-b border-border-muted px-1 text-right" style={{ height: rowHeight }}>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button size="icon-sm" variant="ghost" aria-label={`${t('dataTable.rowActions')}: ${titleColumnLabel(row)}`}>
                  <MoreHorizontal aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {quickEdit ? (
                  <QuickEditItem label={t('dataTable.quickEdit')} content={quickEdit(row)} title={titleColumnLabel(row)} />
                ) : null}
                {quickEdit && items.length > 0 ? <DropdownMenuSeparator /> : null}
                {items.map((item) => (
                  <DropdownMenuItem
                    key={item.id}
                    destructive={item.destructive}
                    onSelect={() => item.onSelect(row)}
                  >
                    {item.label}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </td>
        ) : null}
        {href && columns[0] ? (
          <td className="sr-only">
            <a href={href}>{`${t('dataTable.openRow', { title: titleColumnLabel(row) })}`}</a>
          </td>
        ) : null}
      </tr>
    );
  };

  const toolbar = (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <Search
          className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          id={searchInputId}
          ref={searchRef}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          placeholder={t('dataTable.searchPlaceholder')}
          aria-label={t('dataTable.searchPlaceholder')}
          className="w-56 pl-9 pr-7"
        />
        {searchInput ? (
          <button
            type="button"
            aria-label={t('common.clear')}
            onClick={() => {
              setSearchInput('');
              patch({ search: '' });
            }}
            className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          >
            <X className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        ) : null}
      </div>

      {filters ? (
        <FilterBar
          filters={filterDefs}
          active={activeFilters}
          onChange={(next) => {
            // The table's slice is the source of truth for client filtering;
            // the screen callback stays informed (persistence, server mode).
            setActiveFiltersSlice(next);
            filters.onChange(next);
          }}
        />
      ) : null}

      <div className="ml-auto flex items-center gap-1">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="ghost" aria-label={t('dataTable.columns')}>
              <SlidersHorizontal aria-hidden="true" />
              <span className="sr-only">{t('dataTable.columns')}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>{t('dataTable.columns')}</DropdownMenuLabel>
            {columns
              .filter((column) => column.enableHiding !== false)
              .map((column) => (
                <DropdownMenuCheckboxItem
                  key={column.id}
                  checked={columnVisibility[column.id] !== false}
                  onCheckedChange={(checked) => {
                    const next = { ...columnVisibility, [column.id]: checked };
                    setColumnVisibility(next);
                    persist({ visibility: next });
                  }}
                >
                  {column.header}
                </DropdownMenuCheckboxItem>
              ))}
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onClick={() => {
                setColumnVisibility({});
                setColumnOrder([]);
                setColumnSizes({});
                persist({ visibility: {}, order: [], sizes: {} });
              }}
            >
              {t('dataTable.columnsReset')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="ghost">
              <span className="text-13">{t('dataTable.density')}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {(['compact', 'default', 'comfortable'] as const).map((option) => (
              <DropdownMenuCheckboxItem
                key={option}
                checked={density === option}
                onCheckedChange={() => {
                  patch({ density: option });
                  persist({ density: option });
                }}
              >
                {t(
                  option === 'compact'
                    ? 'dataTable.densityCompact'
                    : option === 'default'
                      ? 'dataTable.densityDefault'
                      : 'dataTable.densityComfortable'
                )}
              </DropdownMenuCheckboxItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="sm" variant="ghost" aria-label={t('dataTable.export')}>
              <Download aria-hidden="true" />
              <span className="sr-only">{t('dataTable.export')}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuLabel>{t('dataTable.export')}</DropdownMenuLabel>
            <DropdownMenuItem onClick={() => exportRows('csv')}>
              {t('dataTable.exportCsv')}
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => exportRows('json')}>
              {t('dataTable.exportJson')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        {onRefresh ? (
          <Button size="sm" variant="ghost" aria-label={t('dataTable.refresh')} onClick={onRefresh}>
            <RefreshCw className={cn(loading && 'animate-spin')} aria-hidden="true" />
          </Button>
        ) : null}
      </div>
    </div>
  );

  const tabsRow =
    savedViews || statusTabs ? (
      <div className="flex flex-wrap items-center justify-between gap-2">
        {savedViews ? (
          <div role="tablist" aria-label={t('dataTable.savedViews')} className="flex flex-wrap items-center gap-1">
            <button
              type="button"
              role="tab"
              aria-selected={activeViewId === null}
              onClick={() => savedViews.onSelect(null)}
              className={cn(
                'rounded-md px-2 py-1 text-13 transition-colors',
                activeViewId === null
                  ? 'bg-accent font-medium text-foreground'
                  : 'text-muted-foreground hover:bg-surface-200'
              )}
            >
              {t('dataTable.statusAll')}
            </button>
            {savedViews.views.map((view) => (
              <span key={view.id} className="relative inline-flex">
                <button
                  type="button"
                  role="tab"
                  aria-selected={activeViewId === view.id}
                  onClick={() => savedViews.onSelect(view.id)}
                  className={cn(
                    'rounded-md px-2 py-1 text-13 transition-colors',
                    activeViewId === view.id
                      ? 'bg-accent font-medium text-foreground'
                      : 'text-muted-foreground hover:bg-surface-200'
                  )}
                >
                  {view.label}
                </button>
                {savedViews.onDelete && activeViewId === view.id ? (
                  <button
                    type="button"
                    aria-label={`${t('dataTable.deleteView')}: ${view.label}`}
                    onClick={() => savedViews.onDelete?.(view.id)}
                    className="absolute -right-1 -top-1 rounded-full border border-border bg-card p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                  >
                    <X className="h-2.5 w-2.5" aria-hidden="true" />
                  </button>
                ) : null}
              </span>
            ))}
            <Button
              size="sm"
              variant="ghost"
              onClick={() => savedViews.onSave(`${t('dataTable.saveView')} ${savedViews.views.length + 1}`)}
            >
              {t('dataTable.saveView')}
            </Button>
          </div>
        ) : (
          <span />
        )}
        {statusTabs ? (
          <div
            role="tablist"
            aria-label={t('dataTable.savedViews')}
            className="flex flex-wrap items-center gap-1"
          >
            {statusTabs.tabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeStatusTab === tab.id}
                onClick={() => statusTabs.onChange(tab.id)}
                className={cn(
                  'flex items-center gap-1 rounded-md px-2 py-1 text-13 transition-colors',
                  activeStatusTab === tab.id
                    ? 'bg-accent font-medium text-foreground'
                    : 'text-muted-foreground hover:bg-surface-200'
                )}
              >
                {tab.label}
                <span className="rounded-full bg-surface-200 px-1.5 text-xs tabular-nums text-muted-foreground">
                  {tab.count}
                </span>
              </button>
            ))}
          </div>
        ) : null}
      </div>
    ) : null;

  return (
    <div className={cn('space-y-2', className)} aria-label={ariaLabel}>
      {toolbar}
      {tabsRow}

      {showSelectionBanner ? (
        <div
          role="status"
          className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-info/40 bg-info/10 px-3 py-1.5 text-13"
        >
          <span>{t('dataTable.selectAllMatching', { count: filteredCount })}</span>
          <span className="flex gap-1">
            <Button size="sm" variant="outline" onClick={() => setMatchingAll(true)}>
              {t('dataTable.selectAllMatching', { count: filteredCount })}
            </Button>
            <Button size="sm" variant="ghost" onClick={clearSelection}>
              {t('dataTable.clearSelection')}
            </Button>
          </span>
        </div>
      ) : null}

      {selectedCount > 0 && bulkActions.length > 0 ? (
        <BulkBar
          selectedRows={selectedRows}
          selectedCount={selectedCount}
          actions={bulkActions}
          onClear={clearSelection}
        />
      ) : null}

      {emptyBody ??
        (isCard ? (
          <CardMode
            rows={pagedRows}
            getRowId={getRowId}
            columns={columns}
            selection={selection}
            onToggle={(rowId) => toggleRow(rowId, pageRowIds.indexOf(rowId), false)}
            rowHref={rowHref}
            onRowOpen={onRowOpen}
            rowMenu={rowMenu}
            selectedCount={selectedCount}
          />
        ) : (
          <div
            ref={scrollRef}
            className={cn('overflow-auto rounded-lg border border-border', shouldVirtualize && 'max-h-96')}
          >
            <table
              className="w-full border-collapse text-left"
              aria-label={ariaLabel}
              aria-rowcount={filteredCount}
            >
              <thead className="sticky top-0 z-20">
                <tr>
                  {resolvedOrder.map(renderHeaderCell)}
                  {rowMenu ? <th scope="col" className="w-10 border-b border-border bg-card"><span className="sr-only">{t('common.actions')}</span></th> : null}
                </tr>
              </thead>
              {shouldVirtualize ? (
                <tbody>
                  <tr aria-hidden="true" style={{ height: virtualizer.getTotalSize() }}>
                    <td colSpan={resolvedOrder.length + 1} style={{ padding: 0 }} />
                  </tr>
                  {virtualizer.getVirtualItems().map((virtualRow) => {
                    const row = pagedRows[virtualRow.index]!;
                    return renderRow(row, virtualRow.index, {
                      position: 'absolute',
                      top: virtualRow.start,
                      left: 0,
                      width: '100%',
                    });
                  })}
                </tbody>
              ) : (
                <tbody onDragOver={(event) => event.preventDefault()}>
                  {pagedRows.map((row, index) => renderRow(row, index))}
                  {pagedRows.length === 0 ? (
                    <tr>
                      <td colSpan={resolvedOrder.length + 1} className="p-6 text-center text-13 text-muted-foreground">
                        {t('common.noResults')}
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              )}
            </table>
          </div>
        ))}

      {loading ? (
        <div role="status" aria-label={t('dataTable.loadingRows')} className="space-y-1">
          {Array.from({ length: Math.min(pagination.pageSize, 5) }, (_, index) => (
            <div key={index} className="h-9 animate-pulse rounded-sm bg-surface-200" />
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-2 text-13 text-muted-foreground">
        <div className="flex items-center gap-3">
          <span className="tabular-nums">
            {selectedCount > 0
              ? t('dataTable.selectedOf', { selected: selectedCount, total: filteredCount })
              : t('dataTable.totalCount', { count: filteredCount })}
          </span>
          {lastUpdated ? (
            <span className="text-xs">{t('dataTable.lastUpdated', { time: lastUpdated.toLocaleTimeString() })}</span>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-1.5">
            <label htmlFor={`${ariaLabel}-page-size`}>{t('dataTable.rowsPerPage')}</label>
            <Select
              value={String(pagination.pageSize)}
              onValueChange={(next) => patch({ pagination: { pageIndex: 0, pageSize: Number(next) } })}
            >
              <SelectTrigger
                id={`${ariaLabel}-page-size`}
                aria-label={t('dataTable.rowsPerPage')}
                className="h-7 w-20"
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PAGE_SIZES.map((size) => (
                  <SelectItem key={size} value={String(size)}>
                    {size}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {cursor ? (
            <div className="flex items-center gap-1">
              <Button
                size="sm"
                variant="outline"
                disabled={!cursor.previous}
                onClick={() => onCursorChange?.('previous')}
              >
                ‹ {t('dataTable.cursorPrev')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={!cursor.next}
                onClick={() => onCursorChange?.('next')}
              >
                {t('dataTable.cursorNext')} ›
              </Button>
            </div>
          ) : (
            <Pagination
              page={pagination.pageIndex + 1}
              pageCount={effectivePageCount}
              onPageChange={(page) => patch({ pagination: { ...pagination, pageIndex: page - 1 } })}
            />
          )}
        </div>
      </div>

      <p className="sr-only">{t('dataTable.keyboardHint')}</p>
    </div>
  );
}

/**
 * Quick edit row-menu entry: opens a sheet with the screen-provided content
 * (title/slug/status without leaving the list, docs/02-ui-spec.md 3.1).
 */
function QuickEditItem({ label, content, title }: { label: string; content: ReactNode; title: string }) {
  return (
    <Sheet>
      <SheetTrigger asChild>
        <button
          type="button"
          className="relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-1.5 text-left text-13 outline-none transition-colors focus:bg-accent focus:text-accent-foreground"
        >
          {label}
        </button>
      </SheetTrigger>
      <SheetContent side="right">
        <SheetHeader>
          <SheetTitle>{`${label}: ${title}`}</SheetTitle>
        </SheetHeader>
        <div className="p-6">{content}</div>
      </SheetContent>
    </Sheet>
  );
}

function CardMode<T>({
  rows,
  getRowId,
  columns,
  selection,
  onToggle,
  rowHref,
  onRowOpen,
  rowMenu,
  selectedCount,
}: {
  rows: T[];
  getRowId: (row: T) => string;
  columns: Array<DataTableColumn<T>>;
  selection: Set<string>;
  onToggle: (rowId: string) => void;
  rowHref?: (row: T) => string;
  onRowOpen?: (row: T) => void;
  rowMenu?: (row: T) => Array<RowMenuItem<T>>;
  selectedCount: number;
}) {
  const { t } = useI18n();
  const titleColumn = columns[0];
  const secondary = columns.slice(1, 4);
  return (
    <div className="space-y-2">
      {rows.map((row) => {
        const id = getRowId(row);
        const href = rowHref?.(row);
        const items = (rowMenu?.(row) ?? []).filter((item) => item.hidden?.(row) !== true);
        return (
          <div
            key={id}
            data-row-id={id}
            className={cn(
              'flex items-start gap-3 rounded-lg border border-border bg-card p-3',
              selection.has(id) && 'border-primary bg-accent/50'
            )}
          >
            <Checkbox
              checked={selection.has(id)}
              aria-label={titleColumn ? String(titleColumn.accessor(row)) : id}
              onCheckedChange={() => onToggle(id)}
            />
            <div className="min-w-0 flex-1">
              {titleColumn && href ? (
                <a
                  href={href}
                  className="block truncate text-13 font-medium text-foreground"
                  onClick={(event) => {
                    // Plain left-click uses the screen's router; the real
                    // href keeps middle-click/new-tab working.
                    event.preventDefault();
                    onRowOpen?.(row);
                  }}
                >
                  {String(titleColumn.accessor(row))}
                </a>
              ) : titleColumn ? (
                <p className="truncate text-13 font-medium text-foreground">
                  {String(titleColumn.accessor(row))}
                </p>
              ) : null}
              <dl className="mt-1 space-y-0.5">
                {secondary.map((column) => (
                  <div key={column.id} className="flex items-center gap-2 text-xs">
                    <dt className="w-24 shrink-0 text-muted-foreground">{column.header}</dt>
                    <dd className="min-w-0 truncate">
                      <FieldCell value={column.accessor(row)} fieldType={column.fieldType} />
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
            {items.length > 0 ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button size="icon-sm" variant="ghost" aria-label={`${t('dataTable.rowActions')}: ${titleColumn ? String(titleColumn.accessor(row)) : id}`}>
                    <MoreHorizontal aria-hidden="true" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {items.map((item) => (
                    <DropdownMenuItem
                      key={item.id}
                      destructive={item.destructive}
                      onSelect={() => item.onSelect(row)}
                    >
                      {item.label}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            ) : null}
          </div>
        );
      })}
      <span className="sr-only">{t('common.selected', { count: selectedCount })}</span>
    </div>
  );
}
