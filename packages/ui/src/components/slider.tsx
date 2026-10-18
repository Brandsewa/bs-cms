import * as SliderPrimitive from '@radix-ui/react-slider';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

export type SliderProps = ComponentPropsWithoutRef<typeof SliderPrimitive.Root>;

/**
 * Slider primitive (range-capable via array values). The accessible name is
 * taken from the root's aria-label / aria-labelledby and applied to every
 * thumb — range sliders name thumbs "<label> start" and "<label> end".
 */
const Slider = forwardRef<ElementRef<typeof SliderPrimitive.Root>, SliderProps>(function Slider(
  { className, 'aria-label': ariaLabel, 'aria-labelledby': ariaLabelledBy, value, defaultValue, ...props },
  ref
) {
  const values = value ?? defaultValue;
  const thumbCount = Array.isArray(values) ? values.length : 1;

  const thumbName = (index: number): string | undefined => {
    if (!ariaLabel) return undefined;
    if (thumbCount < 2) return ariaLabel;
    return `${ariaLabel} ${index === 0 ? 'start' : 'end'}`;
  };

  const thumbLabelledBy = (): string | undefined => {
    if (!ariaLabelledBy) return undefined;
    // Range sliders name thumbs via aria-label ("<label> start/end"); a
    // single external label cannot name both thumbs.
    return thumbCount < 2 ? ariaLabelledBy : undefined;
  };

  return (
    <SliderPrimitive.Root
      ref={ref}
      value={value}
      defaultValue={defaultValue}
      className={cn(
        'relative flex w-full touch-none select-none items-center data-[orientation=vertical]:h-full data-[orientation=vertical]:min-h-32 data-[orientation=vertical]:w-auto data-[orientation=vertical]:flex-col',
        className
      )}
      {...props}
    >
      <SliderPrimitive.Track className="relative h-1 w-full grow overflow-hidden rounded-full bg-secondary data-[orientation=vertical]:h-full data-[orientation=vertical]:w-1">
        <SliderPrimitive.Range className="absolute h-full w-full bg-primary data-[orientation=vertical]:w-full" />
      </SliderPrimitive.Track>
      {Array.from({ length: thumbCount }).map((_, index) => (
        <SliderPrimitive.Thumb
          key={index}
          aria-label={thumbName(index)}
          aria-labelledby={thumbLabelledBy()}
          className={cn(
            'block h-4 w-4 rounded-full border border-primary/50 bg-background shadow-sm transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
            'disabled:pointer-events-none disabled:opacity-50'
          )}
        />
      ))}
    </SliderPrimitive.Root>
  );
});

export { Slider };
