import { Search } from 'lucide-react';
import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Avatar, AvatarFallback } from '../components/avatar';
import { Input } from '../components/input';
import type { PickerUser } from './relation-picker';

export interface UserPickerProps {
  users: PickerUser[];
  /** Selected user id (single-select). */
  value: string | null;
  onChange: (id: string | null) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

const SEARCH_DEBOUNCE_MS = 250;

/**
 * User picker: searchable combobox with avatar rendering and single-select
 * clear (docs/02-ui-spec.md 3.2; used for author filters, reviewer
 * assignment, permission screens).
 */
export function UserPicker({
  users,
  value,
  onChange,
  placeholder,
  disabled = false,
  className,
  'aria-label': ariaLabel,
}: UserPickerProps) {
  const { t } = useI18n();
  const inputId = useId();
  const listId = useId();
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const selected = users.find((user) => user.id === value) ?? null;

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return users;
    return users.filter((user) => user.name.toLowerCase().includes(needle));
  }, [users, query]);

  const onInputChange = (next: string) => {
    setQuery(next);
    if (next.trim().length === 0) {
      onChange(null);
      setOpen(false);
      return;
    }
    setOpen(true);
    setActiveIndex(0);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setOpen(true), SEARCH_DEBOUNCE_MS);
  };

  const pick = (user: PickerUser) => {
    onChange(user.id);
    setQuery('');
    setOpen(false);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (open && filtered.length > 0) {
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setActiveIndex((index) => Math.min(index + 1, filtered.length - 1));
        return;
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        setActiveIndex((index) => Math.max(index - 1, 0));
        return;
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        const user = filtered[activeIndex];
        if (user) pick(user);
        return;
      }
    }
    if (event.key === 'Escape') setOpen(false);
  };

  return (
    <div className={cn('relative space-y-1', className)}>
      {selected ? (
        <span className="mb-1 inline-flex items-center gap-1.5 rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground">
          <Avatar className="h-4 w-4">
            <AvatarFallback name={selected.name} />
          </Avatar>
          {selected.name}
          <button
            type="button"
            disabled={disabled}
            aria-label={`${t('common.remove')} ${selected.name}`}
            onClick={() => onChange(null)}
            className="rounded-sm px-0.5 hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          >
            ×
          </button>
        </span>
      ) : null}
      <span className="relative block">
        <Search
          className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
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
          placeholder={placeholder ?? t('pickers.userSearch')}
          onChange={(event) => onInputChange(event.target.value)}
          onKeyDown={onKeyDown}
          onFocus={() => setOpen(true)}
          className="pl-8"
        />
      </span>
      {open && filtered.length > 0 ? (
        <ul
          id={listId}
          role="listbox"
          aria-label={ariaLabel}
          className="absolute z-[var(--z-popover)] mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-border bg-popover p-1 shadow-md"
        >
          {filtered.map((user, index) => (
            <li
              key={user.id}
              role="option"
              aria-selected={user.id === value}
              onMouseEnter={() => setActiveIndex(index)}
              onMouseDown={(event) => {
                // Prevent blur-before-click race with the input's onBlur.
                event.preventDefault();
                pick(user);
              }}
              className={cn(
                'flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-13',
                index === activeIndex && 'bg-accent text-accent-foreground',
                user.id === value && 'font-medium'
              )}
            >
              <Avatar className="h-5 w-5">
                <AvatarFallback name={user.name} />
              </Avatar>
              <span className="truncate">{user.name}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
