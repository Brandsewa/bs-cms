import type { HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

/**
 * Keyboard shortcut chip, for tooltips, menus and the shortcut cheat sheet.
 */
function Kbd({ className, ...props }: HTMLAttributes<HTMLElement>) {
  return (
    <kbd
      className={cn(
        'inline-flex h-5 min-w-5 select-none items-center justify-center gap-0.5 rounded-sm border border-border bg-surface-200 px-1 font-sans text-[11px] font-medium text-foreground',
        className
      )}
      {...props}
    />
  );
}

export { Kbd };
