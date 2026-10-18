import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import type { MessageKey } from '../i18n';
import { Badge } from '../components/badge';
import { Skeleton } from '../components/skeleton';
import { Tooltip } from '../components/tooltip';

/** Entry statuses used across lists, badges and filters (docs/02-ui-spec.md 3.4). */
export type EntryStatus =
  | 'draft'
  | 'in_review'
  | 'scheduled'
  | 'published'
  | 'archived'
  | 'trashed'
  | 'ai_created';

const STATUS_VARIANT: Record<EntryStatus, 'slate' | 'warning' | 'info' | 'success' | 'secondary' | 'ai' | 'outline'> = {
  draft: 'slate',
  in_review: 'warning',
  scheduled: 'info',
  published: 'success',
  archived: 'secondary',
  trashed: 'outline',
  ai_created: 'ai',
};

const STATUS_KEY: Record<EntryStatus, MessageKey> = {
  draft: 'status.draft',
  in_review: 'status.inReview',
  scheduled: 'status.scheduled',
  published: 'status.published',
  archived: 'status.archived',
  trashed: 'status.trashed',
  ai_created: 'status.aiCreated',
};

/**
 * Status badge mapping entry statuses to the colour semantics of the spec:
 * draft grey, in review amber, scheduled blue, published green, archived
 * slate, AI-created purple.
 */
function StatusBadge({ status, className }: { status: EntryStatus; className?: string }) {
  const { t } = useI18n();
  return (
    <Badge variant={STATUS_VARIANT[status]} className={className}>
      {t(STATUS_KEY[status])}
    </Badge>
  );
}

export interface StatusDotProps {
  /** CSS colour for the dot (token reference, e.g. 'var(--success)'). */
  colour: string;
  /** The label carries the meaning; the colour is decorative. */
  label: string;
  className?: string;
}

/** Status dot with tooltip (docs/02-ui-spec.md 3.4): job, delivery, health. */
function StatusDot({ colour, label, className }: StatusDotProps) {
  return (
    <Tooltip content={label}>
      <span
        role="img"
        aria-label={label}
        tabIndex={0}
        className={cn('inline-flex h-2.5 w-2.5 rounded-full', className)}
        style={{ backgroundColor: colour }}
      />
    </Tooltip>
  );
}

export interface MetricCardProps {
  label: string;
  value: ReactNode;
  hint?: string;
  trend?: { direction: 'up' | 'down' | 'flat'; label: string };
  loading?: boolean;
  className?: string;
}

/**
 * Metric card for the dashboard home (docs/02-ui-spec.md 4.2): value, label,
 * hint and trend. Loading renders a skeleton sized like the content.
 */
function MetricCard({ label, value, hint, trend, loading = false, className }: MetricCardProps) {
  if (loading) {
    return (
      <div
        className={cn('rounded-lg border border-border bg-card p-4', className)}
        aria-busy="true"
      >
        <Skeleton className="h-3 w-20" />
        <Skeleton className="mt-2 h-7 w-16" />
        <Skeleton className="mt-2 h-3 w-24" />
      </div>
    );
  }
  return (
    <div className={cn('rounded-lg border border-border bg-card p-4', className)}>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums text-foreground">{value}</p>
      {trend || hint ? (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          {trend ? (
            <span
              className={cn(
                'font-medium',
                trend.direction === 'up' && 'text-success',
                trend.direction === 'down' && 'text-destructive'
              )}
            >
              {trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : '→'}{' '}
              {trend.label}
            </span>
          ) : null}
          {hint}
        </p>
      ) : null}
    </div>
  );
}

export { MetricCard, StatusBadge, StatusDot };
