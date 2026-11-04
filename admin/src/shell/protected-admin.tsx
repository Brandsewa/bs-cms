import type { ReactNode } from 'react';
import { PageLoadingSkeleton } from '@bs/ui';
import { useSession } from '../auth/session-context';
import { AdminLayout } from './admin-layout';
import { ForbiddenRoute } from './forbidden';

export function ProtectedAdmin({
  permission,
  children,
  chrome = true,
}: {
  permission?: string;
  children: ReactNode;
  /**
   * Render the standard admin shell (sidebar + topbar). Full-screen work
   * surfaces (e.g. the page designer) pass `false` to own the viewport.
   */
  chrome?: boolean;
}) {
  const { session, isLoading, hasPermission } = useSession();

  const wrap = (node: ReactNode) => (chrome ? <AdminLayout>{node}</AdminLayout> : node);

  if (isLoading) {
    return wrap(<PageLoadingSkeleton lines={6} />);
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
      return wrap(<ForbiddenRoute />);
    }
  }

  return wrap(children);
}
