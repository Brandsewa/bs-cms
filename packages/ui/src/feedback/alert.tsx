import { cva, type VariantProps } from 'class-variance-authority';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '../lib/utils';

const alertVariants = cva(
  'flex gap-3 rounded-md border p-3 text-13 [&_svg]:mt-0.5 [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        info: 'border-info/30 bg-info/10 text-foreground [&_svg]:text-info',
        warning: 'border-warning/40 bg-warning/10 text-foreground [&_svg]:text-warning',
        error: 'border-destructive/40 bg-destructive/10 text-foreground [&_svg]:text-destructive',
        success: 'border-success/30 bg-success/10 text-foreground [&_svg]:text-success',
      },
    },
    defaultVariants: {
      variant: 'info',
    },
  }
);

export type AlertVariant = NonNullable<VariantProps<typeof alertVariants>['variant']>;

const ICONS: Record<AlertVariant, typeof Info> = {
  info: Info,
  warning: AlertTriangle,
  error: XCircle,
  success: CheckCircle2,
};

export interface AlertProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'>, VariantProps<typeof alertVariants> {
  title?: ReactNode;
  children?: ReactNode;
}

/**
 * Inline alert/admonition (info, warning, error, success) — docs/02-ui-spec.md
 * 3.4 and section 2 banner area items. Announced politely via role=status.
 */
function Alert({ className, variant = 'info', title, children, ...props }: AlertProps) {
  const Icon = ICONS[variant ?? 'info'];
  return (
    <div
      role="status"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    >
      <Icon aria-hidden="true" />
      <div className="min-w-0">
        {title ? <p className="font-medium text-foreground">{title}</p> : null}
        {children ? <div className={cn(title && 'mt-0.5 text-muted-foreground')}>{children}</div> : null}
      </div>
    </div>
  );
}

export { Alert, alertVariants };
