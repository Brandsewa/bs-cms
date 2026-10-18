import type { HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

/**
 * Loading placeholder that must be sized like the content it replaces to
 * avoid layout shift (docs/02-ui-spec.md 1, 6).
 */
function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      aria-hidden="true"
      className={cn('animate-pulse rounded-md bg-surface-300', className)}
      {...props}
    />
  );
}

export { Skeleton };
