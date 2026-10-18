import * as AccordionPrimitive from '@radix-ui/react-accordion';
import { ChevronDown } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

const Accordion = AccordionPrimitive.Root;

const AccordionItem = forwardRef<
  ElementRef<typeof AccordionPrimitive.Item>,
  ComponentPropsWithoutRef<typeof AccordionPrimitive.Item>
>(function AccordionItem({ className, ...props }, ref) {
  return (
    <AccordionPrimitive.Item
      ref={ref}
      className={cn('border-b border-border', className)}
      {...props}
    />
  );
});

const AccordionTrigger = forwardRef<
  ElementRef<typeof AccordionPrimitive.Trigger>,
  ComponentPropsWithoutRef<typeof AccordionPrimitive.Trigger>
>(function AccordionTrigger({ className, children, ...props }, ref) {
  return (
    <AccordionPrimitive.Header className="flex">
      <AccordionPrimitive.Trigger
        ref={ref}
        className={cn(
          'flex flex-1 items-center justify-between gap-2 py-3 text-left text-13 font-medium transition-colors hover:bg-surface-100',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          '[&[data-state=open]>svg]:rotate-180',
          className
        )}
        {...props}
      >
        {children}
        <ChevronDown
          className="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-[var(--duration-base)]"
          aria-hidden="true"
        />
      </AccordionPrimitive.Trigger>
    </AccordionPrimitive.Header>
  );
});

const AccordionContent = forwardRef<
  ElementRef<typeof AccordionPrimitive.Content>,
  ComponentPropsWithoutRef<typeof AccordionPrimitive.Content>
>(function AccordionContent({ className, children, ...props }, ref) {
  return (
    <AccordionPrimitive.Content
      ref={ref}
      className="overflow-hidden text-13 data-[state=closed]:hidden"
      {...props}
    >
      <div className={cn('pb-3 pt-0 text-muted-foreground', className)}>{children}</div>
    </AccordionPrimitive.Content>
  );
});

export { Accordion, AccordionContent, AccordionItem, AccordionTrigger };
