import type { ReactNode } from 'react';
import { useSession } from '../auth/session-context';
import { ForbiddenRoute } from './forbidden';

/**
 * Per-route permission gate for routes rendered INSIDE the persistent shell
 * (the shell layout owns authentication and the sidebar/topbar chrome, so
 * navigating between gated routes no longer remounts them).
 *
 * - `permission` omitted: authenticated users only.
 * - `permission` set: denied users get the forbidden state (the shell stays
 *   around it, matching the pre-shell ProtectedAdmin behaviour).
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission?: string;
  children: ReactNode;
}) {
  const { session, hasPermission } = useSession();

  if (!session || session.status !== 'authenticated') {
    if (typeof window !== 'undefined') {
      const current = window.location.pathname + window.location.search;
      window.location.href = `/admin/login?return_to=${encodeURIComponent(current)}`;
    }
    return null;
  }

  if (permission) {
    const roleHandles = session.roleHandles ?? [];
    const isOwner = roleHandles.includes('owner') || roleHandles.includes('admin');

    if (!isOwner) {
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
        return <ForbiddenRoute />;
      }
    }
  }

  return <>{children}</>;
}
