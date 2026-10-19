import { Filter as FilterIcon, X } from 'lucide-react';
import { useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { DateRangePicker } from '../date/date-range-picker';
import { Input } from '../components/input';
import { MultiSelect } from '../components/multi-select';
import { Popover, PopoverContent, PopoverTrigger } from '../components/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/select';
import { Checkbox } from '../components/checkbox';
import type { ActiveFilter, DataTableFilter, FilterValue } from './data-table-types';

/**
 * Filter bar (docs/02-ui-spec.md 3.1): add-filter button opens a field
 * picker; per-type editors; active filters as removable chips; Clear all.
 */

interface FilterEditorProps {
  filter: DataTableFilter;
  value: FilterValue | undefined;
  onChange: (value: FilterValue) => void;
}

function FilterEditor({ filter, value, onChange }: FilterEditorProps) {
  switch (filter.type) {
    case 'text':
      return (
        <Input
          autoFocus
          aria-label={filter.label}
          placeholder={filter.label}
          value={value?.type === 'text' ? value.contains : ''}
          onChange={(event) => onChange({ type: 'text', contains: event.target.value })}
          className="w-48"
        />
      );
    case 'number':
      return (
        <div className="flex items-center gap-1">
          <Input
            aria-label={`${filter.label} minimum`}
            inputMode="numeric"
            placeholder="min"
            value={value?.type === 'number' && value.min !== undefined ? String(value.min) : ''}
            onChange={(event) => {
              const min = event.target.value === '' ? undefined : Number(event.target.value);
              onChange({ type: 'number', min, max: value?.type === 'number' ? value.max : undefined });
            }}
            className="w-20"
          />
          <span className="text-xs text-muted-foreground">–</span>
          <Input
            aria-label={`${filter.label} maximum`}
            inputMode="numeric"
            placeholder="max"
            value={value?.type === 'number' && value.max !== undefined ? String(value.max) : ''}
            onChange={(event) => {
              const max = event.target.value === '' ? undefined : Number(event.target.value);
              onChange({ type: 'number', min: value?.type === 'number' ? value.min : undefined, max });
            }}
            className="w-20"
          />
        </div>
      );
    case 'date':
      return (
        <div className="p-1">
          <DateRangePicker
            value={value?.type === 'date' ? { from: value.from, to: value.to } : undefined}
            onChange={(range) => onChange({ type: 'date', from: range?.from, to: range?.to })}
            aria-label={filter.label}
          />
        </div>
      );
    case 'enum':
    case 'status':
      return (
        <div className="p-1">
          <MultiSelect
            options={filter.options ?? []}
            value={value?.type === 'enum' ? value.values : []}
            onChange={(values) => onChange({ type: 'enum', values })}
            aria-label={filter.label}
            maxChips={99}
          />
        </div>
      );
    case 'boolean':
      return (
        <div className="p-1">
          <Select
            value={value?.type === 'boolean' ? String(value.value) : undefined}
            onValueChange={(next) => onChange({ type: 'boolean', value: next === 'true' })}
          >
            <SelectTrigger aria-label={filter.label} className="w-40">
              <SelectValue placeholder={filter.label} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="true">Yes</SelectItem>
              <SelectItem value="false">No</SelectItem>
            </SelectContent>
          </Select>
        </div>
      );
    case 'relation':
    case 'taxonomy': {
      return (
        <div className="space-y-2 p-1">
          <MultiSelect
            options={filter.options ?? []}
            value={value?.type === 'relation' ? value.ids : []}
            onChange={(ids) =>
              onChange({
                type: 'relation',
                ids,
                includeSubcategories: value?.type === 'relation' ? value.includeSubcategories : undefined,
              })
            }
            aria-label={filter.label}
            maxChips={99}
          />
          {filter.withSubcategories ? (
            <label className="flex items-center gap-2 px-1 text-13">
              <Checkbox
                checked={value?.type === 'relation' ? (value.includeSubcategories ?? false) : false}
                onCheckedChange={(checked) =>
                  onChange({
                    type: 'relation',
                    ids: value?.type === 'relation' ? value.ids : [],
                    includeSubcategories: checked === true,
                  })
                }
              />
              Include subcategories
            </label>
          ) : null}
        </div>
      );
    }
    default:
      return null;
  }
}

export function describeFilter(value: FilterValue): string {
  switch (value.type) {
    case 'text':
      return `“${value.contains}”`;
    case 'number':
      return `${value.min ?? '-∞'} – ${value.max ?? '∞'}`;
    case 'date':
      return `${value.from ? value.from.toISOString().slice(0, 10) : '…'} – ${value.to ? value.to.toISOString().slice(0, 10) : '…'}`;
    case 'enum':
      return `${value.values.length} selected`;
    case 'boolean':
      return value.value ? 'Yes' : 'No';
    case 'relation':
      return `${value.ids.length} selected${value.includeSubcategories ? ' (+subcategories)' : ''}`;
    default:
      return '';
  }
}

export interface FilterBarProps {
  filters: DataTableFilter[];
  active: ActiveFilter[];
  onChange: (filters: ActiveFilter[]) => void;
  disabled?: boolean;
  className?: string;
}

export function FilterBar({ filters, active, onChange, disabled = false, className }: FilterBarProps) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [pendingFilterId, setPendingFilterId] = useState<string | null>(
    filters[0]?.id ?? null
  );

  const pendingFilter = filters.find((filter) => filter.id === pendingFilterId) ?? filters[0];

  const setFilter = (filterId: string, value: FilterValue | null) => {
    const rest = active.filter((entry) => entry.filterId !== filterId);
    onChange(value ? [...rest, { filterId, value }] : rest);
  };

  return (
    <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button size="sm" variant="outline" disabled={disabled || filters.length === 0}>
            <FilterIcon aria-hidden="true" />
            {t('dataTable.addFilter')}
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-64 p-2" aria-label={t('dataTable.addFilter')}>
          {filters.length > 1 ? (
            <div className="mb-2 flex flex-wrap gap-1">
              {filters.map((filter) => (
                <button
                  key={filter.id}
                  type="button"
                  onClick={() => setPendingFilterId(filter.id)}
                  className={cn(
                    'rounded-md border px-2 py-0.5 text-xs transition-colors',
                    filter.id === pendingFilter?.id
                      ? 'border-primary bg-accent text-foreground'
                      : 'border-border text-muted-foreground hover:bg-surface-200'
                  )}
                >
                  {filter.label}
                </button>
              ))}
            </div>
          ) : null}
          {pendingFilter ? (
            <FilterEditor
              filter={pendingFilter}
              value={active.find((entry) => entry.filterId === pendingFilter.id)?.value}
              onChange={(value) => {
                setFilter(pendingFilter.id, value);
              }}
            />
          ) : null}
        </PopoverContent>
      </Popover>

      {active.map((entry) => {
        const filter = filters.find((candidate) => candidate.id === entry.filterId);
        if (!filter) return null;
        return (
          <span
            key={entry.filterId}
            className="inline-flex max-w-64 items-center gap-1 rounded-md border border-border bg-surface-100 px-1.5 py-0.5 text-xs text-foreground"
          >
            <span className="text-muted-foreground">{filter.label}:</span>
            <span className="truncate">{describeFilter(entry.value)}</span>
            <button
              type="button"
              aria-label={`${t('common.remove')} ${filter.label}`}
              onClick={() => setFilter(entry.filterId, null)}
              className="rounded-sm p-0.5 hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <X className="h-3 w-3" aria-hidden="true" />
            </button>
          </span>
        );
      })}

      {active.length > 0 ? (
        <Button size="sm" variant="ghost" onClick={() => onChange([])}>
          {t('dataTable.clearAll')}
        </Button>
      ) : null}
    </div>
  );
}

