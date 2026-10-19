import { Check, Link2, Loader2, Lock, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Input } from '../components/input';
import { Tooltip } from '../components/tooltip';

export type SlugAvailability = 'checking' | 'available' | 'taken' | 'error';

export interface SlugInputProps {
  value: string;
  onChange: (slug: string) => void;
  /** Source text (the entry title) — auto-derives the slug until edited. */
  source?: string;
  /**
   * Debounced uniqueness check against the server. Reports 'available',
   * 'taken' or 'error' (network failure — shown as a hint, not blocking).
   */
  validate?: (slug: string) => Promise<SlugAvailability>;
  /** Full URL preview prefix, e.g. "https://site.com/posts/". */
  urlPrefix?: string;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

export function slugify(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 96);
}

/**
 * Slug input (docs/02-ui-spec.md 3.2): auto-derived from the source (title)
 * until the user edits it (lock), with a debounced uniqueness check and a
 * full-URL preview.
 */
export function SlugInput({
  value,
  onChange,
  source,
  validate,
  urlPrefix,
  disabled = false,
  className,
  'aria-label': ariaLabel,
}: SlugInputProps) {
  const { t } = useI18n();
  const [locked, setLocked] = useState(true);
  const [draft, setDraft] = useState(value);
  const [availability, setAvailability] = useState<SlugAvailability | null>(null);
  const sequence = useRef(0);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hintId = useId();

  // Auto-derive while locked: slugify the source whenever it changes.
  useEffect(() => {
    if (locked && source !== undefined) {
      const auto = slugify(source);
      if (auto !== value) onChange(auto);
      setDraft(auto);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [source, locked]);

  // Debounced uniqueness check.
  useEffect(() => {
    if (!validate || !value) {
      setAvailability(null);
      return;
    }
    if (debounceRef.current) clearTimeout(debounceRef.current);
    const current = ++sequence.current;
    setAvailability('checking');
    debounceRef.current = setTimeout(() => {
      validate(value)
        .then((verdict) => {
          if (sequence.current !== current) return;
          setAvailability(verdict);
        })
        .catch(() => {
          if (sequence.current !== current) return;
          setAvailability('error');
        });
    }, 400);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [value, validate]);

  const statusIcon = () => {
    if (availability === 'checking') return <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" aria-hidden="true" />;
    if (availability === 'available') return <Check className="h-4 w-4 text-success" aria-hidden="true" />;
    if (availability === 'taken') return <X className="h-4 w-4 text-destructive-text" aria-hidden="true" />;
    return null;
  };

  const statusText = (): string | null => {
    if (availability === 'checking') return null;
    if (availability === 'taken') return t('pickers.slugTaken');
    if (availability === 'error') return t('pickers.slugCheckFailed');
    return null;
  };

  return (
    <div className={cn('space-y-1', className)}>
      <div className="flex items-center gap-1">
        <span className="relative flex min-w-0 flex-1 items-center">
          <Link2
            className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            value={locked ? value : draft}
            disabled={disabled || locked}
            aria-label={ariaLabel}
            aria-invalid={availability === 'taken' || undefined}
            aria-describedby={availability === 'taken' ? hintId : undefined}
            onChange={(event) => {
              setDraft(event.target.value);
              onChange(event.target.value);
            }}
            className="pl-8 pr-16 font-mono"
          />
          <span className="absolute right-2 flex items-center gap-1">
            {statusIcon()}
            <Tooltip content={locked ? t('pickers.slugUnlock') : t('pickers.slugLock')}>
              <button
                type="button"
                disabled={disabled}
                aria-pressed={!locked}
                aria-label={locked ? t('pickers.slugUnlock') : t('pickers.slugLock')}
                onClick={() => {
                  if (locked) setLocked(false);
                  else {
                    setLocked(true);
                    const auto = source !== undefined ? slugify(source) : draft;
                    setDraft(auto);
                    onChange(auto);
                  }
                }}
                className={cn(
                  'rounded-sm p-0.5 text-muted-foreground hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                  !locked && 'text-primary'
                )}
              >
                {locked ? <Lock className="h-3.5 w-3.5" aria-hidden="true" /> : <X className="h-3.5 w-3.5" aria-hidden="true" />}
              </button>
            </Tooltip>
          </span>
        </span>
      </div>

      {urlPrefix ? (
        <p className="truncate text-xs text-muted-foreground">
          {urlPrefix}
          <span className="font-mono text-foreground">{value}</span>
        </p>
      ) : null}
      {availability === 'taken' ? (
        <p id={hintId} role="alert" className="text-xs text-destructive-text">
          {t('pickers.slugTaken')}
        </p>
      ) : availability === 'available' ? (
        <p className="text-xs text-success">{statusText()}</p>
      ) : availability === 'error' ? (
        <p className="text-xs text-muted-foreground">{statusText()}</p>
      ) : null}
      {availability === 'error' ? (
        <p className="text-xs text-muted-foreground">{t('pickers.slugCheckFailed')}</p>
      ) : null}
    </div>
  );
}
