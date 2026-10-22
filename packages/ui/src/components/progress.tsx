import * as ProgressPrimitive from '@radix-ui/react-progress';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

export type ProgressProps = ComponentPropsWithoutRef<typeof ProgressPrimitive.Root>;

/**
 * Progress bar for bulk operations and uploads. Omit `value` for an
 * indeterminate (job running) state — used by the DataTable bulk bar.
 */
const Progress = forwardRef<ElementRef<typeof ProgressPrimitive.Root>, ProgressProps>(
  function Progress({ className, value, ...props }, ref) {
    const indeterminate = value === null || value === undefined;
    return (
      <ProgressPrimitive.Root
        ref={ref}
        value={value}
        data-indeterminate={indeterminate || undefined}
        style={{ overflow: 'hidden', ...props.style }}
        className={cn(
          'relative h-1.5 w-full overflow-hidden rounded-full bg-secondary dark:bg-muted/80 border border-transparent dark:border-border/50',
          className
        )}
        {...props}
      >
        <ProgressPrimitive.Indicator
          className={cn(
            'h-full w-full flex-1 rounded-full bg-primary transition-transform duration-[var(--duration-base)] ease-[var(--ease-out)]',
            indeterminate && 'animate-[progress-indeterminate_1.2s_ease-in-out_infinite]'
          )}
          style={indeterminate ? undefined : { transform: `translateX(-${100 - (value ?? 0)}%)` }}
        />
      </ProgressPrimitive.Root>
    );
  }
);

export { Progress };
