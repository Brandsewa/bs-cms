import { Check, ChevronsUpDown, X } from 'lucide-react';
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from './command';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { Spinner } from './spinner';

export interface ComboboxOption {
  value: string;
  label: string;
}

export interface ComboboxProps {
  /** Static option list. Provide `onSearch` instead for async sources. */
  options?: ComboboxOption[];
  /**
   * Async search called with the debounced query (250 ms, in-flight requests
   * discarded). The combobox manages its own loading state.
   */
  onSearch?: (query: string) => Promise<ComboboxOption[]>;
  value: string | null;
  onChange: (value: string | null) => void;
  /** Offer "Create \"{query}\"" when no option matches exactly. */
  allowCreate?: boolean;
  onCreate?: (label: string) => Promise<ComboboxOption> | ComboboxOption;
  placeholder?: string;
  emptyMessage?: string;
  disabled?: boolean;
  /** Shown after the selected value when `renderSelectedLabel` is absent. */
  renderSelected?: (option: ComboboxOption) => ReactNode;
  className?: string;
  'aria-label'?: string;
}

const SEARCH_DEBOUNCE_MS = 250;

/**
 * Searchable single-value combobox (docs/02-ui-spec.md 3.2). Supports a
 * static option array or an async `onSearch` source, and inline creation of
 * missing options (used by term pickers and relation pickers).
 */
export function Combobox({
  options,
  onSearch,
  value,
  onChange,
  allowCreate = false,
  onCreate,
  placeholder,
  emptyMessage,
  disabled = false,
  renderSelected,
  className,
  'aria-label': ariaLabel,
}: ComboboxProps) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [asyncOptions, setAsyncOptions] = useState<ComboboxOption[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [creating, setCreating] = useState(false);
  const searchSequence = useRef(0);
  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const isAsync = typeof onSearch === 'function';

  // Debounced async search; out-of-order responses are discarded.
  useEffect(() => {
    if (!isAsync || !open) return;
    if (debounceTimer.current) clearTimeout(debounceTimer.current);
    const sequence = ++searchSequence.current;
    setSearching(true);
    debounceTimer.current = setTimeout(() => {
      onSearch(query)
        .then((results) => {
          if (searchSequence.current !== sequence) return;
          setAsyncOptions(results);
        })
        .catch(() => {
          if (searchSequence.current !== sequence) return;
          setAsyncOptions([]);
        })
        .finally(() => {
          if (searchSequence.current === sequence) setSearching(false);
        });
    }, SEARCH_DEBOUNCE_MS);
    return () => {
      if (debounceTimer.current) clearTimeout(debounceTimer.current);
    };
  }, [query, open, isAsync, onSearch]);

  const availableOptions = useMemo(
    () => (isAsync ? (asyncOptions ?? []) : (options ?? [])),
    [isAsync, asyncOptions, options]
  );

  const selected = useMemo(
    () => availableOptions.find((option) => option.value === value) ?? null,
    [availableOptions, value]
  );

  const exactMatch = availableOptions.some(
    (option) => option.label.toLowerCase() === query.trim().toLowerCase()
  );
  const showCreate = allowCreate && query.trim().length > 0 && !exactMatch && !!onCreate;

  const handleSelect = useCallback(
    (option: ComboboxOption) => {
      onChange(option.value);
      setOpen(false);
      setQuery('');
    },
    [onChange]
  );

  const handleCreate = useCallback(async () => {
    if (!onCreate) return;
    setCreating(true);
    try {
      const created = await onCreate(query.trim());
      handleSelect(created);
    } finally {
      setCreating(false);
    }
  }, [onCreate, query, handleSelect]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          role="combobox"
          aria-expanded={open}
          aria-label={ariaLabel}
          disabled={disabled}
          className={cn(
            'flex h-8 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-2.5 py-1 text-left text-13 shadow-xs transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className
          )}
        >
          <span className={cn('truncate', !selected && 'text-muted-foreground')}>
            {selected
              ? (renderSelected ? renderSelected(selected) : selected.label)
              : (placeholder ?? t('multiSelect.placeholder'))}
          </span>
          <span className="flex shrink-0 items-center gap-1">
            {selected ? (
              <span
                role="button"
                tabIndex={0}
                aria-label={t('combobox.clearLabel')}
                onClick={(event) => {
                  event.stopPropagation();
                  onChange(null);
                }}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.stopPropagation();
                    onChange(null);
                  }
                }}
                className="rounded-sm p-0.5 text-muted-foreground hover:bg-surface-200 hover:text-foreground"
              >
                <X className="h-3.5 w-3.5" aria-hidden="true" />
              </span>
            ) : null}
            <ChevronsUpDown className="h-4 w-4 opacity-50" aria-hidden="true" />
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] p-0"
        align="start"
        aria-label={ariaLabel}
      >
        <Command shouldFilter={!isAsync}>
          <CommandInput
            placeholder={t('combobox.searchPlaceholder')}
            value={query}
            onValueChange={setQuery}
          />
          <CommandList>
            {searching ? (
              <div className="flex items-center gap-2 px-2 py-1.5 text-13 text-muted-foreground">
                <Spinner size={14} /> {t('combobox.loading')}
              </div>
            ) : null}
            {/* cmdk shows Empty only when no items match; creation rows count
                as items, so the message disappears when a create action is
                offered. */}
            {!searching && !showCreate ? (
              <CommandEmpty>{emptyMessage ?? t('combobox.empty')}</CommandEmpty>
            ) : null}
            <CommandGroup>
              {availableOptions.map((option) => (
                <CommandItem
                  key={option.value}
                  value={isAsync ? undefined : option.label}
                  onSelect={() => handleSelect(option)}
                >
                  <Check
                    className={cn(
                      'mr-1 h-4 w-4 shrink-0',
                      option.value === value ? 'opacity-100' : 'opacity-0'
                    )}
                    aria-hidden="true"
                  />
                  {option.label}
                </CommandItem>
              ))}
            </CommandGroup>
            {showCreate ? (
              <CommandGroup>
                <CommandItem onSelect={() => void handleCreate()} disabled={creating}>
                  {creating ? <Spinner size={14} className="mr-1" /> : null}
                  {t('combobox.create', { value: query.trim() })}
                </CommandItem>
              </CommandGroup>
            ) : null}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
