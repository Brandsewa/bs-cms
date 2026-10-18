import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import { Circle } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef, type ReactNode } from 'react';
import { cn } from '../lib/utils';

const RadioGroup = forwardRef<
  ElementRef<typeof RadioGroupPrimitive.Root>,
  ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Root>
>(function RadioGroup({ className, ...props }, ref) {
  return (
    <RadioGroupPrimitive.Root
      ref={ref}
      className={cn('grid gap-2', className)}
      {...props}
    />
  );
});

const RadioGroupItem = forwardRef<
  ElementRef<typeof RadioGroupPrimitive.Item>,
  ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>
>(function RadioGroupItem({ className, ...props }, ref) {
  return (
    <RadioGroupPrimitive.Item
      ref={ref}
      className={cn(
        'aspect-square h-4 w-4 rounded-full border border-input bg-background shadow-xs transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
        'disabled:cursor-not-allowed disabled:opacity-50',
        'data-[state=checked]:border-primary data-[state=checked]:text-primary',
        'aria-[invalid=true]:border-destructive',
        className
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator className="flex items-center justify-center">
        <Circle className="h-2 w-2 fill-primary text-primary" aria-hidden="true" />
      </RadioGroupPrimitive.Indicator>
    </RadioGroupPrimitive.Item>
  );
});

export interface RadioCardProps
  extends Omit<ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>, 'title'> {
  title: ReactNode;
  description?: ReactNode;
}

/**
 * Radio option rendered as a selectable card with a title and optional
 * description (docs/02-ui-spec.md 3.2 "radio cards"; used for policy
 * presets, editor profiles, etc.).
 */
const RadioCard = forwardRef<ElementRef<typeof RadioGroupPrimitive.Item>, RadioCardProps>(
  function RadioCard({ className, title, description, children, ...props }, ref) {
    return (
      <RadioGroupPrimitive.Item
        ref={ref}
        className={cn(
          'group/card relative flex w-full items-start gap-3 rounded-lg border border-border bg-card p-3 text-left shadow-xs transition-colors',
          'hover:bg-surface-100',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'data-[state=checked]:border-primary data-[state=checked]:bg-accent',
          className
        )}
        {...props}
      >
        <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-input bg-background transition-colors group-data-[state=checked]/card:border-primary">
          <span className="h-2 w-2 rounded-full opacity-0 group-data-[state=checked]/card:bg-primary group-data-[state=checked]/card:opacity-100" />
        </span>
        <span className="min-w-0">
          <span className="block text-13 font-medium text-foreground">{title}</span>
          {description ? (
            <span className="mt-0.5 block text-xs text-muted-foreground">{description}</span>
          ) : null}
          {children}
        </span>
      </RadioGroupPrimitive.Item>
    );
  }
);

export { RadioGroup, RadioGroupItem, RadioCard };
