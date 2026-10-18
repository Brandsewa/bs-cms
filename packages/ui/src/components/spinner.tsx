import { Loader2 } from 'lucide-react';
import type { SVGProps } from 'react';
import { cn } from '../lib/utils';

export type SpinnerProps = SVGProps<SVGSVGElement> & { size?: number };

/**
 * Spinner for button-level and inline pending states only. Page and table
 * loading use skeletons that match the final layout (docs/02-ui-spec.md 1).
 */
function Spinner({ className, size = 16, role, ...props }: SpinnerProps) {
  return (
    <Loader2
      width={size}
      height={size}
      role={role ?? 'status'}
      aria-label={role === undefined ? 'Loading' : undefined}
      className={cn('animate-spin text-current', className)}
      {...props}
    />
  );
}

export { Spinner };
