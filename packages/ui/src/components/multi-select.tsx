import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import type { ComboboxOption } from './combobox';

export interface MultiSelectProps {
  options: ComboboxOption[];
  value: string[];
  onChange: (value: string[]) => void;
  placeholder?: string;
  emptyMessage?: string;
  disabled?: boolean;
  /** Max visible chips before a "+N more" badge. */
  maxChips?: number;
  className?: string;
  'aria-label'?: string;
}

/**
 * Multi-value select with chips, search, select-all and clear
 * (docs/02-ui-spec.md 3.2). Used for filters, tag assignment and role
 * selection. Values stay in the order they were selected.
 */
export function MultiSelect({
  options,
  value,
  onChange,
  placeholder,
  emptyMessage,
  disabled = false,
  maxChips = 3,
  className,
  'aria-label': ariaLabel,
}: MultiSelectProps) {
  const { t } = useI18n();
  // The trigger is a real button and carries aria-expanded; chips with their
  // remove buttons sit next to it, never nested inside the button (axe:
  // no nested interactive controls).
  const [open, setOpen] = useState(false);

  const byValue = useMemo(
    () => new Map(options.map((option) => [option.value, option])),
    [options]
  );
  const selectedOptions = value
    .map((id) => byValue.get(id))
    .filter((option): option is ComboboxOption => option !== undefined);
  const visibleChips = selectedOptions.slice(0, maxChips);
  const hiddenCount = selectedOptions.length - visibleChips.length;
  const allSelected = options.length > 0 && value.length === options.length;

  const toggle = (optionValue: string) => {
    onChange(
      value.includes(optionValue)
        ? value.filter((id) => id !== optionValue)
        : [...value, optionValue]
    );
  };

  const selectAll = () => onChange(options.map((option) => option.value));
  const clearAll = () => onChange([]);

  return (
    <div className={cn('w-full', className)}>
      {selectedOptions.length > 0 ? (
        <div className="mb-1 flex flex-wrap items-center gap-1">
          {visibleChips.map((option) => (
            <span
              key={option.value}
              className="inline-flex max-w-40 items-center gap-1 rounded-sm bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground"
            >
              <span className="truncate">{option.label}</span>
              <button
                type="button"
                disabled={disabled}
                aria-label={`${t('common.remove')} ${option.label}`}
                onClick={() => toggle(option.value)}
                className="rounded-sm hover:bg-surface-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              >
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            </span>
          ))}
          {hiddenCount > 0 ? (
            <span className="text-xs text-muted-foreground">
              {t('multiSelect.moreCount', { count: hiddenCount })}
            </span>
          ) : null}
        </div>
      ) : null}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-haspopup="listbox"
            aria-expanded={open}
            aria-label={ariaLabel}
            disabled={disabled}
            className={cn(
              'flex min-h-8 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-2 py-1 text-left text-13 shadow-xs transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
              'disabled:cursor-not-allowed disabled:opacity-50'
            )}
          >
            {selectedOptions.length === 0 ? (
              <span className="text-muted-foreground">
                {placeholder ?? t('multiSelect.placeholder')}
              </span>
            ) : (
              <span className="truncate text-foreground">
                {t('common.selected', { count: selectedOptions.length })}
              </span>
            )}
            <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" aria-hidden="true" />
          </button>
        </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] p-0"
        align="start"
        aria-label={ariaLabel}
      >
        <Command>
          <CommandInput placeholder={t('combobox.searchPlaceholder')} />
          <CommandList>
            {options.length === 0 ? (
              <CommandEmpty>{emptyMessage ?? t('multiSelect.empty')}</CommandEmpty>
            ) : null}
            <CommandGroup>
              <CommandItem onSelect={allSelected ? clearAll : selectAll}>
                <Check
                  className={cn('mr-1 h-4 w-4', allSelected ? 'opacity-100' : 'opacity-0')}
                  aria-hidden="true"
                />
                {allSelected ? t('multiSelect.clearAll') : t('multiSelect.selectAll')}
              </CommandItem>
            </CommandGroup>
            <CommandGroup>
              {options.map((option) => {
                const isSelected = value.includes(option.value);
                return (
                  <CommandItem key={option.value} value={option.label} onSelect={() => toggle(option.value)}>
                    <Check
                      className={cn(
                        'mr-1 h-4 w-4 shrink-0',
                        isSelected ? 'opacity-100' : 'opacity-0'
                      )}
                      aria-hidden="true"
                    />
                    {option.label}
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
      </Popover>
    </div>
  );
}
