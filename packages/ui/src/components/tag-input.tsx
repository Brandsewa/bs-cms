import { X } from 'lucide-react';
import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface TagInputProps {
  value: string[];
  onChange: (tags: string[]) => void;
  placeholder?: string;
  disabled?: boolean;
  /**
   * Validates a candidate tag; return an error message to reject it
   * (message shown inline). Defaults to accepting any non-empty value.
   */
  validate?: (tag: string) => string | null;
  'aria-label'?: string;
  id?: string;
}

/**
 * Tag input: type and press Enter to add, Backspace on an empty field removes
 * the last tag, duplicates are rejected with an inline message
 * (docs/02-ui-spec.md 3.2 "create on enter").
 */
export function TagInput({
  value,
  onChange,
  placeholder,
  disabled = false,
  validate,
  'aria-label': ariaLabel,
  id,
}: TagInputProps) {
  const { t } = useI18n();
  const inputRef = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const errorId = useId();

  const commit = () => {
    const candidate = draft.trim();
    if (candidate.length === 0) return;
    if (value.some((tag) => tag.toLowerCase() === candidate.toLowerCase())) {
      setError(t('tagInput.duplicate', { value: candidate }));
      return;
    }
    const validationError = validate?.(candidate) ?? null;
    if (validationError) {
      setError(validationError);
      return;
    }
    setError(null);
    onChange([...value, candidate]);
    setDraft('');
  };

  const remove = (tag: string) => {
    setError(null);
    onChange(value.filter((existing) => existing !== tag));
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      commit();
    } else if (event.key === 'Backspace' && draft.length === 0 && value.length > 0) {
      onChange(value.slice(0, -1));
    } else if (event.key === 'Escape') {
      setDraft('');
      setError(null);
    }
  };

  return (
    <div>
      <div
        className={cn(
          'flex min-h-8 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-13 shadow-xs',
          'focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background',
          'disabled:cursor-not-allowed disabled:opacity-50',
          error && 'border-destructive'
        )}
      >
        {value.map((tag) => (
          <span
            key={tag}
            className="inline-flex items-center gap-1 rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground"
          >
            {tag}
            <button
              type="button"
              disabled={disabled}
              aria-label={`${t('common.remove')} ${tag}`}
              onClick={() => remove(tag)}
              className="rounded-sm hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <X className="h-3 w-3" aria-hidden="true" />
            </button>
          </span>
        ))}
        <input
          ref={inputRef}
          id={id}
          type="text"
          value={draft}
          disabled={disabled}
          aria-label={ariaLabel}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          placeholder={value.length === 0 ? (placeholder ?? t('tagInput.placeholder')) : t('tagInput.placeholder')}
          onChange={(event) => {
            setDraft(event.target.value);
            if (error) setError(null);
          }}
          onKeyDown={onKeyDown}
          className="h-6 min-w-16 flex-1 bg-transparent text-13 outline-none placeholder:text-muted-foreground"
        />
      </div>
      {error ? (
        <p id={errorId} role="alert" className="mt-1 text-xs text-destructive-text">
          {error}
        </p>
      ) : null}
    </div>
  );
}
