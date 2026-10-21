import { useState, useMemo, type ReactNode } from 'react';
import {
  AppSidebar,
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
  Button,
  useHotkeys,
  useTheme,
  type HotkeyItem,
} from '@bs/ui';
import {
  User,
  LogOut,
  Search,
  ShieldCheck,
  Menu,
  Sun,
  Moon,
  Laptop,
} from 'lucide-react';
import { useSession } from '../auth/session-context';
import { ADMIN_SIDEBAR_GROUPS, type AdminSidebarItem } from './nav-groups';
import { ShellErrorBoundary } from './error-boundary';
import { StepUpDialog } from '../auth/step-up-dialog';
import { ShellThemeToggle } from './theme-toggle';
import { CommandMenu } from './command-menu';
import { ShellBannerArea } from './banner-area';

export function AdminLayout({ children }: { children: ReactNode }) {
  const { session, logout, hasPermission: sessionHasPermission } = useSession();
  const { theme, setTheme } = useTheme();
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [commandMenuOpen, setCommandMenuOpen] = useState(false);
  const [mobileDrawerOpen, setMobileDrawerOpen] = useState(false);

  const roleHandles = session?.roleHandles ?? [];
  const isOwner = roleHandles.includes('owner') || roleHandles.includes('admin');

  const hasPermission = (permission: string) => {
    if (isOwner) {
      return true;
    }
    if (permission.includes(':')) {
      const parts = permission.split(':');
      const subject = parts[0] ?? '';
      const action = parts[1] ?? 'read';
      return sessionHasPermission(subject, action);
    }
    return sessionHasPermission(permission, 'read');
  };

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/admin/';

  // Find active item
  let activeId: string | undefined;
  for (const group of ADMIN_SIDEBAR_GROUPS) {
    for (const item of group.items) {
      if (
        currentPath === item.to ||
        currentPath === item.to + '/' ||
        (item.to !== '/admin' && item.to !== '/admin/' && currentPath.startsWith(item.to))
      ) {
        activeId = item.id;
        break;
      }
    }
  }

  const primaryRole = roleHandles[0] ?? 'user';

  // Global hotkeys layer
  const hotkeys: HotkeyItem[] = useMemo(
    () => [
      {
        combo: 'ctrl+k',
        onTrigger: () => setCommandMenuOpen((prev) => !prev),
        description: 'Toggle command palette',
      },
      {
        combo: 'g d',
        onTrigger: () => {
          window.location.href = '/admin/';
        },
        description: 'Go to Dashboard',
      },
      {
        combo: 'g e',
        onTrigger: () => {
          if (hasPermission('collection:read')) {
            window.location.href = '/admin/entries';
          }
        },
        description: 'Go to Entries list',
      },
      {
        combo: 'g m',
        onTrigger: () => {
          if (hasPermission('media:read')) {
            window.location.href = '/admin/media';
          }
        },
        description: 'Go to Media library',
      },
      {
        combo: 'ctrl+s',
        onTrigger: () => {
          // Dispatch a custom global save event so active forms/editors can handle save draft
          window.dispatchEvent(new CustomEvent('bs:save-draft'));
        },
        description: 'Save current entry draft',
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [isOwner, session?.permissions]
  );

  useHotkeys(hotkeys);

  const renderSidebar = (isMobile = false) => (
    <AppSidebar
      className="shrink-0 h-full border-r border-border bg-background"
      groups={ADMIN_SIDEBAR_GROUPS}
      hasPermission={hasPermission}
      activeId={activeId}
      showCollapseButton={!isMobile}
      renderLink={(item, content, isActive, className) => {
        const adminItem = item as AdminSidebarItem;
        return (
          <a
            href={adminItem.to || '#'}
            aria-current={isActive ? 'page' : undefined}
            className={className}
            onClick={() => setMobileDrawerOpen(false)}
          >
            {content}
          </a>
        );
      }}
    />
  );

  return (
    <div className="flex min-h-screen bg-background text-foreground">
      {/* Skip to main content link for keyboard accessibility */}
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-primary-foreground focus:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
      >
        Skip to main content
      </a>

      {/* Desktop Sidebar */}
      <div className="hidden md:flex shrink-0">
        {renderSidebar(false)}
      </div>

      {/* Mobile Sidebar Drawer (<768px) */}
      <Sheet open={mobileDrawerOpen} onOpenChange={setMobileDrawerOpen}>
        <SheetContent side="left" className="p-0 w-64 border-r border-border bg-background">
          <SheetHeader className="sr-only">
            <SheetTitle>Navigation Menu</SheetTitle>
            <SheetDescription>Main administration navigation</SheetDescription>
          </SheetHeader>
          {renderSidebar(true)}
        </SheetContent>
      </Sheet>

      {/* Main column */}
      <div className="flex flex-1 flex-col min-w-0">
        {/* Top bar - slim Studio style header */}
        <header className="sticky top-0 z-10 flex h-11 items-center justify-between border-b border-border bg-background px-3 sm:px-6">
          <div className="flex items-center gap-2 sm:gap-3 min-w-0">
            {/* Mobile menu trigger (<768px) with accessible 44px touch target */}
            <Button
              variant="ghost"
              size="sm"
              className="flex md:hidden h-8 w-8 min-h-[44px] min-w-[44px] p-0"
              onClick={() => setMobileDrawerOpen(true)}
              aria-label="Open navigation menu"
            >
              <Menu className="h-4 w-4" />
            </Button>
            <div className="flex items-center gap-2 md:hidden">
              <span className="flex h-6 w-6 items-center justify-center rounded bg-primary text-[10px] font-bold text-primary-foreground">
                BS
              </span>
              <span className="text-13 font-semibold text-foreground">Studio</span>
            </div>
          </div>

          <div className="flex items-center gap-1 sm:gap-2">
            {/* Search command menu trigger */}
            <Button
              variant="outline"
              size="sm"
              className="hidden sm:flex h-7 items-center gap-2 px-2 text-foreground-lighter hover:text-foreground"
              onClick={() => setCommandMenuOpen(true)}
              aria-label="Search and quick actions (Ctrl+K)"
            >
              <Search className="h-3.5 w-3.5" />
              <span className="text-12">Search...</span>
              <kbd className="rounded border border-border bg-surface-200 px-1.5 py-0.2 text-[10px] text-foreground-lighter">
                {'\u2318'}K
              </kbd>
            </Button>

            {/* Mobile search icon button (<640px) with accessible 44px touch target */}
            <Button
              variant="ghost"
              size="sm"
              className="flex sm:hidden h-8 w-8 min-h-[44px] min-w-[44px] p-0"
              onClick={() => setCommandMenuOpen(true)}
              aria-label="Search and quick actions"
            >
              <Search className="h-4 w-4" />
            </Button>

            {/* Theme switcher toggle */}
            <ShellThemeToggle />

            {/* User menu dropdown with 44px touch target on mobile */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="flex h-7 items-center gap-1.5 px-1.5 min-h-[44px] min-w-[44px] sm:min-h-0 sm:min-w-0 hover:bg-surface-200"
                  aria-label="User menu"
                >
                  <div className="flex h-5.5 w-5.5 items-center justify-center rounded-full bg-surface-200 text-foreground">
                    <User className="h-3.5 w-3.5" />
                  </div>
                  <span className="hidden text-13 font-medium sm:inline-block text-foreground">
                    {session?.user?.name ?? 'Admin'}
                  </span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>
                  <div className="flex flex-col space-y-1">
                    <p className="text-13 font-medium leading-none">{session?.user?.name}</p>
                    <p className="text-11 leading-none text-muted-foreground">
                      {session?.user?.email}
                    </p>
                    <span className="mt-1 inline-flex w-fit items-center rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-secondary-foreground">
                      {primaryRole}
                    </span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {/* Theme selector in user menu */}
                <DropdownMenuLabel className="text-xs text-muted-foreground">Theme</DropdownMenuLabel>
                <DropdownMenuItem
                  onClick={() => setTheme('light')}
                  className="gap-2 cursor-pointer text-xs"
                >
                  <Sun className="h-3.5 w-3.5" />
                  <span>Light</span>
                  {theme === 'light' && <span className="ml-auto text-primary text-xs">✓</span>}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => setTheme('dark')}
                  className="gap-2 cursor-pointer text-xs"
                >
                  <Moon className="h-3.5 w-3.5" />
                  <span>Dark</span>
                  {theme === 'dark' && <span className="ml-auto text-primary text-xs">✓</span>}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => setTheme('system')}
                  className="gap-2 cursor-pointer text-xs"
                >
                  <Laptop className="h-3.5 w-3.5" />
                  <span>System</span>
                  {theme === 'system' && <span className="ml-auto text-primary text-xs">✓</span>}
                </DropdownMenuItem>

                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => {
                    setStepUpOpen(true);
                  }}
                  className="gap-2 cursor-pointer"
                >
                  <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                  <span>Verify session (2FA)</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => {
                    void logout();
                  }}
                  className="gap-2 text-destructive focus:text-destructive cursor-pointer"
                >
                  <LogOut className="h-4 w-4" />
                  <span>Log out</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        {/* Global banner area */}
        <ShellBannerArea />

        {/* Workspace body */}
        <main id="main-content" tabIndex={-1} className="flex-1 overflow-y-auto focus:outline-none">
          <ShellErrorBoundary>{children}</ShellErrorBoundary>
        </main>
      </div>

      {/* Command Menu Palette */}
      <CommandMenu open={commandMenuOpen} onOpenChange={setCommandMenuOpen} />

      {/* Step-up authentication dialog */}
      <StepUpDialog
        open={stepUpOpen}
        onOpenChange={setStepUpOpen}
        onSuccess={() => {
          setStepUpOpen(false);
        }}
      />
    </div>
  );
}
