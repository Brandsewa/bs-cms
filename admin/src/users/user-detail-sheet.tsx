import { useEffect, useState } from 'react';
import {
  Alert,
  Avatar,
  AvatarFallback,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  ConfirmationModal,
  ErrorState,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Spinner,
} from '@bs/ui';
import {
  Smartphone,
  Globe,
  Clock,
  Calendar,
  CheckCircle,
  XCircle,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import type { UserSummary } from './change-role-dialog';

export interface UserSessionItem {
  id: string;
  ip_address?: string;
  user_agent?: string;
  last_activity_at?: string;
  created_at: string;
  is_current?: boolean;
}

export interface UserDetailData {
  id: string;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'pending_invitation';
  locale?: string;
  timezone?: string;
  two_factor_enabled: boolean;
  roles: Array<{ id: string; handle: string; name: string; description?: string }>;
  created_at: string;
  updated_at: string;
  last_login_at?: string | null;
}

export interface UserDetailSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  userId: string | null;
  onChangeRole: (user: UserSummary) => void;
  onUserUpdated: () => void;
  onRequireStepUp?: (action: string) => void;
}

export function UserDetailSheet({
  open,
  onOpenChange,
  userId,
  onChangeRole,
  onUserUpdated,
  onRequireStepUp,
}: UserDetailSheetProps) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [user, setUser] = useState<UserDetailData | null>(null);
  const [sessions, setSessions] = useState<UserSessionItem[]>([]);
  const [sessionsLoading, setSessionsLoading] = useState(false);
  const [revokingSessions, setRevokingSessions] = useState(false);
  const [resetting2FA, setResetting2FA] = useState(false);
  const [reset2FAConfirmOpen, setReset2FAConfirmOpen] = useState(false);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const loadUserDetails = async (id: string) => {
    setLoading(true);
    setError(null);

    try {
      const res = await apiClient.request<UserDetailData>('GET', `/users/${id}`);
      if (res.ok) {
        setUser(res.data);
      } else {
        setError(res.problem?.detail || 'Failed to load user details.');
      }
    } catch {
      setError('A network error occurred while loading user details.');
    } finally {
      setLoading(false);
    }
  };

  const loadSessions = async (id: string) => {
    setSessionsLoading(true);
    try {
      const res = await apiClient.request<{ items: UserSessionItem[] }>('GET', `/users/${id}/sessions`);
      if (res.ok) {
        setSessions(res.data.items || []);
      }
    } catch {
      // Sessions optional or empty
    } finally {
      setSessionsLoading(false);
    }
  };

  useEffect(() => {
    if (open && userId) {
      setActionSuccess(null);
      setActionError(null);
      loadUserDetails(userId);
      loadSessions(userId);
    } else {
      setUser(null);
      setSessions([]);
      setError(null);
      setActionSuccess(null);
      setActionError(null);
    }
  }, [open, userId]);

  const handleRevokeSessions = async () => {
    if (!userId) return;
    setRevokingSessions(true);
    setActionSuccess(null);
    setActionError(null);

    try {
      const res = await apiClient.request<{ status: string }>('DELETE', `/users/${userId}/sessions`);
      if (res.ok) {
        setActionSuccess('All active sessions for this user have been terminated.');
        loadSessions(userId);
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        onRequireStepUp?.('revoke_sessions');
      } else {
        setActionError(res.problem?.detail || 'Failed to revoke sessions.');
      }
    } catch {
      setActionError('Network error while revoking sessions.');
    } finally {
      setRevokingSessions(false);
    }
  };

  const handleReset2FA = async () => {
    if (!userId) return;
    setResetting2FA(true);
    setActionSuccess(null);
    setActionError(null);

    try {
      // Reset 2FA via user update or unlock endpoint
      const res = await apiClient.request<{ status: string }>('POST', `/users/${userId}/unlock`);
      if (res.ok) {
        setActionSuccess('Two-Factor Authentication requirement reset successfully.');
        setReset2FAConfirmOpen(false);
        loadUserDetails(userId);
        onUserUpdated();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setReset2FAConfirmOpen(false);
        onRequireStepUp?.('reset_2fa');
      } else {
        setActionError(res.problem?.detail || 'Failed to reset 2FA.');
      }
    } catch {
      setActionError('Network error while resetting 2FA.');
    } finally {
      setResetting2FA(false);
    }
  };

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full sm:max-w-xl overflow-y-auto">
        <SheetHeader className="border-b border-border pb-4">
          <SheetTitle>User Details</SheetTitle>
          <SheetDescription>
            Account configuration, assigned roles, security status, and active sessions.
          </SheetDescription>
        </SheetHeader>

        {loading ? (
          <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
            <Spinner size={24} />
            <p className="text-13 text-muted-foreground">Loading user profile...</p>
          </div>
        ) : error ? (
          <div className="py-6">
            <ErrorState
              title="Unable to load user"
              message={error}
              onRetry={() => userId && loadUserDetails(userId)}
            />
          </div>
        ) : user ? (
          <div className="space-y-6 py-4">
            {actionSuccess ? (
              <Alert variant="success" role="status" className="border-border">
                <p className="text-13 text-foreground">{actionSuccess}</p>
              </Alert>
            ) : null}

            {actionError ? (
              <Alert variant="error" role="alert">
                <p className="text-13">{actionError}</p>
              </Alert>
            ) : null}

            {/* Profile Overview Card */}
            <div className="flex items-start gap-4 rounded-lg border border-border bg-card p-4">
              <Avatar className="h-12 w-12 text-base">
                <AvatarFallback name={user.name || user.email} />
              </Avatar>
              <div className="flex-1 space-y-1">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h3 className="text-base font-semibold text-foreground">{user.name || 'Unnamed User'}</h3>
                  <Badge
                    variant={
                      user.status === 'active'
                        ? 'default'
                        : user.status === 'suspended'
                        ? 'destructive'
                        : 'secondary'
                    }
                  >
                    {user.status === 'active'
                      ? 'Active'
                      : user.status === 'suspended'
                      ? 'Suspended'
                      : 'Invited'}
                  </Badge>
                </div>
                <p className="text-13 text-muted-foreground break-all">{user.email}</p>
                <p className="text-11 font-mono text-muted-foreground pt-1">ID: {user.id}</p>
              </div>
            </div>

            {/* Role Assignments */}
            <Card>
              <CardHeader className="flex flex-row items-center justify-between py-3">
                <div>
                  <CardTitle className="text-sm">Assigned Roles</CardTitle>
                  <CardDescription className="text-12">
                    Controls privileges across content and admin capabilities.
                  </CardDescription>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => onChangeRole(user)}
                >
                  Change Role
                </Button>
              </CardHeader>
              <CardContent className="space-y-2 pt-0">
                <div className="flex flex-wrap gap-2">
                  {user.roles && user.roles.length > 0 ? (
                    user.roles.map((r) => (
                      <Badge key={r.id} variant="secondary" className="px-2.5 py-1">
                        <span className="font-medium">{r.name}</span>
                        {r.handle === 'owner' ? (
                          <span className="ml-1 text-xs text-primary font-bold">★</span>
                        ) : null}
                      </Badge>
                    ))
                  ) : (
                    <span className="text-13 text-muted-foreground">No roles assigned.</span>
                  )}
                </div>
              </CardContent>
            </Card>

            {/* Security & 2FA Card */}
            <Card>
              <CardHeader className="py-3">
                <CardTitle className="text-sm">Security & Two-Factor Authentication</CardTitle>
                <CardDescription className="text-12">
                  Authentication status and emergency access controls.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 pt-0">
                <div className="flex items-center justify-between rounded-md border border-border p-3">
                  <div className="flex items-center gap-3">
                    <Smartphone className="h-5 w-5 text-muted-foreground" />
                    <div>
                      <p className="text-13 font-medium text-foreground">Two-Factor Authentication (2FA)</p>
                      <p className="text-12 text-muted-foreground">
                        {user.two_factor_enabled
                          ? 'TOTP authenticator app is configured and active.'
                          : 'Two-factor authentication is not currently enabled for this user.'}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {user.two_factor_enabled ? (
                      <span className="flex items-center gap-1 text-12 font-medium text-success">
                        <CheckCircle className="h-4 w-4" />
                        Enabled
                      </span>
                    ) : (
                      <span className="flex items-center gap-1 text-12 font-medium text-muted-foreground">
                        <XCircle className="h-4 w-4" />
                        Disabled
                      </span>
                    )}
                  </div>
                </div>

                {user.two_factor_enabled ? (
                  <div className="flex justify-end pt-1">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setReset2FAConfirmOpen(true)}
                    >
                      Reset 2FA Token
                    </Button>
                  </div>
                ) : null}
              </CardContent>
            </Card>

            {/* Account Metadata Card */}
            <Card>
              <CardHeader className="py-3">
                <CardTitle className="text-sm">Account History</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-13 pt-0">
                <div className="flex justify-between py-1 border-b border-border/50">
                  <span className="text-muted-foreground flex items-center gap-1.5">
                    <Calendar className="h-3.5 w-3.5" /> Created At
                  </span>
                  <span className="text-foreground font-mono text-12">{user.created_at}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-border/50">
                  <span className="text-muted-foreground flex items-center gap-1.5">
                    <Clock className="h-3.5 w-3.5" /> Last Login
                  </span>
                  <span className="text-foreground font-mono text-12">
                    {user.last_login_at || 'Never logged in'}
                  </span>
                </div>
                <div className="flex justify-between py-1">
                  <span className="text-muted-foreground flex items-center gap-1.5">
                    <Globe className="h-3.5 w-3.5" /> Timezone / Locale
                  </span>
                  <span className="text-foreground text-12">
                    {user.timezone || 'UTC'} ({user.locale || 'en'})
                  </span>
                </div>
              </CardContent>
            </Card>

            {/* Active Sessions Card */}
            <Card>
              <CardHeader className="flex flex-row items-center justify-between py-3">
                <div>
                  <CardTitle className="text-sm">Active Sessions</CardTitle>
                  <CardDescription className="text-12">
                    Currently authenticated browser and API sessions.
                  </CardDescription>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={handleRevokeSessions}
                  disabled={revokingSessions || sessionsLoading || sessions.length === 0}
                >
                  {revokingSessions ? (
                    <span className="flex items-center gap-1.5">
                      <Spinner size={12} />
                      Revoking...
                    </span>
                  ) : (
                    'Revoke All Sessions'
                  )}
                </Button>
              </CardHeader>
              <CardContent className="pt-0">
                {sessionsLoading ? (
                  <div className="flex items-center justify-center py-4">
                    <Spinner size={16} />
                  </div>
                ) : sessions.length > 0 ? (
                  <div className="space-y-2">
                    {sessions.map((s) => (
                      <div
                        key={s.id}
                        className="flex items-center justify-between rounded-md border border-border p-2.5 text-12"
                      >
                        <div className="space-y-0.5">
                          <p className="font-mono text-foreground font-medium">
                            {s.ip_address || '127.0.0.1'}
                            {s.is_current ? (
                              <Badge variant="outline" className="ml-2 text-[10px]">
                                Current Session
                              </Badge>
                            ) : null}
                          </p>
                          <p className="text-muted-foreground truncate max-w-xs">
                            {s.user_agent || 'Unknown Client'}
                          </p>
                        </div>
                        <div className="text-right text-muted-foreground font-mono text-11">
                          {s.last_activity_at || s.created_at}
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-12 text-muted-foreground py-2 text-center">
                    No active sessions recorded for this user.
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
        ) : null}

        {/* Reset 2FA Confirmation Modal */}
        <ConfirmationModal
          open={reset2FAConfirmOpen}
          onOpenChange={setReset2FAConfirmOpen}
          title="Reset Two-Factor Authentication"
          body={`Are you sure you want to reset 2FA for "${user?.email}"? The user will be required to configure a new authenticator upon their next login.`}
          confirmLabel="Reset 2FA"
          destructive
          loading={resetting2FA}
          onConfirm={handleReset2FA}
        />
      </SheetContent>
    </Sheet>
  );
}
