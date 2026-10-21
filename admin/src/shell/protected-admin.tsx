import type { ReactNode } from 'react';
import { useSession } from '../auth/session-context';
import { AdminLayout } from './admin-layout';
import { ForbiddenRoute } from './forbidden';

export function ProtectedAdmin({
  permission,
  children,
}: {
  permission?: string;
  children: ReactNode;
}) {
  const { session, isLoading, hasPermission } = useSession();

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-3">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
          <p className="text-13 text-muted-foreground">Loading session...</p>
        </div>
      </div>
    );
  }

  if (!session || session.status !== 'authenticated') {
    // Unauthenticated: redirect to login preserving current route
    if (typeof window !== 'undefined') {
      const current = window.location.pathname + window.location.search;
      window.location.href = `/admin/login?return_to=${encodeURIComponent(current)}`;
    }
    return null;
  }

  // Permission check
  const roleHandles = session.roleHandles ?? [];
  const isOwner = roleHandles.includes('owner') || roleHandles.includes('admin');

  if (permission && !isOwner) {
    let allowed = false;
    if (permission.includes(':')) {
      const parts = permission.split(':');
      const subject = parts[0] ?? '';
      const action = parts[1] ?? 'read';
      allowed = hasPermission(subject, action);
    } else {
      allowed = hasPermission(permission, 'read');
    }

    if (!allowed) {
      return (
        <AdminLayout>
          <ForbiddenRoute />
        </AdminLayout>
      );
    }
  }

  return <AdminLayout>{children}</AdminLayout>;
}
