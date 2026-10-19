import { ChevronRight } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ReactNode } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

/**
 * Page scaffolding (docs/02-ui-spec.md 3.5): width tiers, header with
 * actions aside, section nav tabs, sections with title/description, cards.
 */

export interface PageContainerProps extends ComponentPropsWithoutRef<'div'> {
  /** small: settings/forms · default: lists/details · full: editors, wide tables. */
  width?: 'small' | 'default' | 'full';
}

const TIERS = {
  small: 'max-w-3xl',
  default: 'max-w-6xl',
  full: 'max-w-none',
} as const;

export function PageContainer({
  width = 'default',
  className,
  children,
  ...props
}: PageContainerProps) {
  return (
    <div
      className={cn('mx-auto w-full px-4 py-6 sm:px-6', TIERS[width], className)}
      {...props}
    >
      {children}
    </div>
  );
}

export interface PageHeaderProps {
  title: ReactNode;
  description?: ReactNode;
  /** Meta line under the title (counts, status badges, last saved). */
  meta?: ReactNode;
  /** Actions in the header aside (primary buttons, menus). */
  actions?: ReactNode;
  className?: string;
}

export function PageHeader({ title, description, meta, actions, className }: PageHeaderProps) {
  return (
    <header className={cn('flex flex-wrap items-start justify-between gap-4', className)}>
      <div className="min-w-0">
        <h1 className="text-lg font-semibold text-foreground">{title}</h1>
        {description ? (
          <p className="mt-1 max-w-2xl text-13 text-muted-foreground">{description}</p>
        ) : null}
        {meta ? <div className="mt-2 flex flex-wrap items-center gap-2">{meta}</div> : null}
      </div>
      {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
    </header>
  );
}

export interface PageSectionProps {
  title?: ReactNode;
  description?: ReactNode;
  /** Aside actions for the section row. */
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
}

export function PageSection({ title, description, actions, children, className }: PageSectionProps) {
  return (
    <section className={cn('space-y-3', className)}>
      {title || actions ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            {title ? (
              <h2 className="text-base font-semibold text-foreground">{title}</h2>
            ) : null}
            {description ? (
              <p className="mt-0.5 text-13 text-muted-foreground">{description}</p>
            ) : null}
          </div>
          {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
        </div>
      ) : null}
      {children}
    </section>
  );
}

export interface BreadcrumbItem {
  label: ReactNode;
  href?: string;
}

export interface BreadcrumbsProps {
  items: BreadcrumbItem[];
  className?: string;
  /** Renders links; screens pass their router Link here (M3 shell). */
  renderLink?: (item: BreadcrumbItem, content: ReactNode) => ReactNode;
}

export function Breadcrumbs({ items, className, renderLink }: BreadcrumbsProps) {
  const { t } = useI18n();
  return (
    <nav aria-label={t('breadcrumbs.label')} className={cn('text-13', className)}>
      <ol className="flex flex-wrap items-center gap-1 text-muted-foreground">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;
          const content = (
            <span
              className={cn(
                isLast && 'font-medium text-foreground',
                !isLast && item.href !== undefined && 'hover:text-foreground'
              )}
            >
              {item.label}
            </span>
          );
          return (
            <li key={index} className="flex items-center gap-1">
              {isLast ? (
                <span aria-current="page">{content}</span>
              ) : item.href !== undefined ? (
                renderLink ? (
                  renderLink(item, content)
                ) : (
                  <a href={item.href}>{content}</a>
                )
              ) : (
                content
              )}
              {!isLast ? <ChevronRight className="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

const Card = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function Card(
  { className, ...props },
  ref
) {
  return (
    <div
      ref={ref}
      className={cn('rounded-lg border border-border bg-card text-card-foreground shadow-xs', className)}
      {...props}
    />
  );
});

const CardHeader = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardHeader(
  { className, ...props },
  ref
) {
  return <div ref={ref} className={cn('flex flex-col gap-1 p-4', className)} {...props} />;
});

const CardTitle = forwardRef<HTMLHeadingElement, ComponentPropsWithoutRef<'h3'> & { as?: 'h2' | 'h3' | 'h4' }>(
  function CardTitle({ className, as: Tag = 'h3', ...props }, ref) {
    return (
      <Tag
        ref={ref}
        className={cn('text-sm font-semibold leading-none', className)}
        {...props}
      />
    );
  }
);

const CardDescription = forwardRef<HTMLParagraphElement, ComponentPropsWithoutRef<'p'>>(
  function CardDescription({ className, ...props }, ref) {
    return (
      <p ref={ref} className={cn('text-13 text-muted-foreground', className)} {...props} />
    );
  }
);

const CardContent = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardContent(
  { className, ...props },
  ref
) {
  return <div ref={ref} className={cn('p-4 pt-0', className)} {...props} />;
});

const CardFooter = forwardRef<HTMLDivElement, ComponentPropsWithoutRef<'div'>>(function CardFooter(
  { className, ...props },
  ref
) {
  return (
    <div ref={ref} className={cn('flex items-center gap-2 p-4 pt-0', className)} {...props} />
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

export function PageNav({ items, value, onChange, 'aria-label': ariaLabel, className }: PageNavProps) {
  return (
    <nav
      role="tablist"
      aria-label={ariaLabel}
      className={cn('flex gap-4 overflow-x-auto border-b border-border', className)}
    >
      {items.map((item) => {
        const active = item.id === value;
        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={active}
            disabled={item.disabled}
            onClick={() => onChange(item.id)}
            className={cn(
              '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 px-1 pb-2 pt-1 text-13 font-medium transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              'disabled:pointer-events-none disabled:opacity-50',
              active
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )}
          >
            {item.label}
            {typeof item.count === 'number' ? (
              <span className="rounded-full bg-surface-200 px-1.5 text-xs tabular-nums text-muted-foreground">
                {item.count}
              </span>
            ) : null}
          </button>
        );
      })}
    </nav>
  );
}
