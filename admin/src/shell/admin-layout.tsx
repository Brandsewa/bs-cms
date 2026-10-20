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
  const { session, logout } = useSession();
  const [stepUpOpen, setStepUpOpen] = useState(false);

  const permissions = new Set<string>(session?.permissions ?? []);
  const roles = session?.user?.roles ?? [];
  const isOwner = roles.includes('owner') || roles.includes('admin');

  const hasPermission = (permission: string) => {
    if (isOwner) return true;
    return permissions.has(permission);
  };

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/admin';

  // Find active item
  let activeId: string | undefined;
  for (const group of ADMIN_SIDEBAR_GROUPS) {
    for (const item of group.items) {
      if (currentPath === item.to || (item.to !== '/admin' && currentPath.startsWith(item.to))) {
        activeId = item.id;
        break;
      }
    }
  }

  const primaryRole = roles[0] ?? 'user';

  return (
    <div className="flex min-h-screen bg-background text-foreground">
      {/* Sidebar */}
      <AppSidebar
        groups={ADMIN_SIDEBAR_GROUPS}
        hasPermission={hasPermission}
        activeId={activeId}
        renderLink={(item, content, isActive) => {
          const adminItem = item as AdminSidebarItem;
          return (
            <a
              href={adminItem.to || '#'}
              aria-current={isActive ? 'page' : undefined}
              className="flex w-full items-center gap-2"
            >
              {content}
            </a>
          );
        }}
      />

      {/* Main column */}
      <div className="flex flex-1 flex-col min-w-0">
        {/* Top bar */}
        <header className="sticky top-0 z-10 flex h-14 items-center justify-between border-b border-border bg-card px-4 sm:px-6">
          <div className="flex items-center gap-4 min-w-0">
            <Breadcrumbs
              items={[
                { label: 'Admin', href: '/admin' },
                ...(activeId ? [{ label: activeId.charAt(0).toUpperCase() + activeId.slice(1) }] : []),
              ]}
            />
          </div>

          <div className="flex items-center gap-2">
            {/* Search command menu trigger */}
            <Button
              variant="outline"
              size="sm"
              className="hidden sm:flex items-center gap-2 text-muted-foreground"
              onClick={() => {
                // Future M4 command menu
              }}
            >
              <Search className="h-3.5 w-3.5" />
              <span className="text-12">Search...</span>
              <kbd className="rounded border border-border bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                ⌘K
              </kbd>
            </Button>

            {/* Step-up trigger shortcut / demo */}
            <Button
              variant="ghost"
              size="sm"
              aria-label="Security verification"
              title="Security step-up test"
              onClick={() => setStepUpOpen(true)}
              className="h-8 w-8 p-0"
            >
              <ShieldCheck className="h-4 w-4" />
            </Button>

            {/* Theme toggle */}
            <ShellThemeToggle />

            {/* User profile dropdown menu */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="flex items-center gap-2 h-8 px-2">
                  <User className="h-4 w-4" />
                  <span className="hidden md:inline-block text-13 font-medium">
                    {session?.user?.name || session?.user?.email || 'User'}
                  </span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>
                  <div className="flex flex-col space-y-0.5">
                    <p className="text-13 font-medium">{session?.user?.name || 'Account'}</p>
                    <p className="text-12 text-muted-foreground truncate">{session?.user?.email}</p>
                    <span className="inline-block mt-1 text-[11px] font-semibold uppercase tracking-wider text-primary">
                      {primaryRole}
                    </span>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  onClick={() => {
                    void logout();
                  }}
                  className="text-destructive focus:text-destructive"
                >
                  <LogOut className="mr-2 h-4 w-4" />
                  <span>Log out</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        {/* Global Banner Area */}
        <BannerArea aria-label="System announcements" className="px-4 pt-3 sm:px-6">
          {null}
        </BannerArea>

        {/* Content Outlet wrapped in ErrorBoundary */}
        <main className="flex-1 min-w-0">
          <ShellErrorBoundary>{children}</ShellErrorBoundary>
        </main>
      </div>

      {/* Step up dialog */}
      <StepUpDialog
        open={stepUpOpen}
        onOpenChange={setStepUpOpen}
        onSuccess={() => {
          // Sensitive action completed
        }}
      />
    </div>
  );
}
