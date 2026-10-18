import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '../lib/utils';
import { buttonVariants } from './button';

const AlertDialog = AlertDialogPrimitive.Root;
const AlertDialogTrigger = AlertDialogPrimitive.Trigger;
const AlertDialogPortal = AlertDialogPrimitive.Portal;

const AlertDialogOverlay = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Overlay>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Overlay>
>(function AlertDialogOverlay({ className, ...props }, ref) {
  return (
    <AlertDialogPrimitive.Overlay
      ref={ref}
      className={cn('fixed inset-0 z-[var(--z-overlay)] bg-overlay', className)}
      {...props}
    />
  );
});

const AlertDialogContent = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Content>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Content>
>(function AlertDialogContent({ className, ...props }, ref) {
  return (
    <AlertDialogPortal>
      <AlertDialogOverlay />
      <AlertDialogPrimitive.Content
        ref={ref}
        className={cn(
          'fixed left-1/2 top-1/2 z-[var(--z-modal)] w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-border bg-card p-6 text-card-foreground shadow-lg outline-none',
          'focus-visible:ring-2 focus-visible:ring-ring',
          className
        )}
        {...props}
      />
    </AlertDialogPortal>
  );
});

function AlertDialogHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('mb-4 flex flex-col gap-1.5', className)} {...props} />;
}

function AlertDialogFooter({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn('mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)}
      {...props}
    />
  );
}

const AlertDialogTitle = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Title>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Title>
>(function AlertDialogTitle({ className, ...props }, ref) {
  return (
    <AlertDialogPrimitive.Title
      ref={ref}
      className={cn('text-base font-semibold', className)}
      {...props}
    />
  );
});

const AlertDialogDescription = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Description>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Description>
>(function AlertDialogDescription({ className, ...props }, ref) {
  return (
    <AlertDialogPrimitive.Description
      ref={ref}
      className={cn('text-13 text-muted-foreground', className)}
      {...props}
    />
  );
});

const AlertDialogAction = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Action>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Action> & {
    variant?: 'default' | 'destructive';
  }
>(function AlertDialogAction({ className, variant = 'destructive', ...props }, ref) {
  return (
    <AlertDialogPrimitive.Action
      ref={ref}
      className={cn(buttonVariants({ variant }), className)}
      {...props}
    />
  );
});

const AlertDialogCancel = forwardRef<
  ElementRef<typeof AlertDialogPrimitive.Cancel>,
  ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Cancel>
>(function AlertDialogCancel({ className, ...props }, ref) {
  return (
    <AlertDialogPrimitive.Cancel
      ref={ref}
      className={cn(buttonVariants({ variant: 'outline' }), className)}
      {...props}
    />
  );
});

export {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogOverlay,
  AlertDialogPortal,
  AlertDialogTitle,
  AlertDialogTrigger,
};
