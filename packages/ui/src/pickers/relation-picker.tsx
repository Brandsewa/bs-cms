import { Search } from 'lucide-react';
import { useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Input } from '../components/input';

export interface PickerUser {
  id: string;
  name: string;
}

export interface RelationPickerProps {
  /**
   * Async search over entries/users (server-side; wired to the client at
   * screen level). Receives the debounced query (250 ms).
   */
  search: (query: string) => Promise<PickerUser[]>;
  /** Selected items in selection order. */
  value: PickerUser[];
  onChange: (items: PickerUser[]) => void;
  /** Renders the item row (e.g. with entry status or user avatar). */
  renderItem?: (item: PickerUser, selected: boolean) => ReactNode;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

const SEARCH_DEBOUNCE_MS = 250;

/**
 * Relation/user picker: debounced async search with a keyboard-navigable
 * result listbox and removable chips (docs/02-ui-spec.md 3.2 combobox).
 */
export function RelationPicker({
  search,
  value,
  onChange,
  renderItem,
  placeholder,
  disabled = false,
  className,
  'aria-label': ariaLabel,
}: RelationPickerProps) {
  const { t } = useI18n();
  const inputId = useId();
  const listId = useId();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<PickerUser[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [open, setOpen] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const sequence = useRef(0);

  const runSearch = (trimmed: string) => {
    const current = ++sequence.current;
    setLoading(true);
    search(trimmed)
      .then((found) => {
        if (sequence.current !== current) return;
        setResults(found);
        setOpen(true);
        setActiveIndex(0);
      })
      .catch(() => {
        if (sequence.current === current) {
          setResults([]);
          setOpen(true);
        }
      })
      .finally(() => {
        if (sequence.current === current) setLoading(false);
      });
  };

  const onInputChange = (next: string) => {
    setQuery(next);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    if (next.trim().length === 0) {
      setResults(null);
      setOpen(false);
      return;
    }
    debounceRef.current = setTimeout(() => runSearch(next.trim()), SEARCH_DEBOUNCE_MS);
  };

  const pick = (item: PickerUser) => {
    if (value.some((existing) => existing.id === item.id)) return;
    onChange([...value, item]);
    setQuery('');
    setResults(null);
    setOpen(false);
  };

  const remove = (id: string) => onChange(value.filter((item) => item.id !== id));

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (open && results && results.length > 0) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActiveIndex((index) => Math.min(index + 1, results.length - 1));
        return;
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActiveIndex((index) => Math.max(index - 1, 0));
        return;
      }
      if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        pick(results[activeIndex]!);
        return;
      }
      if (event.key === 'Escape') {
        setOpen(false);
        return;
      }
    }
    if (event.key === 'Backspace' && query.length === 0 && value.length > 0) {
      onChange(value.slice(0, -1));
    }
  };

  const row = (item: PickerUser, selected: boolean): ReactNode =>
    renderItem ? (
      renderItem(item, selected)
    ) : (
      <>
        <span className="truncate">{item.name}</span>
        {selected ? <span className="ml-auto text-xs text-muted-foreground">{t('status.published')}</span> : null}
      </>
    );

  return (
    <div className={cn('space-y-1', className)}>
      <div className="flex flex-wrap items-center gap-1 rounded-md border border-input px-2 py-1 text-13 shadow-xs focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background">
        {value.map((item) => (
          <span
            key={item.id}
            className="inline-flex max-w-48 items-center gap-1 rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground"
          >
            <span className="truncate">{item.name}</span>
            <button
              type="button"
              disabled={disabled}
              aria-label={`${t('common.remove')} ${item.name}`}
              onClick={() => remove(item.id)}
              className="rounded-sm px-0.5 hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              ×
            </button>
          </span>
        ))}
        <span className="relative flex min-w-32 flex-1 items-center">
          <Search
            className="pointer-events-none absolute left-0 h-3.5 w-3.5 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            id={inputId}
            value={query}
            disabled={disabled}
            aria-label={ariaLabel}
            aria-expanded={open}
            aria-controls={open ? listId : undefined}
            role="combobox"
            aria-autocomplete="list"
            placeholder={placeholder ?? t('pickers.relationSearch')}
            onChange={(event) => onInputChange(event.target.value)}
            onKeyDown={onKeyDown}
            className="h-6 border-0 pl-5 shadow-none focus-visible:ring-0"
          />
        </span>
      </div>

      {open && results ? (
        <ul
          id={listId}
          role="listbox"
          aria-label={ariaLabel}
          className="max-h-48 overflow-y-auto rounded-md border border-border bg-popover p-1 shadow-md"
        >
          {results.length === 0 ? (
            <li role="option" aria-selected="false" className="px-2 py-1.5 text-13 text-muted-foreground">
              {t('common.noResults')}
            </li>
          ) : (
            results.map((item, index) => {
              const alreadySelected = value.some((existing) => existing.id === item.id);
              return (
                <li
                  key={item.id}
                  role="option"
                  aria-selected={alreadySelected}
                  aria-disabled={alreadySelected || undefined}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => {
                    if (!alreadySelected) pick(item);
                  }}
                  className={cn(
                    'flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-13',
                    index === activeIndex && 'bg-accent text-accent-foreground',
                    alreadySelected && 'opacity-50'
                  )}
                >
                  {row(item, alreadySelected)}
                </li>
              );
            })
          )}
        </ul>
      ) : null}
      {loading ? <p className="text-xs text-muted-foreground">{t('combobox.loading')}</p> : null}
    </div>
  );
}
