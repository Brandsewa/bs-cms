import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import type * as S from '@bs/client';
import { apiClient } from '../lib/api';

export interface UserRole {
  id: string;
  handle: string;
  name: string;
  description?: string | null;
  is_system?: number | boolean;
  is_ai?: number | boolean;
  created_at?: string;
  updated_at?: string;
}

export interface PermissionRule {
  id: string;
  role_id: string;
  subject_type: string;
  subject_key: string;
  action: string;
  effect: 'allow' | 'deny';
  fields?: string[] | null;
  conditions?: Record<string, unknown> | null;
}

export interface MePermissionsResponse {
  actor_id: string;
  role_ids: string[];
  permissions: PermissionRule[];
}

/**
 * Normalized user session type.
 */
export interface AdminUser extends Omit<S.User, 'roles'> {
  roles: Array<string | UserRole>;
}

export interface AdminSession {
  status: 'authenticated';
  user: AdminUser;
  csrf_token: string;
  /** Role handles: ['owner', 'admin', etc.] */
  roleHandles: string[];
  /** Compiled/effective permission rules from GET /api/v1/me/permissions */
  permissions: PermissionRule[];
}

export interface SessionContextValue {
  session: AdminSession | null;
  isLoading: boolean;
  error: Error | null;
  refreshSession: () => Promise<AdminSession | null>;
  logout: () => Promise<void>;
  hasPermission: (subject: string, action?: string) => boolean;
}

const SessionContext = createContext<SessionContextValue | undefined>(undefined);

export function checkPermission(
  session: AdminSession | null,
  subject: string,
  action: string = 'read'
): boolean {
  if (!session || session.status !== 'authenticated') {
    return false;
  }

  // Owner always bypasses all checks
  if (session.roleHandles.includes('owner')) {
    return true;
  }

  // Evaluate permission rules.
  // Deny rules take precedence if explicitly matched.
  let allowed = false;

  for (const rule of session.permissions) {
    const subjectMatches =
      rule.subject_type === '*' ||
      rule.subject_type === subject ||
      (subject === 'collection' && rule.subject_type === 'collection') ||
      (subject === 'taxonomy' && (rule.subject_type === 'taxonomy' || rule.subject_type === 'term')) ||
      (subject === 'settings' && (rule.subject_type === 'settings' || rule.subject_type === 'schema'));

    const actionMatches =
      rule.action === '*' ||
      rule.action === action ||
      (action === 'read' && rule.action === 'read') ||
      (action === 'manage' && (rule.action === '*' || rule.action === 'update' || rule.action === 'create'));

    if (subjectMatches && actionMatches) {
      if (rule.effect === 'deny') {
        return false;
      }
      if (rule.effect === 'allow') {
        allowed = true;
      }
    }
  }

  return allowed;
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AdminSession | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  // isLoading starts true; later refreshes update the session in place so mounted screens keep their local state.
  const fetchSession = async (): Promise<AdminSession | null> => {
    try {
      const res = await apiClient.request<{
        status: 'authenticated';
        user: AdminUser;
        csrf_token: string;
      }>('GET', '/auth/session');

      if (res.ok && res.data?.status === 'authenticated') {
        const sessionData = res.data;
        if (sessionData.csrf_token) {
          apiClient.setCsrfToken(sessionData.csrf_token);
        }

        // Derive role handles from string or object roles
        const rawRoles = sessionData.user?.roles ?? [];
        const roleHandles: string[] = rawRoles.map((r) =>
          typeof r === 'string' ? r : r.handle
        );

        // Fetch effective permissions from /me/permissions
        let permissions: PermissionRule[] = [];
        try {
          const permRes = await apiClient.request<MePermissionsResponse>(
            'GET',
            '/me/permissions'
          );
          if (permRes.ok && permRes.data?.permissions) {
            permissions = permRes.data.permissions;
          }
        } catch {
          // If /me/permissions is unavailable, fallback to empty permissions
          permissions = [];
        }

        const fullSession: AdminSession = {
          ...sessionData,
          roleHandles,
          permissions,
        };

        setSession(fullSession);
        setError(null);
        return fullSession;
      } else {
        setSession(null);
        apiClient.setCsrfToken(undefined);
        return null;
      }
    } catch (err) {
      setError(err instanceof Error ? err : new Error(String(err)));
      setSession(null);
      return null;
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    void fetchSession();
  }, []);

  const logout = async () => {
    try {
      await apiClient.request('POST', '/auth/logout');
    } finally {
      setSession(null);
      apiClient.setCsrfToken(undefined);
      window.location.href = '/admin/login';
    }
  };

  const hasPermission = (subject: string, action: string = 'read'): boolean => {
    return checkPermission(session, subject, action);
  };

  return (
    <SessionContext.Provider
      value={{
        session,
        isLoading,
        error,
        refreshSession: fetchSession,
        logout,
        hasPermission,
      }}
    >
      {children}
    </SessionContext.Provider>
  );
}

export function useSession() {
  const ctx = useContext(SessionContext);
  if (!ctx) {
    throw new Error('useSession must be used within a SessionProvider');
  }
  return ctx;
}
