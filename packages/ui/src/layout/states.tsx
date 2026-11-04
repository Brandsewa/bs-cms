import { Link2, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Skeleton } from '../components/skeleton';
import { copyToClipboard } from '../lib/utils';

/**
 * State fragments shared by every screen (docs/02-ui-spec.md 1: loading,
 * empty, error, permission denied, not found). Screens must never render a
 * blank page: each state has an icon, one-line explanation and the primary
 * action. Loading states use the shared spinner (skeletons were removed
 * from loading surfaces in 2026-11 — one loader, everywhere).
 */

export interface EmptyStateProps {
  /** Decorative icon; aria-hidden. */
  icon?: ReactNode;
  title: string;
  description?: string;
  /** Primary action button (e.g. "New post", "Clear filters"). */
  action?: ReactNode;
  children?: ReactNode;
  className?: string;
}

export function EmptyState({ icon, title, description, action, children, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 rounded-md border border-dashed border-border bg-card shadow-xs px-6 py-10 text-center',
        className
      )}
    >
      {icon ? (
        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-200 text-foreground-lighter [&_svg]:h-5 [&_svg]:w-5" aria-hidden="true">
          {icon}
        </div>
      ) : null}
      <div className="space-y-1">
        <p className="text-13 font-semibold text-foreground">{title}</p>
        {description ? (
          <p className="max-w-sm text-13 leading-normal text-foreground-lighter">{description}</p>
        ) : null}
      </div>
      {action ? <div className="mt-2">{action}</div> : null}
      {children}
    </div>
  );
}

export interface ErrorStateProps {
  title?: string;
  /** Human-readable failure message (e.g. the problem detail). */
  message?: string;
  /** Server correlation id (RFC 9457 request_id) shown for support. */
  requestId?: string;
  onRetry?: () => void;
  className?: string;
}

export function ErrorState({
  title,
  message,
  requestId,
  onRetry,
  className,
}: ErrorStateProps) {
  const { t } = useI18n();
  return (
    <div
      role="alert"
      className={cn(
        'flex flex-col items-center justify-center gap-3 rounded-lg border border-destructive/40 bg-destructive/5 px-6 py-12 text-center',
        className
      )}
    >
      <div className="text-destructive-text [&_svg]:h-8 [&_svg]:w-8" aria-hidden="true">
        <RotateCcw />
      </div>
      <p className="text-sm font-medium text-foreground">{title ?? t('states.errorTitle')}</p>
      {message ? <p className="max-w-md text-13 text-muted-foreground">{message}</p> : null}
      {onRetry ? (
        <Button size="sm" onClick={onRetry}>
          {t('states.retry')}
        </Button>
      ) : null}
      {requestId ? (
        <p className="flex items-center gap-1 text-xs text-muted-foreground">
          {t('states.requestId')}
          <code className="flex items-center gap-1 rounded-sm bg-surface-200 px-1 py-0.5 font-mono">
            {requestId}
            <button
              type="button"
              aria-label="Copy request id"
              onClick={() => void copyToClipboard(requestId)}
              className="cursor-pointer rounded-sm px-0.5 hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <Link2 className="h-3 w-3" aria-hidden="true" />
            </button>
          </code>
        </p>
      ) : null}
    </div>
  );
}

export interface NotFoundStateProps {
  onBack?: () => void;
  onHome?: () => void;
  className?: string;
}

export function NotFoundState({ onBack, onHome, className }: NotFoundStateProps) {
  const { t } = useI18n();
  return (
    <EmptyState
      title={t('states.notFoundTitle')}
      description={t('states.notFoundBody')}
      className={className}
      action={
        <div className="flex gap-2">
          {onBack ? (
            <Button size="sm" variant="outline" onClick={onBack}>
              {t('states.back')}
            </Button>
          ) : null}
          {onHome ? (
            <Button size="sm" onClick={onHome}>
              {t('states.backHome')}
            </Button>
          ) : null}
        </div>
      }
    />
  );
}

export interface ForbiddenStateProps {
  onBack?: () => void;
  onHome?: () => void;
  className?: string;
}

export function ForbiddenState({ onBack, onHome, className }: ForbiddenStateProps) {
  const { t } = useI18n();
  return (
    <EmptyState
      title={t('states.forbiddenTitle')}
      description={t('states.forbiddenBody')}
      className={className}
      action={
        <div className="flex gap-2">
          {onBack ? (
            <Button size="sm" variant="outline" onClick={onBack}>
              {t('states.back')}
            </Button>
          ) : null}
          {onHome ? (
            <Button size="sm" onClick={onHome}>
              {t('states.backHome')}
            </Button>
          ) : null}
        </div>
      }
    />
  );
}

export interface PageLoadingSkeletonProps {
  lines?: number;
  className?: string;
}

/**
 * Structural page skeleton loader mapping real page elements (header, actions,
 * table/card surface, and animated rows) to prevent layout shifts.
 */
export function PageLoadingSkeleton({ lines = 5, className }: PageLoadingSkeletonProps) {
  return (
    <div
      role="status"
      aria-label="Loading content"
      className={cn('w-full space-y-6 p-4 sm:p-6', className)}
    >
      <span className="sr-only">Loading content…</span>
      {/* Top breadcrumb & page header skeleton */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Skeleton className="h-3 w-16 rounded" />
          <span className="text-xs text-muted-foreground/40">/</span>
          <Skeleton className="h-3 w-24 rounded" />
        </div>
        <div className="flex items-center justify-between gap-4">
          <div className="space-y-1.5 flex-1">
            <Skeleton className="h-6 w-48 rounded" />
            <Skeleton className="h-3.5 w-80 max-w-full rounded" />
          </div>
          <div className="flex items-center gap-2">
            <Skeleton className="h-8 w-24 rounded-md" />
          </div>
        </div>
      </div>

      {/* Main card skeleton */}
      <div className="rounded-lg border border-border bg-card p-5 shadow-xs space-y-4">
        {/* Table/Content header bar */}
        <div className="flex items-center justify-between border-b border-border/70 pb-3.5">
          <div className="flex items-center gap-2">
            <Skeleton className="h-7 w-20 rounded" />
            <Skeleton className="h-7 w-24 rounded" />
          </div>
          <div className="flex items-center gap-2">
            <Skeleton className="h-7 w-40 rounded" />
            <Skeleton className="h-7 w-8 rounded" />
          </div>
        </div>

        {/* Content rows */}
        <div className="space-y-3 pt-1">
          {Array.from({ length: Math.min(lines, 8) }).map((_, i) => (
            <div key={i} className="flex items-center justify-between gap-4 py-2 border-b border-border/40 last:border-0">
              <div className="flex items-center gap-3 flex-1">
                <Skeleton className="h-4 w-4 rounded-sm shrink-0" />
                <Skeleton
                  className="h-4 rounded"
                  style={{ width: `${[60, 75, 50, 80, 65, 45, 70][i % 7]}%` }}
                />
              </div>
              <div className="flex items-center gap-3 shrink-0">
                <Skeleton className="h-5 w-16 rounded-full" />
                <Skeleton className="h-4 w-20 rounded" />
                <Skeleton className="h-4 w-4 rounded-sm" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
