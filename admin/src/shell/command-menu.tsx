import { useId } from 'react';
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
  CommandSeparator,
  CommandShortcut,
  useTheme,
} from '@bs/ui';
import {
  FileText,
  Plus,
  Image,
  Sun,
  Moon,
  Laptop,
  LogOut,
  Sparkles,
  User,
} from 'lucide-react';
import { ADMIN_SIDEBAR_GROUPS } from './nav-groups';
import { useSession } from '../auth/session-context';

export interface CommandMenuProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CommandMenu({ open, onOpenChange }: CommandMenuProps) {
  const { session, logout, hasPermission: sessionHasPermission } = useSession();
  const { theme, setTheme } = useTheme();
  const titleId = useId();
  const descriptionId = useId();

  const roleHandles = session?.roleHandles ?? [];
  const isOwner = roleHandles.includes('owner') || roleHandles.includes('admin');

  const hasPermission = (permission?: string) => {
    if (!permission || isOwner) return true;
    if (permission.includes(':')) {
      const parts = permission.split(':');
      return sessionHasPermission(parts[0] ?? '', parts[1] ?? 'read');
    }
    return sessionHasPermission(permission, 'read');
  };

  const runCommand = (action: () => void) => {
    onOpenChange(false);
    action();
  };

  const navigateTo = (url: string) => {
    runCommand(() => {
      window.location.href = url;
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
        className="overflow-hidden p-0 max-w-lg shadow-2xl border-border bg-popover"
      >
        <DialogTitle id={titleId} className="sr-only">
          Command Palette
        </DialogTitle>
        <DialogDescription id={descriptionId} className="sr-only">
          Search navigation routes, create new content, or toggle system themes.
        </DialogDescription>

        <Command className="rounded-lg border-0" label="Command menu">
          <CommandInput placeholder="Type a command or search..." autoFocus />
          <CommandList className="max-h-[320px]">
            <CommandEmpty>No results found.</CommandEmpty>

            {/* Quick Actions */}
            <CommandGroup heading="Quick Actions">
              {hasPermission('collection:create') && (
                <CommandItem
                  value="New Post"
                  onSelect={() => navigateTo('/admin/entries/posts/new')}
                  className="cursor-pointer"
                >
                  <Plus className="mr-2 h-4 w-4 text-primary" />
                  <span>New Post</span>
                </CommandItem>
              )}
              {hasPermission('collection:create') && (
                <CommandItem
                  value="New Page"
                  onSelect={() => navigateTo('/admin/entries/pages/new')}
                  className="cursor-pointer"
                >
                  <FileText className="mr-2 h-4 w-4 text-primary" />
                  <span>New Page</span>
                </CommandItem>
              )}
              {hasPermission('media:create') && (
                <CommandItem
                  value="Upload Media"
                  onSelect={() => navigateTo('/admin/media')}
                  className="cursor-pointer"
                >
                  <Image className="mr-2 h-4 w-4 text-primary" />
                  <span>Upload Media</span>
                </CommandItem>
              )}
              {hasPermission('ai:read') && (
                <CommandItem
                  value="AI Assistant Review"
                  onSelect={() => navigateTo('/admin/ai')}
                  className="cursor-pointer"
                >
                  <Sparkles className="mr-2 h-4 w-4 text-primary" />
                  <span>AI Assistant / Review</span>
                </CommandItem>
              )}
            </CommandGroup>

            <CommandSeparator />

            {/* Navigation Groups from Sidebar */}
            {ADMIN_SIDEBAR_GROUPS.map((group) => {
              const visibleItems = group.items.filter((item) => hasPermission(item.permission));
              if (visibleItems.length === 0) return null;

              return (
                <CommandGroup key={group.id} heading={group.label ?? 'Navigation'}>
                  {visibleItems.map((item) => {
                    const Icon = item.icon;
                    return (
                      <CommandItem
                        key={item.id}
                        value={item.label}
                        onSelect={() => navigateTo(item.to)}
                        className="cursor-pointer"
                      >
                        {Icon ? <Icon className="mr-2 h-4 w-4 text-muted-foreground" /> : null}
                        <span>{item.label}</span>
                        {item.id === 'entries' && <CommandShortcut>G E</CommandShortcut>}
                        {item.id === 'media' && <CommandShortcut>G M</CommandShortcut>}
                      </CommandItem>
                    );
                  })}
                </CommandGroup>
              );
            })}

            <CommandSeparator />

            {/* Account & Preferences */}
            <CommandGroup heading="Account">
              <CommandItem
                value="My Account Profile Password 2FA"
                onSelect={() => navigateTo('/admin/account')}
                className="cursor-pointer"
              >
                <User className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>My account</span>
              </CommandItem>
            </CommandGroup>

            <CommandSeparator />

            {/* Preferences & System */}
            <CommandGroup heading="Theme">
              <CommandItem
                value="Light Theme"
                onSelect={() => runCommand(() => setTheme('light'))}
                className="cursor-pointer"
              >
                <Sun className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>Light Theme</span>
                {theme === 'light' && <CommandShortcut>(Active)</CommandShortcut>}
              </CommandItem>
              <CommandItem
                value="Dark Theme"
                onSelect={() => runCommand(() => setTheme('dark'))}
                className="cursor-pointer"
              >
                <Moon className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>Dark Theme</span>
                {theme === 'dark' && <CommandShortcut>(Active)</CommandShortcut>}
              </CommandItem>
              <CommandItem
                value="System Preference"
                onSelect={() => runCommand(() => setTheme('system'))}
                className="cursor-pointer"
              >
                <Laptop className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>System Preference</span>
                {theme === 'system' && <CommandShortcut>(Active)</CommandShortcut>}
              </CommandItem>
            </CommandGroup>

            <CommandSeparator />

            <CommandGroup heading="Account">
              <CommandItem
                value="Log out"
                onSelect={() => runCommand(() => void logout())}
                className="cursor-pointer text-destructive"
              >
                <LogOut className="mr-2 h-4 w-4 text-destructive" />
                <span>Log out</span>
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </DialogContent>
    </Dialog>
  );
}
