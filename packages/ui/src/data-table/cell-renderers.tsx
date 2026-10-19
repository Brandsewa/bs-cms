import { Check, X as XIcon } from 'lucide-react';
import { format, isValid } from 'date-fns';
import { Avatar, AvatarFallback, Badge, RelativeTime, Tooltip } from '../index';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { StatusBadge } from '../feedback/metrics';
import type { CellRendererContext, FieldType, ReferencedItem, StatusValue } from './types';

/**
 * Per-field-type cell renderers (docs/02-ui-spec.md 3.1 "Cell renderers per
 * field type"). Each returns collapsed, dense-table-appropriate output.
 */

export function TextCell({ value }: { value: unknown }) {
  const text = value === null || value === undefined ? '' : String(value);
  if (!text) return <span className="text-muted-foreground">—</span>;
  return (
    <Tooltip content={text} delayDuration={300}>
      <span tabIndex={0} className="block max-w-40 truncate">
        {text}
      </span>
    </Tooltip>
  );
}

export function StatusCell({ value }: { value: unknown }) {
  const status = value as StatusValue | string | undefined;
  if (!status) return <span className="text-muted-foreground">—</span>;
  if (typeof status === 'string') {
    return <StatusBadge status={status as StatusValue['key']} />;
  }
  return <StatusBadge status={status.key} />;
}

export function DateCell({
  value,
  context,
}: {
  value: unknown;
  context?: CellRendererContext;
}) {
  const date = value instanceof Date ? value : new Date(String(value));
  if (!isValid(date)) return <span className="text-muted-foreground">—</span>;
  return (
    <span className="tabular-nums text-13">
      {context?.renderRelative ? (
        context.renderRelative(date)
      ) : (
        <RelativeTime date={date} />
      )}
    </span>
  );
}

export function BooleanCell({ value }: { value: unknown }) {
  const { t } = useI18n();
  const truthy = value === true || value === 'true';
  return (
    <span
      role="img"
      aria-label={truthy ? `${t('status.published')}` : `${t('status.draft')}`}
      title={truthy ? 'Yes' : 'No'}
      className={cn('inline-flex', truthy ? 'text-success' : 'text-muted-foreground')}
    >
      {truthy ? <Check className="h-4 w-4" aria-hidden="true" /> : <XIcon className="h-4 w-4" aria-hidden="true" />}
    </span>
  );
}

export function NumberCell({ value }: { value: unknown }) {
  const number = typeof value === 'number' ? value : Number(value);
  if (Number.isNaN(number)) return <span className="text-muted-foreground">—</span>;
  return (
    <span className="block w-full text-right tabular-nums text-13">
      {number.toLocaleString()}
    </span>
  );
}

export function ChipsCell({
  items,
  max = 2,
}: {
  items: ReferencedItem[];
  max?: number;
}) {
  if (!items || items.length === 0) return <span className="text-muted-foreground">—</span>;
  const visible = items.slice(0, max);
  const overflow = items.length - visible.length;
  return (
    <Tooltip
      content={items.map((item) => item.label).join(', ')}
      delayDuration={200}
    >
      <span tabIndex={0} className="flex max-w-48 items-center gap-1">
        {visible.map((item) => (
          <span
            key={item.id}
            className="inline-flex max-w-24 items-center truncate rounded-sm border border-border bg-surface-200 px-1 py-0.5 text-xs text-foreground"
            style={item.colour ? { borderLeft: `3px solid ${item.colour}` } : undefined}
          >
            {item.label}
          </span>
        ))}
        {overflow > 0 ? (
          <span className="text-xs text-muted-foreground">+{overflow}</span>
        ) : null}
      </span>
    </Tooltip>
  );
}

export function MediaCell({ item }: { item?: ReferencedItem }) {
  if (!item) return <span className="text-muted-foreground">—</span>;
  return (
    <Tooltip content={item.label}>
      <span tabIndex={0} className="inline-flex">
        {item.mediaUrl ? (
          <img
            src={item.mediaUrl}
            alt={item.label}
            width={36}
            height={28}
            loading="lazy"
            className="h-7 w-9 rounded-sm border border-border object-cover"
          />
        ) : (
          <span className="flex h-7 w-9 items-center justify-center rounded-sm border border-border bg-surface-200 text-xs text-muted-foreground">
            {item.label.charAt(0).toUpperCase()}
          </span>
        )}
      </span>
    </Tooltip>
  );
}

export function UserCell({ item }: { item?: ReferencedItem }) {
  if (!item) return <span className="text-muted-foreground">—</span>;
  return (
    <span className="flex items-center gap-1.5 text-13">
      <Avatar className="h-5 w-5">
        <AvatarFallback name={item.label} />
      </Avatar>
      <span className="truncate">{item.label}</span>
    </span>
  );
}

export function UrlCell({ value }: { value: unknown }) {
  const url = typeof value === 'string' ? value : '';
  if (!url) return <span className="text-muted-foreground">—</span>;
  let display = url;
  try {
    const parsed = new URL(url);
    display = parsed.host + (parsed.pathname === '/' ? '' : parsed.pathname);
  } catch {
    // relative or invalid — display as-is
  }
  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      className="block max-w-40 truncate text-primary underline-offset-2 hover:underline"
    >
      {display}
    </a>
  );
}

export function FieldCell({
  value,
  fieldType,
  context,
}: {
  value: unknown;
  fieldType: FieldType;
  context?: CellRendererContext;
}) {
  switch (fieldType) {
    case 'status':
      return <StatusCell value={value} />;
    case 'date':
    case 'datetime':
      return <DateCell value={value} context={context} />;
    case 'boolean':
      return <BooleanCell value={value} />;
    case 'number':
      return <NumberCell value={value} />;
    case 'relation':
    case 'taxonomy':
    case 'tags':
      return <ChipsCell items={Array.isArray(value) ? (value as ReferencedItem[]) : []} />;
    case 'media':
      return <MediaCell item={value as ReferencedItem | undefined} />;
    case 'user':
      return <UserCell item={value as ReferencedItem | undefined} />;
    case 'url':
      return <UrlCell value={value} />;
    case 'text':
    default:
      return <TextCell value={value} />;
  }
}

export function formatExportValue(value: unknown): string {
  if (value instanceof Date) return format(value, 'yyyy-MM-dd HH:mm');
  if (value === null || value === undefined) return '';
  if (Array.isArray(value)) return value.map((item) => (typeof item === 'object' && item !== null && 'label' in item ? String((item as ReferencedItem).label) : String(item))).join('; ');
  if (typeof value === 'object' && 'label' in (value as object)) return String((value as ReferencedItem).label);
  return String(value);
}

export { Badge };
