import * as DialogPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';

const sheetVariants = cva(
  'fixed z-[var(--z-modal)] flex flex-col gap-4 border-border bg-card text-card-foreground shadow-lg outline-none focus-visible:ring-2 focus-visible:ring-ring',
  {
    variants: {
      side: {
        right: 'inset-y-0 right-0 h-full w-3/4 max-w-md border-l',
        left: 'inset-y-0 left-0 h-full w-3/4 max-w-md border-r',
        top: 'inset-x-0 top-0 h-auto max-h-[85vh] border-b',
        bottom: 'inset-x-0 bottom-0 h-auto max-h-[85vh] border-t',
      },
    },
    defaultVariants: {
      side: 'right',
    },
  }
);

const Sheet = DialogPrimitive.Root;
const SheetTrigger = DialogPrimitive.Trigger;
const SheetClose = DialogPrimitive.Close;
const SheetPortal = DialogPrimitive.Portal;

const SheetOverlay = forwardRef<
  ElementRef<typeof DialogPrimitive.Overlay>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(function SheetOverlay({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Overlay
      ref={ref}
      className={cn('fixed inset-0 z-[var(--z-overlay)] bg-overlay', className)}
      {...props}
    />
  );
});

export interface SheetContentProps
  extends ComponentPropsWithoutRef<typeof DialogPrimitive.Content>,
    VariantProps<typeof sheetVariants> {
  showClose?: boolean;
}

/**
 * Side panel for quick edit, filters on mobile, pickers and related-item
 * editing (docs/02-ui-spec.md 3.3). Focus is trapped while open and returned
 * to the trigger on close.
 */
const SheetContent = forwardRef<ElementRef<typeof DialogPrimitive.Content>, SheetContentProps>(
  function SheetContent({ className, children, side = 'right', showClose = true, ...props }, ref) {
    return (
      <SheetPortal>
        <SheetOverlay />
        <DialogPrimitive.Content
          ref={ref}
          className={cn(sheetVariants({ side }), className)}
          {...props}
        >
          {children}
          {showClose ? (
            <DialogPrimitive.Close
              className="absolute right-3 top-3 rounded-md p-1 text-muted-foreground opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label="Close"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </DialogPrimitive.Close>
          ) : null}
        </DialogPrimitive.Content>
      </SheetPortal>
    );
  }
);

function SheetHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex flex-col gap-1.5 p-6 pb-0', className)} {...props} />;
}

function SheetFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('mt-auto flex flex-col-reverse gap-2 p-6 pt-2 sm:flex-row sm:justify-end', className)}
      {...props}
    />
  );
}

const SheetTitle = forwardRef<
  ElementRef<typeof DialogPrimitive.Title>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(function SheetTitle({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Title
      ref={ref}
      className={cn('text-base font-semibold text-foreground', className)}
      {...props}
    />
  );
});

const SheetDescription = forwardRef<
  ElementRef<typeof DialogPrimitive.Description>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(function SheetDescription({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Description
      ref={ref}
      className={cn('text-13 text-muted-foreground', className)}
      {...props}
    />
  );
});

/**
 * Mobile drawer: a bottom sheet built on the accessible dialog primitive
 * (docs/02-ui-spec.md 3.3 "Drawer (mobile)"). Drag-to-dismiss is not offered;
 * Escape, overlay click and the close button dismiss it.
 */
const Drawer = Sheet;
const DrawerTrigger = SheetTrigger;
const DrawerClose = SheetClose;
export { DrawerTrigger };
const DrawerContent = forwardRef<ElementRef<typeof DialogPrimitive.Content>, SheetContentProps>(
  function DrawerContent({ className, side, showClose = true, children, ...props }, ref) {
    return (
      <SheetContent
        ref={ref}
        side={side ?? 'bottom'}
        showClose={showClose}
        className={cn('rounded-t-lg', className)}
        {...props}
      >
        {children}
      </SheetContent>
    );
  }
);
const DrawerHeader = SheetHeader;
const DrawerFooter = SheetFooter;
const DrawerTitle = SheetTitle;
const DrawerDescription = SheetDescription;

export {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
};
