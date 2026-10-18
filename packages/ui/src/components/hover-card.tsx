import * as HoverCardPrimitive from '@radix-ui/react-hover-card';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

const HoverCard = HoverCardPrimitive.Root;
const HoverCardTrigger = HoverCardPrimitive.Trigger;

const HoverCardContent = forwardRef<
  ElementRef<typeof HoverCardPrimitive.Content>,
  ComponentPropsWithoutRef<typeof HoverCardPrimitive.Content>
>(function HoverCardContent({ className, align = 'center', sideOffset = 4, ...props }, ref) {
  return (
    <HoverCardPrimitive.Portal>
      <HoverCardPrimitive.Content
        ref={ref}
        align={align}
        sideOffset={sideOffset}
        className={cn(
          'z-[var(--z-popover)] w-64 rounded-md border border-border bg-popover p-4 text-popover-foreground shadow-md outline-none',
          className
        )}
        {...props}
      />
    </HoverCardPrimitive.Portal>
  );
});

export { HoverCard, HoverCardContent, HoverCardTrigger };
