import { ChevronRight } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

/**
 * Page scaffolding (docs/02-ui-spec.md 3.5): width tiers, header with
 * actions aside, section nav tabs, sections with title/description, cards.
 */

export interface PageContainerProps extends ComponentPropsWithoutRef<'div'> {
  /** small: settings/forms (max-w-3xl) · default: lists/details (max-w-5xl) · full: editors, wide tables. */
  width?: 'small' | 'default' | 'full';
  /** Alias for Supabase Studio size prop compatibility ('small' | 'default' | 'large' | 'full') */
  size?: 'small' | 'default' | 'large' | 'full';
}

const TIERS = {
  small: 'max-w-3xl',
  default: 'max-w-6xl',
  large: 'max-w-7xl',
  full: 'max-w-none',
} as const;

export function PageContainer({
  width,
  size,
  className,
  children,
  ...props
}: PageContainerProps) {
  const resolvedWidth = size ?? width ?? 'default';
  return (
    <div
      className={cn('mx-auto w-full px-4 py-5 sm:px-6', TIERS[resolvedWidth], className)}
      {...props}
    >
      {children}
    </div>
  );
}

export interface PageHeaderProps {
  title: ReactNode;
  description?: ReactNode;
  icon?: ReactNode;
  /** Meta line under the title (counts, status badges, last saved). */
  meta?: ReactNode;
  /** Actions in the header aside (primary buttons, menus). */
  actions?: ReactNode;
  aside?: ReactNode;
  /** Layout size variant */
  size?: 'small' | 'default' | 'large' | 'full';
  className?: string;
  children?: ReactNode;
}

export function PageHeader({
  title,
  description,
  icon,
  meta,
  actions,
  aside,
  size: _size,
  className,
  children,
}: PageHeaderProps) {
  const asideActions = aside ?? actions;
  return (
    <header className={cn('flex flex-wrap items-start justify-between gap-3 pb-2', className)}>
      <div className="flex items-start gap-3 min-w-0">
        {icon ? <div className="mt-0.5 shrink-0">{icon}</div> : null}
        <div className="min-w-0">
          <h1 className="text-base font-semibold leading-tight text-foreground">{title}</h1>
          {description ? (
            <p className="mt-1 max-w-2xl text-13 leading-normal text-foreground-lighter">{description}</p>
          ) : null}
          {meta ? <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div> : null}
        </div>
      </div>
      {asideActions ? <div className="flex shrink-0 items-center gap-2">{asideActions}</div> : null}
      {children}
    </header>
  );
}

export interface PageSectionProps {
  title?: ReactNode;
  description?: ReactNode;
  /** Aside actions for the section row. */
  actions?: ReactNode;
  aside?: ReactNode;
  orientation?: 'vertical' | 'horizontal';
  children: ReactNode;
  className?: string;
}

export function PageSection({
  title,
  description,
  actions,
  aside,
  orientation = 'vertical',
  children,
  className,
}: PageSectionProps) {
  const asideActions = aside ?? actions;
  const isHorizontal = orientation === 'horizontal';

  return (
    <section className={cn('space-y-3', className)}>
      {title || asideActions ? (
        <div
          className={cn(
            'flex flex-wrap items-center justify-between gap-2',
            isHorizontal && 'md:grid md:grid-cols-3 md:gap-6'
          )}
        >
          <div className={cn(isHorizontal && 'md:col-span-1')}>
            {title ? (
              <h2 className="text-sm font-semibold text-foreground">{title}</h2>
            ) : null}
            {description ? (
              <p className="mt-0.5 text-13 text-foreground-lighter">{description}</p>
            ) : null}
          </div>
          {asideActions ? (
            <div className={cn('flex items-center gap-2', isHorizontal && 'md:col-span-2 md:justify-end')}>
              {asideActions}
            </div>
          ) : null}
        </div>
      ) : null}
      <div className={cn(isHorizontal && 'md:grid md:grid-cols-3 md:gap-6')}>
        <div className={cn(isHorizontal && 'md:col-span-2 md:col-start-2')}>{children}</div>
      </div>
    </section>
  );
}

export function PageBreadcrumbs({
  children,
  actions,
  className,
}: {
  children: ReactNode;
  actions?: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'flex w-full items-center justify-between border-b border-border bg-background px-4 py-2 sm:px-6',
        className
      )}
    >
      <div className="flex items-center min-w-0">{children}</div>
      {actions ? <div className="flex items-center gap-2 shrink-0">{actions}</div> : null}
    </div>
  );
}

export interface BreadcrumbItem {
  label: ReactNode;
  href?: string;
}

export interface BreadcrumbsProps {
  items: BreadcrumbItem[];
  className?: string;
  actions?: ReactNode;
  /** Renders links; screens pass their router Link here (M3 shell). */
  renderLink?: (item: BreadcrumbItem, content: ReactNode) => ReactNode;
}

