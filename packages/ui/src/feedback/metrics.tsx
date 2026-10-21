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
  icon?: ReactNode;
  className?: string;
}

/**
 * Metric card for the dashboard home (docs/02-ui-spec.md 4.2 & Supabase Studio):
 * value, label, hint, trend and optional icon.
 * Sizing is compact with neutral 1px borders and high contrast.
 */
function MetricCard({ label, value, hint, trend, loading = false, icon, className }: MetricCardProps) {
  if (loading) {
    return (
      <div
        className={cn('rounded-md border border-border bg-card p-3.5', className)}
        aria-busy="true"
      >
        <Skeleton className="h-3 w-20" />
        <Skeleton className="mt-2 h-6 w-16" />
        <Skeleton className="mt-2 h-3 w-24" />
      </div>
    );
  }
  return (
    <div className={cn('rounded-md border border-border bg-card p-3.5 shadow-xs transition-colors hover:border-border-strong', className)}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-medium text-foreground-lighter">{label}</p>
        {icon ? <div className="text-foreground-muted [&_svg]:h-4 [&_svg]:w-4">{icon}</div> : null}
      </div>
      <p className="mt-1 text-xl font-semibold tabular-nums text-foreground">{value}</p>
      {trend || hint ? (
        <p className="mt-1.5 flex items-center gap-1.5 text-xs text-foreground-muted">
          {trend ? (
            <span
              className={cn(
                'font-medium',
                trend.direction === 'up' && 'text-success-text',
                trend.direction === 'down' && 'text-destructive-text'
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
