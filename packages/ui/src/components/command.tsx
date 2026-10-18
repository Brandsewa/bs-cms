import {
  CommandEmpty as CmdkEmpty,
  CommandGroup as CmdkGroup,
  CommandInput as CmdkInput,
  CommandItem as CmdkItem,
  CommandList as CmdkList,
  Command as CmdkRoot,
  CommandSeparator as CmdkSeparator,
} from 'cmdk';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef, type HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

/**
 * Command menu primitives on cmdk — used by the Ctrl/Cmd+K menu (phase-05
 * 5.4) and searchable pickers. The menu shell composes these with a dialog.
 */
const Command = forwardRef<
  ElementRef<typeof CmdkRoot>,
  ComponentPropsWithoutRef<typeof CmdkRoot>
>(function Command({ className, ...props }, ref) {
  return (
    <CmdkRoot
      ref={ref}
      className={cn(
        'flex h-full w-full flex-col overflow-hidden rounded-md bg-popover text-popover-foreground',
        className
      )}
      {...props}
    />
  );
});

const CommandInput = forwardRef<
  ElementRef<typeof CmdkInput>,
  ComponentPropsWithoutRef<typeof CmdkInput>
>(function CommandInput({ className, ...props }, ref) {
  return (
    <div className="flex items-center border-b border-border px-3">
      <CmdkInput
        ref={ref}
        className={cn(
          'flex h-9 w-full bg-transparent py-2 text-13 outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
          className
        )}
        {...props}
      />
    </div>
  );
});

const CommandList = forwardRef<
  ElementRef<typeof CmdkList>,
  ComponentPropsWithoutRef<typeof CmdkList>
>(function CommandList({ className, ...props }, ref) {
  return (
    <CmdkList
      ref={ref}
      className={cn('max-h-72 overflow-y-auto overflow-x-hidden p-1', className)}
      {...props}
    />
  );
});

function CommandEmpty(props: ComponentPropsWithoutRef<typeof CmdkEmpty>) {
  return (
    <CmdkEmpty
      className="py-6 text-center text-13 text-muted-foreground"
      {...props}
    />
  );
}

const CommandGroup = forwardRef<
  ElementRef<typeof CmdkGroup>,
  ComponentPropsWithoutRef<typeof CmdkGroup>
>(function CommandGroup({ className, ...props }, ref) {
  return (
    <CmdkGroup
      ref={ref}
      className={cn(
        'overflow-hidden p-1 text-foreground [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground',
        className
      )}
      {...props}
    />
  );
});

const CommandSeparator = forwardRef<
  ElementRef<typeof CmdkSeparator>,
  ComponentPropsWithoutRef<typeof CmdkSeparator>
>(function CommandSeparator({ className, ...props }, ref) {
  return (
    <CmdkSeparator
      ref={ref}
      className={cn('-mx-1 my-1 h-px bg-border-muted', className)}
      {...props}
    />
  );
});

const CommandItem = forwardRef<
  ElementRef<typeof CmdkItem>,
  ComponentPropsWithoutRef<typeof CmdkItem>
>(function CommandItem({ className, ...props }, ref) {
  return (
    <CmdkItem
      ref={ref}
      className={cn(
        'relative flex cursor-default select-none items-center gap-2 rounded-sm px-2 py-1.5 text-13 outline-none',
        'data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground',
        'data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50',
        '[&_svg]:size-4 [&_svg]:shrink-0',
        className
      )}
      {...props}
    />
  );
});

function CommandShortcut({ className, ...props }: HTMLAttributes<HTMLSpanElement>) {
  return (
    <span
      className={cn('ml-auto text-xs tracking-widest text-muted-foreground', className)}
      {...props}
    />
  );
}

export {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
};
