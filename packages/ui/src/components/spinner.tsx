import { Loader2 } from 'lucide-react';
import type { SVGProps } from 'react';
import { cn } from '../lib/utils';

export type SpinnerProps = SVGProps<SVGSVGElement> & { size?: number };

/**
 * The single loading indicator across the admin: buttons, inline pending
 * states, and page/route loading via PageLoadingSkeleton.
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
