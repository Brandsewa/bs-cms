import type { ReactNode } from 'react';
import { PageLoadingSkeleton, EmptyState, ErrorState, ForbiddenState } from './states';

export interface PageStatesProps {
  isLoading?: boolean;
  error?: string | Error | null;
  isForbidden?: boolean;
  isEmpty?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  onRetry?: () => void;
  skeletonLines?: number;
  children: ReactNode;
}

export function PageStates({
  isLoading,
  error,
  isForbidden,
  isEmpty,
  emptyTitle = 'No data available',
  emptyDescription = 'There are no items to display at this time.',
  emptyAction,
  onRetry,
  skeletonLines = 5,
  children,
}: PageStatesProps) {
  if (isLoading) {
    return <PageLoadingSkeleton lines={skeletonLines} />;
  }

  if (isForbidden) {
    return <ForbiddenState onHome={() => (window.location.href = '/admin')} />;
  }

  if (error) {
    const message = error instanceof Error ? error.message : String(error);
    return <ErrorState message={message} onRetry={onRetry} />;
  }

  if (isEmpty) {
    return (
      <EmptyState
        title={emptyTitle}
        description={emptyDescription}
        action={emptyAction}
      />
    );
  }

  return <>{children}</>;
}
