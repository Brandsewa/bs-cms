import { FC, ReactNode } from 'react';
import {
  Card,
  CardContent,
  CardHeader,
  PageContainer,
  Skeleton,
} from '@bs/ui';

/**
 * Structural Skeleton for Table-driven Pages
 * (Users, Roles, Taxonomies, Redirects, Background Jobs, Audit Log, Forms).
 */
export interface TablePageSkeletonProps {
  title?: string;
  description?: string;
  actionLabel?: string;
  rows?: number;
  columns?: number;
  children?: ReactNode;
}

export const TablePageSkeleton: FC<TablePageSkeletonProps> = ({
  title,
  description,
  actionLabel: _actionLabel,
  rows = 7,
  columns = 5,
}) => {
  return (
    <PageContainer
      width="full"
      className="space-y-6"
      role="status"
      aria-label={`Loading ${title || 'content'} table`}
      data-testid="table-page-skeleton"
    >
      <span className="sr-only">Loading {title || 'content'}…</span>

      {/* Breadcrumbs & Header Skeleton */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <Skeleton className="h-3 w-14 rounded bg-surface-200" />
            <span className="text-xs text-muted-foreground/40">/</span>
            <Skeleton className="h-3 w-24 rounded bg-surface-200" />
          </div>
          <div className="space-y-1">
            {title ? (
              <h1 className="text-lg font-semibold tracking-tight text-foreground">{title}</h1>
            ) : (
              <Skeleton className="h-6 w-48 rounded bg-surface-200" />
            )}
            {description ? (
              <p className="text-13 text-muted-foreground">{description}</p>
            ) : (
              <Skeleton className="h-3.5 w-72 rounded bg-surface-200" />
            )}
          </div>
        </div>

        {/* Right Action Button Skeleton */}
        <div className="flex items-center gap-2">
          <Skeleton className="h-8 w-28 rounded-md bg-surface-200" />
        </div>
      </div>

      {/* Table Card Skeleton */}
      <Card className="border-border bg-card shadow-xs overflow-hidden">
        {/* Filter / Search Bar Skeleton */}
        <div className="flex flex-wrap items-center justify-between gap-3 p-3.5 border-b border-border bg-surface-100/50">
          <div className="flex items-center gap-2">
            <Skeleton className="h-7 w-16 rounded bg-surface-200" />
            <Skeleton className="h-7 w-20 rounded bg-surface-200" />
            <Skeleton className="h-7 w-16 rounded bg-surface-200" />
          </div>
          <div className="flex items-center gap-2">
            <Skeleton className="h-7 w-48 rounded bg-surface-200" />
            <Skeleton className="h-7 w-8 rounded bg-surface-200" />
            <Skeleton className="h-7 w-8 rounded bg-surface-200" />
          </div>
        </div>

        {/* Table Rows Skeleton */}
        <div className="divide-y divide-border/60">
          {/* Header Row */}
          <div className="flex items-center gap-4 px-4 py-3 bg-surface-100">
            <Skeleton className="h-4 w-4 rounded-sm bg-surface-200 shrink-0" />
            {Array.from({ length: columns }).map((_, c) => (
              <Skeleton
                key={c}
                className="h-3.5 rounded bg-surface-200"
                style={{ width: `${[25, 20, 15, 15, 10][c % 5] || 15}%` }}
              />
            ))}
          </div>

          {/* Body Rows */}
          {Array.from({ length: rows }).map((_, r) => (
            <div key={r} className="flex items-center gap-4 px-4 py-3.5 bg-card">
              <Skeleton className="h-4 w-4 rounded-sm bg-surface-200 shrink-0" />
              {Array.from({ length: columns }).map((_, c) => (
                <Skeleton
                  key={c}
                  className="h-4 rounded bg-surface-200"
                  style={{
                    width: `${[35, 20, 12, 18, 8][(r + c) % 5] || 20}%`,
                  }}
                />
              ))}
            </div>
          ))}
        </div>

        {/* Footer Skeleton */}
        <div className="flex items-center justify-between p-3.5 border-t border-border bg-surface-100/30">
          <Skeleton className="h-4 w-32 rounded bg-surface-200" />
          <div className="flex items-center gap-2">
            <Skeleton className="h-7 w-24 rounded bg-surface-200" />
            <Skeleton className="h-7 w-16 rounded bg-surface-200" />
          </div>
        </div>
      </Card>
    </PageContainer>
  );
};

/**
 * Structural Skeleton for Settings & Form Pages
 * (General Settings, Security, Publishing, Email, Webhooks, API Tokens, Branding, My Account).
 */
export interface FormPageSkeletonProps {
  title?: string;
  description?: string;
  cardsCount?: number;
}

export const FormPageSkeleton: FC<FormPageSkeletonProps> = ({
  title,
  description,
  cardsCount = 3,
}) => {
  return (
    <PageContainer
      width="full"
      className="space-y-6"
      role="status"
      aria-label={`Loading ${title || 'settings'}`}
      data-testid="form-page-skeleton"
    >
      <span className="sr-only">Loading {title || 'settings'}…</span>

      {/* Header Skeleton */}
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <Skeleton className="h-3 w-16 rounded bg-surface-200" />
          <span className="text-xs text-muted-foreground/40">/</span>
          <Skeleton className="h-3 w-28 rounded bg-surface-200" />
        </div>
        <div className="flex items-center justify-between gap-4">
          <div className="space-y-1">
            {title ? (
              <h1 className="text-lg font-semibold tracking-tight text-foreground">{title}</h1>
            ) : (
              <Skeleton className="h-6 w-52 rounded bg-surface-200" />
            )}
            {description ? (
              <p className="text-13 text-muted-foreground">{description}</p>
            ) : (
              <Skeleton className="h-3.5 w-96 max-w-full rounded bg-surface-200" />
            )}
          </div>
          <Skeleton className="h-8 w-24 rounded-md bg-surface-200" />
        </div>
      </div>

      {/* Subnav Tabs Skeleton */}
      <div className="flex gap-4 border-b border-border pb-2">
        <Skeleton className="h-6 w-20 rounded bg-surface-200" />
        <Skeleton className="h-6 w-24 rounded bg-surface-200" />
        <Skeleton className="h-6 w-20 rounded bg-surface-200" />
        <Skeleton className="h-6 w-28 rounded bg-surface-200" />
      </div>

      {/* Form Cards Stack */}
      <div className="space-y-6 max-w-4xl">
        {Array.from({ length: cardsCount }).map((_, idx) => (
          <Card key={idx} className="border-border bg-card shadow-xs">
            <CardHeader className="p-5 pb-3 border-b border-border space-y-1.5">
              <Skeleton className="h-4 w-36 rounded bg-surface-200" />
              <Skeleton className="h-3 w-72 rounded bg-surface-200" />
            </CardHeader>
            <CardContent className="p-5 space-y-5">
              <div className="space-y-2">
                <Skeleton className="h-3 w-24 rounded bg-surface-200" />
                <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
              </div>
              <div className="space-y-2">
                <Skeleton className="h-3 w-28 rounded bg-surface-200" />
                <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
              </div>
              {idx === 0 && (
                <div className="flex items-center justify-between rounded-md border border-border bg-surface-100 p-3">
                  <div className="space-y-1">
                    <Skeleton className="h-3.5 w-32 rounded bg-surface-200" />
                    <Skeleton className="h-3 w-48 rounded bg-surface-200" />
                  </div>
                  <Skeleton className="h-5 w-9 rounded-full bg-surface-200" />
                </div>
              )}
            </CardContent>
          </Card>
        ))}
      </div>
    </PageContainer>
  );
};

/**
 * Structural Skeleton for Media Library & Pickers
 * (/admin/media & media modal).
 */
export const MediaGridSkeleton: FC<{ count?: number }> = ({ count = 12 }) => {
  return (
    <div
      role="status"
      aria-label="Loading media library"
      className="space-y-4"
      data-testid="media-grid-skeleton"
    >
      <span className="sr-only">Loading media files…</span>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
        {Array.from({ length: count }).map((_, i) => (
          <div
            key={i}
            className="flex flex-col rounded-md border border-border bg-card p-1.5 shadow-xs space-y-2"
          >
            <Skeleton className="aspect-square w-full rounded bg-surface-200" />
            <div className="px-1 space-y-1">
              <Skeleton className="h-3 w-3/4 rounded bg-surface-200" />
              <Skeleton className="h-2.5 w-1/2 rounded bg-surface-200" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

/**
 * Structural Skeleton for Tree Hierarchy Pages (Menus / Navigation).
 */
export const TreePageSkeleton: FC<{ title?: string }> = ({ title = 'Navigation Menus' }) => {
  return (
    <PageContainer
      width="full"
      className="space-y-6"
      role="status"
      aria-label={`Loading ${title}`}
      data-testid="tree-page-skeleton"
    >
      <span className="sr-only">Loading {title}…</span>
      <div className="flex items-center justify-between border-b border-border pb-4">
        <div className="space-y-1.5">
          <Skeleton className="h-3 w-20 rounded bg-surface-200" />
          <h1 className="text-lg font-semibold tracking-tight text-foreground">{title}</h1>
        </div>
        <div className="flex gap-2">
          <Skeleton className="h-8 w-24 rounded-md bg-surface-200" />
          <Skeleton className="h-8 w-28 rounded-md bg-surface-200" />
        </div>
      </div>

      <Card className="border-border bg-card shadow-xs p-5 space-y-3">
        {[0, 1, 1, 2, 0, 0].map((level, i) => (
          <div
            key={i}
            style={{ marginLeft: `${level * 24}px` }}
            className="flex items-center gap-3 rounded-md border border-border/80 p-3 bg-surface-100/50"
          >
            <Skeleton className="h-4 w-4 rounded-sm bg-surface-200 shrink-0" />
            <Skeleton className="h-4 w-32 rounded bg-surface-200" />
            <Skeleton className="h-3 w-24 rounded bg-surface-200" />
            <div className="ml-auto flex items-center gap-2">
              <Skeleton className="h-5 w-14 rounded-full bg-surface-200" />
              <Skeleton className="h-6 w-16 rounded bg-surface-200" />
            </div>
          </div>
        ))}
      </Card>
    </PageContainer>
  );
};

/**
 * Structural Skeleton for Admin Dashboard (/)
 */
export const DashboardSkeleton: FC = () => {
  return (
    <PageContainer
      width="full"
      className="space-y-6"
      role="status"
      aria-label="Loading dashboard"
      data-testid="dashboard-skeleton"
    >
      <span className="sr-only">Loading dashboard…</span>

      {/* Header */}
      <div className="space-y-1">
        <Skeleton className="h-6 w-40 rounded bg-surface-200" />
        <Skeleton className="h-3.5 w-64 rounded bg-surface-200" />
      </div>

      {/* 3 Metric Cards Row */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Card key={i} className="border-border bg-card p-3.5 shadow-xs space-y-2">
            <Skeleton className="h-3 w-28 rounded bg-surface-200" />
            <Skeleton className="h-7 w-16 rounded bg-surface-200" />
            <Skeleton className="h-3 w-36 rounded bg-surface-200" />
          </Card>
        ))}
      </div>

      {/* Main 2-Column Widgets Grid */}
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Left Column (2 cols) */}
        <div className="space-y-6 lg:col-span-2">
          <Card className="border-border bg-card shadow-xs">
            <CardHeader className="p-4 border-b border-border flex items-center justify-between">
              <Skeleton className="h-4 w-32 rounded bg-surface-200" />
              <Skeleton className="h-3.5 w-16 rounded bg-surface-200" />
            </CardHeader>
            <CardContent className="p-4 space-y-3">
              {Array.from({ length: 4 }).map((_, r) => (
                <div key={r} className="flex items-center justify-between py-2 border-b border-border/50 last:border-0">
                  <div className="space-y-1">
                    <Skeleton className="h-4 w-44 rounded bg-surface-200" />
                    <Skeleton className="h-3 w-28 rounded bg-surface-200" />
                  </div>
                  <Skeleton className="h-5 w-16 rounded-full bg-surface-200" />
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Right Column (1 col) */}
        <div className="space-y-6">
          <Card className="border-border bg-card shadow-xs p-4 space-y-3">
            <Skeleton className="h-4 w-28 rounded bg-surface-200" />
            <Skeleton className="h-20 w-full rounded bg-surface-200" />
            <Skeleton className="h-10 w-full rounded bg-surface-200" />
          </Card>
        </div>
      </div>
    </PageContainer>
  );
};
