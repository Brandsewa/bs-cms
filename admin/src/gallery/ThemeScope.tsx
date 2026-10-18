import type { ReactNode } from 'react';
import { cn } from '@bs/ui';

/**
 * Renders children inside an isolated light or dark token scope. The `.dark`
 * class re-scopes every CSS variable for its subtree, so a gallery page can
 * show light and dark side by side without switching the whole document.
 */
export function ThemeScope({
  theme,
  children,
  className,
}: {
  theme: 'light' | 'dark';
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      data-theme-scope={theme}
      className={cn(
        'rounded-md border border-border bg-background p-6 text-foreground',
        theme === 'dark' && 'dark',
        className
      )}
    >
      {children}
    </div>
  );
}
