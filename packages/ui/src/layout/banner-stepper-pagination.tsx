import { AlertTriangle, XCircle, X, Info, CheckCircle2 } from 'lucide-react';
import { Check } from 'lucide-react';
import { useId, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

/**
 * System banner area (docs/02-ui-spec.md 2): email not configured, cron not
 * running, staging indexable, PHP warnings, pending upgrades. Rendered above
 * the page container; each banner is dismissible by the screen.
 */

export type BannerVariant = 'info' | 'warning' | 'error' | 'success';

const BANNER_ICONS = {
  info: Info,
  warning: AlertTriangle,
  error: XCircle,
  success: CheckCircle2,
} as const;

export interface BannerProps {
  variant: BannerVariant;
  children: React.ReactNode;
  /** Optional inline action (e.g. "Configure email"). */
  action?: React.ReactNode;
  onDismiss?: () => void;
  className?: string;
}

export function Banner({ variant, children, action, onDismiss, className }: BannerProps) {
  const { t } = useI18n();
  const Icon = BANNER_ICONS[variant];
  return (
    <div
      role={variant === 'error' ? 'alert' : 'status'}
      className={cn(
        'flex items-start gap-2 rounded-md border px-3 py-2 text-13',
        variant === 'info' && 'border-info/30 bg-info/10',
        variant === 'warning' && 'border-warning/40 bg-warning/10',
        variant === 'error' && 'border-destructive/40 bg-destructive/10',
        variant === 'success' && 'border-success/30 bg-success/10',
        className
      )}
    >
      <Icon
        aria-hidden="true"
        className={cn(
          'mt-0.5 h-4 w-4 shrink-0',
          variant === 'info' && 'text-info',
          variant === 'warning' && 'text-warning',
          variant === 'error' && 'text-destructive-text',
          variant === 'success' && 'text-success'
        )}
      />
      <div className="min-w-0 flex-1 [&_a]:underline">{children}</div>
      {action ? <div className="shrink-0">{action}</div> : null}
      {onDismiss ? (
        <button
          type="button"
          onClick={onDismiss}
          aria-label={t('banner.dismiss')}
          className="shrink-0 rounded-sm p-0.5 text-muted-foreground hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </button>
      ) : null}
    </div>
  );
}

export function BannerArea({
  children,
  className,
  'aria-label': ariaLabel,
}: {
  children: React.ReactNode;
  className?: string;
  'aria-label'?: string;
}) {
  return (
    <div
      role="region"
      aria-label={ariaLabel}
      className={cn('flex flex-col gap-2', className)}
    >
      {children}
    </div>
  );
}

export interface StepperProps {
  steps: Array<{ id: string; label: string; description?: string }>;
  /** Zero-based index of the current step; steps before it are complete. */
  current: number;
  onStepSelect?: (index: number) => void;
  className?: string;
  'aria-label'?: string;
}

/**
 * Stepper for the installer and onboarding flows (docs/02-ui-spec.md 3.5):
 * completed / current / upcoming states, keyboard navigable when
 * onStepSelect is provided.
 */
export function Stepper({ steps, current, onStepSelect, className, 'aria-label': ariaLabel }: StepperProps) {
  const { t } = useI18n();
  return (
    <ol aria-label={ariaLabel} className={cn('flex flex-col gap-0 sm:flex-row sm:gap-2', className)}>
      {steps.map((step, index) => {
        const state = index < current ? 'complete' : index === current ? 'current' : 'upcoming';
        const selectable = onStepSelect !== undefined && index <= current;
        const circle = (
          <span
            aria-hidden="true"
            className={cn(
              'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium transition-colors',
              state === 'complete' && 'border-primary bg-primary text-primary-foreground',
              state === 'current' && 'border-primary text-primary',
              state === 'upcoming' && 'border-border text-muted-foreground'
            )}
          >
            {state === 'complete' ? <Check className="h-3.5 w-3.5" /> : index + 1}
          </span>
        );
        const labelNode = (
          <span className="flex flex-col">
            <span
              className={cn(
                'text-13 font-medium',
                state === 'upcoming' ? 'text-muted-foreground' : 'text-foreground'
              )}
            >
              {step.label}
            </span>
            {step.description ? (
              <span className="text-xs text-muted-foreground">{step.description}</span>
            ) : null}
          </span>
        );
        const stateLabel = t(
          state === 'complete'
            ? 'stepper.completed'
            : state === 'current'
              ? 'stepper.current'
              : 'stepper.upcoming'
        );
        const className = cn(
          'flex items-start gap-2 rounded-md py-1 pr-3',
          selectable && 'cursor-pointer hover:bg-surface-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
        );
        return (
          <li
            key={step.id}
            aria-current={state === 'current' ? 'step' : undefined}
            className={cn('sm:flex-1', className)}
          >
            {selectable ? (
              <button
                type="button"
                aria-label={t('stepper.step', {
                  current: index + 1,
                  total: steps.length,
                  label: `${step.label} (${stateLabel})`,
                })}
                className="flex w-full items-start gap-2 text-left"
                onClick={() => onStepSelect?.(index)}
              >
                {circle}
                {labelNode}
              </button>
            ) : (
              <span className="flex items-start gap-2">
                {circle}
                {labelNode}
                <span className="sr-only">{stateLabel}</span>
              </span>
            )}
          </li>
        );
      })}
    </ol>
  );
}

export interface PaginationProps {
  page: number;
  pageCount: number;
  onPageChange: (page: number) => void;
  /** Numbered buttons around the current page (default 1 each side). */
  siblingCount?: number;
  disabled?: boolean;
  className?: string;
}

/**
 * Page navigation (docs/02-ui-spec.md 3.1 footer): first/previous/numbers/
 * next/last plus go-to-page. The DataTable footer composes this with the
 * page-size selector.
 */
export function Pagination({
  page,
  pageCount,
  onPageChange,
  siblingCount = 1,
  disabled = false,
  className,
}: PaginationProps) {
  const { t } = useI18n();
  const [goTo, setGoTo] = useState('');
  const [invalid, setInvalid] = useState(false);
  const inputId = useId();

  const clamp = (value: number): number => Math.min(Math.max(value, 1), pageCount);

  const numbers: number[] = [];
  const start = Math.max(page - siblingCount, 1);
  const end = Math.min(page + siblingCount, pageCount);
  for (let index = start; index <= end; index += 1) numbers.push(index);

  const navButton = (
    label: string,
    icon: React.ReactNode,
    target: number,
    enabled: boolean,
    key: string
  ) => (
    <button
      key={key}
      type="button"
      aria-label={label}
      disabled={disabled || !enabled}
      onClick={() => onPageChange(clamp(target))}
      className={cn(
        'inline-flex h-7 min-w-7 items-center justify-center rounded-md border border-border px-1.5 text-13 text-foreground transition-colors',
        'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        'disabled:pointer-events-none disabled:opacity-50'
      )}
    >
      {icon}
    </button>
  );

  return (
    <nav
      role="navigation"
      aria-label={t('pagination.page', { current: page, total: pageCount })}
      className={cn('flex flex-wrap items-center gap-1', className)}
    >
      {navButton(t('pagination.first'), '«', 1, page > 1, 'first')}
      {navButton(t('pagination.previous'), '‹', page - 1, page > 1, 'prev')}
      {start > 1 ? (
        <span className="px-1 text-xs text-muted-foreground" aria-hidden="true">
          …
        </span>
      ) : null}
      {numbers.map((number) => (
        <button
          key={number}
          type="button"
          aria-label={t('pagination.page', { current: number, total: pageCount })}
          aria-current={number === page ? 'page' : undefined}
          disabled={disabled}
          onClick={() => onPageChange(number)}
          className={cn(
            'inline-flex h-7 min-w-7 items-center justify-center rounded-md border px-1.5 text-13 tabular-nums transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            'disabled:pointer-events-none disabled:opacity-50',
            number === page
              ? 'border-primary bg-primary text-primary-foreground'
              : 'border-border text-foreground hover:bg-surface-200'
          )}
        >
          {number}
        </button>
      ))}
      {end < pageCount ? (
        <span className="px-1 text-xs text-muted-foreground" aria-hidden="true">
          …
        </span>
      ) : null}
      {navButton(t('pagination.next'), '›', page + 1, page < pageCount, 'next')}
      {navButton(t('pagination.last'), '»', pageCount, page < pageCount, 'last')}

      <span className="ml-2 flex items-center gap-1 text-xs text-muted-foreground">
        <label htmlFor={inputId}>{t('pagination.goTo')}</label>
        <input
          id={inputId}
          type="text"
          inputMode="numeric"
          value={goTo}
          disabled={disabled}
          aria-invalid={invalid || undefined}
          onChange={(event) => {
            setGoTo(event.target.value.replace(/\D/g, ''));
            setInvalid(false);
          }}
          onKeyDown={(event) => {
            if (event.key !== 'Enter') return;
            const target = Number.parseInt(goTo, 10);
            if (Number.isFinite(target) && target >= 1 && target <= pageCount) {
              onPageChange(target);
              setGoTo('');
              setInvalid(false);
            } else {
              setInvalid(true);
            }
          }}
          className={cn(
            'h-7 w-14 rounded-md border border-input bg-transparent px-1.5 text-13 tabular-nums',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            invalid && 'border-destructive'
          )}
        />
      </span>
      {invalid ? (
        <p role="alert" className="text-xs text-destructive-text">
          {t('pagination.invalidPage', { total: pageCount })}
        </p>
      ) : null}
    </nav>
  );
}
