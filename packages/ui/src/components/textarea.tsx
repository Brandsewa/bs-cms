import {
  forwardRef,
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
  type TextareaHTMLAttributes,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  /**
   * Grows with the content up to maxRows, then scrolls (docs/02-ui-spec.md
   * 3.2: auto-grow textarea). Defaults to 10.
   */
  autoGrow?: boolean;
  maxRows?: number;
  /** Shows an "n of max" character counter tied to maxLength. */
  showCounter?: boolean;
}

/**
 * Textarea with auto-grow and an optional character counter. The counter is
 * announced to screen readers via aria-describedby and switches to a warning
 * colour when the limit is exceeded.
 */
const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  {
    className,
    autoGrow = true,
    maxRows = 10,
    showCounter = false,
    maxLength,
    onChange,
    value,
    defaultValue,
    ...props
  },
  ref
) {
  const innerRef = useRef<HTMLTextAreaElement | null>(null);
  const counterId = useId();
  const { t } = useI18n();

  // Controlled and uncontrolled usage both need the live length for the
  // counter, so track it from change events.
  const [length, setLength] = useState(() => {
    const initial = value ?? defaultValue;
    return typeof initial === 'string' ? initial.length : 0;
  });

  const setRefs = useCallback(
    (node: HTMLTextAreaElement | null) => {
      innerRef.current = node;
      if (typeof ref === 'function') ref(node);
      else if (ref) ref.current = node;
    },
    [ref]
  );

  const resize = useCallback(() => {
    const node = innerRef.current;
    if (!node || !autoGrow) return;
    node.style.height = 'auto';
    const lineHeight = 20;
    const maxHeight = maxRows * lineHeight;
    node.style.height = `${Math.min(node.scrollHeight, maxHeight)}px`;
    node.style.overflowY = node.scrollHeight > maxHeight ? 'auto' : 'hidden';
  }, [autoGrow, maxRows]);

  useEffect(() => {
    resize();
  }, [resize]);

  const controlledLength = typeof value === 'string' ? value.length : undefined;
  const effectiveLength = controlledLength ?? length;
  const overLimit = maxLength !== undefined && effectiveLength > maxLength;

  return (
    <div className="w-full">
      <textarea
        ref={setRefs}
        maxLength={maxLength}
        value={value}
        defaultValue={defaultValue}
        onChange={(event) => {
          setLength(event.target.value.length);
          onChange?.(event);
          resize();
        }}
        aria-describedby={
          showCounter && maxLength !== undefined
            ? counterId
            : props['aria-describedby']
        }
        className={cn(
          'flex min-h-16 w-full rounded-md border border-input bg-transparent px-2.5 py-1.5 text-13 text-foreground shadow-xs transition-colors',
          'placeholder:text-muted-foreground',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'read-only:bg-surface-100',
          'aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive',
          className
        )}
        {...props}
      />
      {showCounter && maxLength !== undefined ? (
        <p
          id={counterId}
          aria-live="polite"
          className={cn(
            'mt-1 text-right text-xs',
            overLimit ? 'text-destructive-text' : 'text-muted-foreground'
          )}
        >
          {overLimit
            ? t('form.charsOverLimit', { count: effectiveLength - maxLength, max: maxLength })
            : t('form.charsCount', { count: effectiveLength, max: maxLength })}
        </p>
      ) : null}
    </div>
  );
});

export { Textarea };
