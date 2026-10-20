import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import type * as S from '@bs/client';
import { apiClient } from '../lib/api';

/**
 * Extended Session type accommodating backend roles and optional permissions.
 */
export interface AdminSession {
  status: 'authenticated';
  user: S.User;
  csrf_token: string;
  permissions?: string[];
}

export interface SessionContextValue {
  session: AdminSession | null;
  isLoading: boolean;
  error: Error | null;
  refreshSession: () => Promise<AdminSession | null>;
  logout: () => Promise<void>;
}

const SessionContext = createContext<SessionContextValue | undefined>(undefined);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AdminSession | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  const fetchSession = async (): Promise<AdminSession | null> => {
    setIsLoading(true);
    try {
      const res = await apiClient.request<AdminSession>('GET', '/auth/session');
      if (res.ok) {
        setSession(res.data);
        if (res.data.csrf_token) {
          apiClient.setCsrfToken(res.data.csrf_token);
        }
        setError(null);
        return res.data;
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

  return (
    <SessionContext.Provider
      value={{
        session,
        isLoading,
        error,
        refreshSession: fetchSession,
        logout,
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
