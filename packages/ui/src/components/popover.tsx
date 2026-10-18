import * as PopoverPrimitive from '@radix-ui/react-popover';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

const Popover = PopoverPrimitive.Root;
const PopoverTrigger = PopoverPrimitive.Trigger;
const PopoverAnchor = PopoverPrimitive.Anchor;
const PopoverClose = PopoverPrimitive.Close;

const PopoverContent = forwardRef<
  ElementRef<typeof PopoverPrimitive.Content>,
  ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>
>(function PopoverContent(
  { className, align = 'center', sideOffset = 4, ...props },
  ref
) {
  return (
    <PopoverPrimitive.Portal>
      <PopoverPrimitive.Content
        ref={ref}
        align={align}
        sideOffset={sideOffset}
        className={cn(
          'z-[var(--z-popover)] w-72 rounded-md border border-border bg-popover p-4 text-popover-foreground shadow-md outline-none',
          'focus-visible:ring-2 focus-visible:ring-ring',
          className
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  );
});

export { Popover, PopoverAnchor, PopoverClose, PopoverContent, PopoverTrigger };