export function Breadcrumbs({ items, className, actions, renderLink }: BreadcrumbsProps) {
  const { t } = useI18n();
  return (
    <div className={cn('flex items-center justify-between gap-4 w-full', className)}>
      <nav aria-label={t('breadcrumbs.label')} className="text-13">
        <ol className="flex flex-wrap items-center gap-1.5 text-foreground-lighter">
          {items.map((item, index) => {
            const isLast = index === items.length - 1;
            const content = (
              <span
                className={cn(
                  isLast && 'font-medium text-foreground',
                  !isLast && item.href !== undefined && 'hover:text-foreground transition-colors'
                )}
              >
                {item.label}
              </span>
            );
            return (
              <li key={index} className="flex items-center gap-1.5">
                {isLast ? (
                  <span aria-current="page">{content}</span>
                ) : item.href !== undefined ? (
                  renderLink ? (
                    renderLink(item, content)
                  ) : (
                    <a href={item.href} className="hover:text-foreground transition-colors">
                      {content}
                    </a>
                  )
                ) : (
                  content
                )}
                {!isLast ? <ChevronRight className="h-3.5 w-3.5 shrink-0 text-foreground-muted" aria-hidden="true" /> : null}
              </li>
            );
          })}
        </ol>
      </nav>
      {actions ? <div className="flex items-center gap-2 shrink-0">{actions}</div> : null}
    </div>
  );
}

const Card = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function Card(
  { className, ...props },
  ref
) {
  return (
    <div
      ref={ref}
      className={cn('rounded-md border border-border bg-card text-card-foreground shadow-xs', className)}
      {...props}
    />
  );
});

const CardHeader = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardHeader(
  { className, ...props },
  ref
) {
  return <div ref={ref} className={cn('flex flex-col gap-1 p-3.5', className)} {...props} />;
});

const CardTitle = forwardRef<HTMLHeadingElement, ComponentPropsWithoutRef<'h3'> & { as?: 'h2' | 'h3' | 'h4' }>(
  function CardTitle({ className, as: Tag = 'h3', ...props }, ref) {
    return (
      <Tag
        ref={ref}
        className={cn('text-sm font-semibold leading-tight text-foreground', className)}
        {...props}
      />
    );
  }
);

const CardDescription = forwardRef<HTMLParagraphElement, ComponentPropsWithoutRef<'p'>>(
  function CardDescription({ className, ...props }, ref) {
    return (
      <p ref={ref} className={cn('text-13 leading-normal text-foreground-lighter', className)} {...props} />
    );
  }
);

const CardContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardContent(
  { className, ...props },
  ref
) {
  return <div ref={ref} className={cn('p-3.5 pt-0', className)} {...props} />;
});

const CardFooter = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardFooter(
  { className, ...props },
  ref
) {
  return (
    <div ref={ref} className={cn('flex items-center gap-2 p-3.5 pt-0 border-t border-border/40 mt-3.5', className)} {...props} />
  );
});

export {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
};

/**
 * Section nav tabs: pages with sub-navigation put their tabs directly under
 * the header (docs/02-ui-spec.md 3.5 action placement rules). Built on the
 * Tabs primitives, styled as an underline row rather than pills.
 */
export interface PageNavItem {
  id: string;
  label: ReactNode;
  /** Optional count rendered after the label. */
  count?: number;
  disabled?: boolean;
}

export interface PageNavProps {
  items: PageNavItem[];
  value: string;
  onChange: (id: string) => void;
  'aria-label': string;
  className?: string;
}

export function PageNav({
  items,
  value,
  onChange,
  'aria-label': ariaLabel,
  className,
  children,
}: PageNavProps & { children?: ReactNode }) {
  if (children) {
    return (
      <div className={cn('border-b border-border bg-background px-4 sm:px-6', className)}>
        {children}
      </div>
    );
  }

  return (
    <nav
      role="tablist"
      aria-label={ariaLabel}
      className={cn('flex gap-6 overflow-x-auto border-b border-border bg-background px-4 sm:px-6', className)}
    >
      {items?.map((item) => {
        const active = item.id === value;
        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={active}
            disabled={item.disabled}
            onClick={() => onChange?.(item.id)}
            className={cn(
              '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 py-2 text-13 font-medium transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              'disabled:pointer-events-none disabled:opacity-50',
              active
                ? 'border-primary text-foreground'
                : 'border-transparent text-foreground-lighter hover:text-foreground'
            )}
          >
            {item.label}
            {typeof item.count === 'number' ? (
              <span className="rounded-full bg-surface-200 px-1.5 py-0.2 text-[11px] tabular-nums text-foreground-muted">
                {item.count}
              </span>
            ) : null}
          </button>
        );
      })}
    </nav>
  );
}
