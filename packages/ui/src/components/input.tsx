import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '../lib/utils';

export type InputProps = InputHTMLAttributes<HTMLInputElement>;

/**
 * Text input primitive. Native date/time input types are forbidden by lint —
 * use the @bs/ui date pickers (docs/02-ui-spec.md 3.6).
 */
const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, type, ...props },
  ref
) {
  return (
    <input
      ref={ref}
      type={type ?? 'text'}
      className={cn(
        'flex h-8 w-full rounded-md border border-input bg-transparent px-2.5 py-1 text-13 text-foreground shadow-xs transition-colors',
        'placeholder:text-muted-foreground',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'read-only:bg-surface-100',
        'aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive',
        className
      )}
      {...props}
    />
  );
});

export { Input };