/**
 * Applies active filters client-side over raw accessor values. Server-side
 * filtering replaces this in server mode (M2b client hooks).
 */
export function applyFilters<T>(
  rows: T[],
  filters: DataTableFilter[],
  active: ActiveFilter[],
  accessor: (row: T, columnId: string) => unknown
): T[] {
  return rows.filter((row) =>
    active.every((entry) => {
      const filter = filters.find((candidate) => candidate.id === entry.filterId);
      if (!filter) return true;
      const raw = accessor(row, filter.columnId);
      switch (entry.value.type) {
        case 'text':
          return String(raw ?? '').toLowerCase().includes(entry.value.contains.toLowerCase());
        case 'number': {
          const number = Number(raw);
          if (Number.isNaN(number)) return false;
          if (entry.value.min !== undefined && number < entry.value.min) return false;
          if (entry.value.max !== undefined && number > entry.value.max) return false;
          return true;
        }
        case 'date': {
          const date = new Date(String(raw));
          if (Number.isNaN(date.getTime())) return false;
          const time = date.getTime();
          if (entry.value.from && time < entry.value.from.getTime()) return false;
          if (entry.value.to) {
            const endOfDay = new Date(entry.value.to);
            endOfDay.setHours(23, 59, 59, 999);
            if (time > endOfDay.getTime()) return false;
          }
          return true;
        }
        case 'enum':
          return entry.value.values.includes(String(raw ?? ''));
        case 'boolean':
          return Boolean(raw) === entry.value.value;
        case 'relation': {
          const value = entry.value;
          const ids = Array.isArray(raw)
            ? (raw as Array<ReferencedItemLike>).map((item) =>
                typeof item === 'object' && item !== null && 'id' in item ? String(item.id) : String(item)
              )
            : [String(raw ?? '')];
          if (!ids.some((id) => value.ids.includes(id))) return false;
          return true;
        }
        default:
          return true;
      }
    })
  );
}

interface ReferencedItemLike {
  id?: unknown;
}
