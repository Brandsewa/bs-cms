import * as SwitchPrimitive from '@radix-ui/react-switch';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

export type SwitchProps = ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>;

const Switch = forwardRef<ElementRef<typeof SwitchPrimitive.Root>, SwitchProps>(
  function Switch({ className, ...props }, ref) {
    return (
      <SwitchPrimitive.Root
        ref={ref}
        className={cn(
          'peer inline-flex h-4.5 w-8 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent shadow-xs transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          'disabled:cursor-not-allowed disabled:opacity-50',
          'data-[state=checked]:bg-primary data-[state=unchecked]:bg-input',
          className
        )}
        {...props}
      >
        <SwitchPrimitive.Thumb
          className={cn(
            'pointer-events-none block h-3.5 w-3.5 rounded-full bg-background shadow-lg ring-0 transition-transform',
            'data-[state=checked]:translate-x-3.5 data-[state=unchecked]:translate-x-0'
          )}
        />
      </SwitchPrimitive.Root>
    );
  }
);

export { Switch };
