import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Avatar,
  AvatarFallback,
  Badge,
  Button,
  Card,
  CardContent,
  ConfirmationModal,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  EmptyState,
  ErrorState,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  TypeToConfirmDialog,
} from '@bs/ui';
import {
  MoreHorizontal,
  Search,
  UserPlus,
  Shield,
  UserCheck,
  UserX,
  Trash2,
  Eye,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';
import { InviteUserDialog, type RoleOption } from './invite-user-dialog';
import { ChangeRoleDialog, type UserSummary } from './change-role-dialog';
import { UserDetailSheet } from './user-detail-sheet';

const StepUpDialog = lazy(() =>
  import('../auth/step-up-dialog').then((m) => ({ default: m.StepUpDialog }))
);

export interface UserRow {
  id: string;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'pending_invitation';
  two_factor_enabled: boolean;
  roles: Array<{ id: string; handle: string; name: string; is_system?: number }>;
  created_at: string;
  updated_at: string;
  last_login_at?: string | null;
}

export function UsersListView() {
  const { session } = useSession();
  const currentUserId = session?.user?.id;

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [users, setUsers] = useState<UserRow[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);

  // Search & Filters
  const [searchQuery, setSearchQuery] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  // Selected row state for Bulk Actions
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());

  // Dialogs & Sheets state
  const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
  const [changeRoleTarget, setChangeRoleTarget] = useState<UserSummary | null>(null);
  const [detailUserId, setDetailUserId] = useState<string | null>(null);

  // Single Action Modals
  const [suspendTarget, setSuspendTarget] = useState<UserRow | null>(null);
  const [unsuspendTarget, setUnsuspendTarget] = useState<UserRow | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<UserRow | null>(null);

  // Step-Up Dialog
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [pendingAction, setPendingAction] = useState<(() => void) | null>(null);

  // Status message banners
  const [statusBanner, setStatusBanner] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const loadData = async () => {
    setLoading(true);
    setError(null);
    try {
      const [usersRes, rolesRes] = await Promise.all([
        apiClient.request<{ items: UserRow[] }>('GET', '/users'),
        apiClient.request<{ items: RoleOption[] }>('GET', '/roles'),
      ]);

      if (usersRes.ok) {
        setUsers(usersRes.data.items || []);
      } else {
        setError(usersRes.problem?.detail || 'Failed to load user list.');
      }

      if (rolesRes.ok) {
        setRoles(rolesRes.data.items || []);
      }
    } catch {
      setError('A network error occurred while communicating with the server.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  // Compute active owners count
  const activeOwnersCount = useMemo(() => {
    return users.filter(
      (u) => u.status === 'active' && u.roles.some((r) => r.handle === 'owner')
    ).length;
  }, [users]);

  // Filtered users
  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      const matchesSearch =
        searchQuery === '' ||
        u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        u.email.toLowerCase().includes(searchQuery.toLowerCase());

      const matchesRole =
        roleFilter === 'all' ||
        u.roles.some((r) => r.handle === roleFilter || r.id === roleFilter);

      const matchesStatus =
        statusFilter === 'all' || u.status === statusFilter;

      return matchesSearch && matchesRole && matchesStatus;
    });
  }, [users, searchQuery, roleFilter, statusFilter]);

  // Selection handlers
  const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.checked) {
      setSelectedIds(new Set(filteredUsers.map((u) => u.id)));
    } else {
      setSelectedIds(new Set());
    }
  };

  const handleToggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };



  const handleSuspendUser = async (user: UserRow) => {
    if (user.id === currentUserId) {
      setStatusBanner({ type: 'error', message: 'You cannot suspend your own account.' });
      return;
    }
    if (user.roles.some((r) => r.handle === 'owner') && activeOwnersCount <= 1) {
      setStatusBanner({ type: 'error', message: 'The last active owner cannot be suspended.' });
      return;
    }

    try {
      const res = await apiClient.request<{ status: string }>('POST', `/users/${user.id}/suspend`);
      if (res.ok) {
        setStatusBanner({ type: 'success', message: `User ${user.email} has been suspended.` });
        setSuspendTarget(null);
        loadData();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => () => handleSuspendUser(user));
        setStepUpOpen(true);
      } else {
        setStatusBanner({ type: 'error', message: res.problem?.detail || 'Failed to suspend user.' });
      }
    } catch {
      setStatusBanner({ type: 'error', message: 'Network error while suspending user.' });
    }
  };

  const handleUnsuspendUser = async (user: UserRow) => {
    try {
      const res = await apiClient.request<{ status: string }>('POST', `/users/${user.id}/unsuspend`);
      if (res.ok) {
        setStatusBanner({ type: 'success', message: `User ${user.email} has been reactivated.` });
        setUnsuspendTarget(null);
        loadData();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => () => handleUnsuspendUser(user));
        setStepUpOpen(true);
      } else {
        setStatusBanner({ type: 'error', message: res.problem?.detail || 'Failed to reactivate user.' });
      }
    } catch {
      setStatusBanner({ type: 'error', message: 'Network error while reactivating user.' });
    }
  };

  const handleDeleteUser = async (user: UserRow) => {
    if (user.id === currentUserId) {
      setStatusBanner({ type: 'error', message: 'You cannot delete your own account.' });
      return;
    }
    if (user.roles.some((r) => r.handle === 'owner') && activeOwnersCount <= 1) {
      setStatusBanner({ type: 'error', message: 'The last active owner cannot be deleted.' });
      return;
    }

    try {
      const res = await apiClient.request<{ status: string }>('DELETE', `/users/${user.id}`);
      if (res.ok) {
        setStatusBanner({ type: 'success', message: `User ${user.email} permanently deleted.` });
        setDeleteTarget(null);
        loadData();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => () => handleDeleteUser(user));
        setStepUpOpen(true);
      } else {
        setStatusBanner({ type: 'error', message: res.problem?.detail || 'Failed to delete user.' });
      }
    } catch {
      setStatusBanner({ type: 'error', message: 'Network error while deleting user.' });
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
        <Spinner size={28} />
        <p className="text-13 text-muted-foreground">Loading workspace users...</p>
      </div>
    );
  }

  if (error) {
    return (
      <ErrorState
        title="Unable to load team members"
        message={error}
        onRetry={loadData}
      />
    );
  }

  return (
    <div className="space-y-4">
      {statusBanner ? (
        <Alert
          variant={statusBanner.type}
          role={statusBanner.type === 'error' ? 'alert' : 'status'}
          className="border-border"
        >
          <div className="flex items-center justify-between">
            <p className="text-13 font-medium">{statusBanner.message}</p>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => setStatusBanner(null)}
              className="h-6 px-2 text-xs"
            >
              Dismiss
            </Button>
          </div>
        </Alert>
      ) : null}

      {/* Action Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 flex-wrap items-center gap-2">
          {/* Search box */}
          <div className="relative min-w-[220px] max-w-xs flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              id="users-search-input"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search by name or email..."
              className="pl-8 text-13"
            />
          </div>

          {/* Role Filter */}
          <Select value={roleFilter} onValueChange={setRoleFilter}>
            <SelectTrigger id="users-role-filter" className="w-[140px] text-13">
              <SelectValue placeholder="All Roles" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Roles</SelectItem>
              {roles.map((r) => (
                <SelectItem key={r.id} value={r.handle}>
                  {r.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* Status Filter */}
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger id="users-status-filter" className="w-[140px] text-13">
              <SelectValue placeholder="All Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="suspended">Suspended</SelectItem>
              <SelectItem value="pending_invitation">Invited</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Button
          id="invite-user-button"
          onClick={() => setInviteDialogOpen(true)}
          className="gap-2 shrink-0"
        >
          <UserPlus className="h-4 w-4" />
          <span>Invite User</span>
        </Button>
      </div>

      {/* Users Table */}
      {filteredUsers.length === 0 ? (
        <Card>
          <CardContent className="py-12">
            <EmptyState
              title="No users match your criteria"
              description="Try adjusting your search query, role filter, or status selection."
              action={
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setSearchQuery('');
                    setRoleFilter('all');
                    setStatusFilter('all');
                  }}
                >
                  Clear Filters
                </Button>
              }
            />
          </CardContent>
        </Card>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">
          <table className="w-full text-left text-13" data-testid="users-table">
            <thead className="border-b border-border bg-muted/40 text-12 font-medium text-muted-foreground">
              <tr>
                <th className="w-10 px-3 py-3">
                  <input
                    type="checkbox"
                    aria-label="Select all users"
                    checked={
                      filteredUsers.length > 0 &&
                      filteredUsers.every((u) => selectedIds.has(u.id))
                    }
                    onChange={handleSelectAll}
                    className="h-4 w-4 rounded border-border text-primary cursor-pointer"
                  />
                </th>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Role</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">2FA</th>
                <th className="px-4 py-3">Last Login</th>
                <th className="w-16 px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {filteredUsers.map((u) => {
                const isSelected = selectedIds.has(u.id);
                const isSelf = u.id === currentUserId;
                const isOwner = u.roles.some((r) => r.handle === 'owner');

                return (
                  <tr
                    key={u.id}
                    className={`transition-colors hover:bg-muted/30 ${
                      isSelected ? 'bg-muted/20' : ''
                    }`}
                    data-testid={`user-row-${u.id}`}
                  >
                    <td className="px-3 py-3">
                      <input
                        type="checkbox"
                        aria-label={`Select user ${u.name || u.email}`}
                        checked={isSelected}
                        onChange={() => handleToggleSelect(u.id)}
                        className="h-4 w-4 rounded border-border text-primary cursor-pointer"
                      />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <Avatar className="h-8 w-8">
                          <AvatarFallback name={u.name || u.email} />
                        </Avatar>
                        <div>
                          <div className="flex items-center gap-1.5 font-medium text-foreground">
                            <span>{u.name || 'Unnamed'}</span>
                            {isSelf ? (
                              <Badge variant="outline" className="text-[10px] px-1.5 py-0 h-4">
                                You
                              </Badge>
                            ) : null}
                          </div>
                          <span className="text-12 text-muted-foreground break-all">{u.email}</span>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {u.roles.map((r) => (
                          <Badge
                            key={r.id}
                            variant={r.handle === 'owner' ? 'default' : 'secondary'}
                            className="text-11"
                          >
                            {r.name}
                          </Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge
                        variant={
                          u.status === 'active'
                            ? 'default'
                            : u.status === 'suspended'
                            ? 'destructive'
                            : 'secondary'
                        }
                        className="capitalize"
                      >
                        {u.status === 'pending_invitation' ? 'Invited' : u.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant="outline" className="text-11">
                        {u.two_factor_enabled ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 font-mono text-12 text-muted-foreground">
                      {u.last_login_at ? u.last_login_at.slice(0, 10) : 'Never'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 w-8 p-0"
                            aria-label={`Open menu for ${u.email}`}
                          >
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                          <DropdownMenuItem onClick={() => setDetailUserId(u.id)}>
                            <Eye className="mr-2 h-4 w-4" />
                            <span>View Details</span>
                          </DropdownMenuItem>

                          <DropdownMenuItem
                            onClick={() =>
                              setChangeRoleTarget({
                                id: u.id,
                                name: u.name,
                                email: u.email,
                                roles: u.roles,
                              })
                            }
                          >
                            <Shield className="mr-2 h-4 w-4" />
                            <span>Change Role</span>
                          </DropdownMenuItem>

                          <DropdownMenuSeparator />

                          {u.status === 'suspended' ? (
                            <DropdownMenuItem onClick={() => setUnsuspendTarget(u)}>
                              <UserCheck className="mr-2 h-4 w-4 text-success" />
                              <span>Reactivate</span>
                            </DropdownMenuItem>
                          ) : (
                            <DropdownMenuItem
                              onClick={() => setSuspendTarget(u)}
                              disabled={isSelf || (isOwner && activeOwnersCount <= 1)}
                              className="text-destructive"
                            >
                              <UserX className="mr-2 h-4 w-4" />
                              <span>Suspend User</span>
                            </DropdownMenuItem>
                          )}

                          <DropdownMenuItem
                            onClick={() => setDeleteTarget(u)}
                            disabled={isSelf || (isOwner && activeOwnersCount <= 1)}
                            className="text-destructive"
                          >
                            <Trash2 className="mr-2 h-4 w-4" />
                            <span>Delete User</span>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Invite User Dialog */}
      <InviteUserDialog
        open={inviteDialogOpen}
        onOpenChange={setInviteDialogOpen}
        roles={roles}
        onSuccess={loadData}
      />

      {/* Change Role Dialog */}
      <ChangeRoleDialog
        open={Boolean(changeRoleTarget)}
        onOpenChange={(open) => !open && setChangeRoleTarget(null)}
        user={changeRoleTarget}
        roles={roles}
        activeOwnerCount={activeOwnersCount}
        onSuccess={() => {
          setStatusBanner({ type: 'success', message: 'User role updated successfully.' });
          loadData();
        }}
        onRequireStepUp={() => {
          setPendingAction(() => () => setChangeRoleTarget(changeRoleTarget));
          setStepUpOpen(true);
        }}
      />

      {/* User Details Slide-over Sheet */}
      <UserDetailSheet
        open={Boolean(detailUserId)}
        onOpenChange={(open) => !open && setDetailUserId(null)}
        userId={detailUserId}
        onChangeRole={(summary) => {
          setDetailUserId(null);
          setChangeRoleTarget(summary);
        }}
        onUserUpdated={loadData}
        onRequireStepUp={() => {
          setStepUpOpen(true);
        }}
      />

      {/* Suspend Confirmation Modal */}
      <ConfirmationModal
        open={Boolean(suspendTarget)}
        onOpenChange={(open) => !open && setSuspendTarget(null)}
        title="Suspend User Account"
        body={`Are you sure you want to suspend "${suspendTarget?.email}"? This will terminate all active sessions and block login access.`}
        confirmLabel="Suspend User"
        destructive
        onConfirm={() => {
          if (suspendTarget) {
            return handleSuspendUser(suspendTarget);
          }
        }}
      />

      {/* Reactivate Confirmation Modal */}
      <ConfirmationModal
        open={Boolean(unsuspendTarget)}
        onOpenChange={(open) => !open && setUnsuspendTarget(null)}
        title="Reactivate User Account"
        body={`Reactivate access for "${unsuspendTarget?.email}"? The user will be permitted to log in with their existing credentials.`}
        confirmLabel="Reactivate User"
        onConfirm={() => {
          if (unsuspendTarget) {
            return handleUnsuspendUser(unsuspendTarget);
          }
        }}
      />

      {/* Type to Confirm Delete Dialog */}
      {deleteTarget ? (
        <TypeToConfirmDialog
          open={Boolean(deleteTarget)}
          onOpenChange={(open) => !open && setDeleteTarget(null)}
          title="Permanently Delete User"
          body={`This action is irreversible. All access, sessions, and credentials for "${deleteTarget.email}" will be destroyed.`}
          phrase={deleteTarget.email}
          confirmLabel="Permanently Delete"
          onConfirm={() => handleDeleteUser(deleteTarget)}
        />
      ) : null}

      {/* Step-Up Re-Authentication Dialog */}
      {stepUpOpen ? (
        <Suspense fallback={null}>
          <StepUpDialog
            open={stepUpOpen}
            onOpenChange={setStepUpOpen}
            title="Security Step-Up Required"
            description="Authorizing user access modifications requires confirming your credentials."
            onSuccess={() => {
              if (pendingAction) {
                const act = pendingAction;
                setPendingAction(null);
                act();
              }
            }}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
