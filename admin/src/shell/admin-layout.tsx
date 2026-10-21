import { useState, type ReactNode } from 'react';
import {
  AppSidebar,
  Breadcrumbs,
  BannerArea,
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  Button,
} from '@bs/ui';
import { User, LogOut, Search, ShieldCheck } from 'lucide-react';
import { useSession } from '../auth/session-context';
import { ADMIN_SIDEBAR_GROUPS, type AdminSidebarItem } from './nav-groups';
import { ShellErrorBoundary } from './error-boundary';
import { StepUpDialog } from '../auth/step-up-dialog';
import { ShellThemeToggle } from './theme-toggle';

export function AdminLayout({ children }: { children: ReactNode }) {
  const { session, logout, hasPermission: sessionHasPermission } = useSession();
  const [stepUpOpen, setStepUpOpen] = useState(false);

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

  return (
    <div className="flex min-h-screen bg-background text-foreground">
      {/* Sidebar */}
      <AppSidebar
        className="shrink-0 hidden md:flex"
        groups={ADMIN_SIDEBAR_GROUPS}
        hasPermission={hasPermission}
        activeId={activeId}
        renderLink={(item, content, isActive) => {
          const adminItem = item as AdminSidebarItem;
          return (
            <a
              href={adminItem.to || '#'}
              aria-current={isActive ? 'page' : undefined}
            >
              {content}
            </a>
          );
        }}
      />

      {/* Main column */}
      <div className="flex flex-1 flex-col min-w-0">
        {/* Top bar - slim Studio style header */}
        <header className="sticky top-0 z-10 flex h-11 items-center justify-between border-b border-border bg-background px-4 sm:px-6">
          <div className="flex items-center gap-3 min-w-0">
            <Breadcrumbs
              items={[
                { label: 'Admin', href: '/admin/' },
                ...(activeId ? [{ label: activeId.charAt(0).toUpperCase() + activeId.slice(1) }] : []),
              ]}
            />
          </div>

          <div className="flex items-center gap-2">
            {/* Search command menu trigger */}
            <Button
              variant="outline"
              size="sm"
              className="hidden sm:flex h-7 items-center gap-2 px-2 text-foreground-lighter hover:text-foreground"
              onClick={() => {
                // Future M4 command menu
              }}
            >
              <Search className="h-3.5 w-3.5" />
              <span className="text-12">Search...</span>
              <kbd className="rounded border border-border bg-surface-200 px-1.5 py-0.2 text-[10px] text-foreground-lighter">
                ⌘K
              </kbd>
            </Button>

            {/* Theme switcher toggle */}
            <ShellThemeToggle />

            {/* User menu dropdown */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="flex h-7 items-center gap-1.5 px-1.5 hover:bg-surface-200"
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
        <BannerArea>{null}</BannerArea>

        {/* Workspace body */}
        <main className="flex-1 overflow-y-auto">
          <ShellErrorBoundary>{children}</ShellErrorBoundary>
        </main>
      </div>

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
