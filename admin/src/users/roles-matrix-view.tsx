import { lazy, Suspense, useEffect, useState } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  ConfirmationModal,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  ErrorState,
  FormItemLayout,
  Input,
  Spinner,
  Textarea,
} from '@bs/ui';
import {
  ShieldAlert,
  Plus,
  Copy,
  Trash2,
  X,
  Eye,
  Sliders,
} from 'lucide-react';
import { apiClient } from '../lib/api';

const StepUpDialog = lazy(() =>
  import('../auth/step-up-dialog').then((m) => ({ default: m.StepUpDialog }))
);

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

export interface RoleDetail {
  id: string;
  handle: string;
  name: string;
  description?: string;
  is_system: number;
  is_ai: number;
  created_at: string;
  updated_at: string;
  permissions?: PermissionRule[];
}

const CANONICAL_SUBJECTS = [
  { key: 'collection', label: 'Collections & Content' },
  { key: 'taxonomy', label: 'Taxonomies' },
  { key: 'term', label: 'Taxonomy Terms' },
  { key: 'media', label: 'Media Library' },
  { key: 'site', label: 'Site Navigation & Menus' },
  { key: 'redirects', label: 'URL Redirects' },
  { key: 'users', label: 'Users & Access' },
  { key: 'settings', label: 'Settings & Backups' },
  { key: 'ai', label: 'AI Studio & Automations' },
];

export function RolesMatrixView() {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [roles, setRoles] = useState<RoleDetail[]>([]);

  // Dialogs
  const [createDialogOpen, setCreateDialogOpen] = useState(false);
  const [cloneTarget, setCloneTarget] = useState<RoleDetail | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<RoleDetail | null>(null);

  // Form states
  const [newRoleName, setNewRoleName] = useState('');
  const [newRoleHandle, setNewRoleHandle] = useState('');
  const [newRoleDesc, setNewRoleDesc] = useState('');
  const [savingRole, setSavingRole] = useState(false);
  const [roleFormError, setRoleFormError] = useState<string | null>(null);

  // Step-Up
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [pendingAction, setPendingAction] = useState<(() => void) | null>(null);

  // Status Banner
  const [banner, setBanner] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // SPRINT-A: Preview as role & Custom role editor modal
  const [previewRole, setPreviewRole] = useState<RoleDetail | null>(null);
  const [editingRole, setEditingRole] = useState<RoleDetail | null>(null);
  const [editedPermissions, setEditedPermissions] = useState<Record<string, { effect: 'allow' | 'deny' | 'inherit'; own_only?: boolean }>>({});
  const [savingPermissions, setSavingPermissions] = useState(false);

  const loadRoles = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiClient.request<{ items: RoleDetail[] }>('GET', '/roles');
      if (res.ok) {
        setRoles(res.data.items || []);
      } else {
        setError(res.problem?.detail || 'Failed to load roles and permissions.');
      }
    } catch {
      setError('A network error occurred while loading roles.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadRoles();
  }, []);

  const handleCreateRole = async () => {
    if (!newRoleName.trim() || !newRoleHandle.trim()) {
      setRoleFormError('Name and handle are required.');
      return;
    }

    setSavingRole(true);
    setRoleFormError(null);

    try {
      const res = await apiClient.request<{ status: string; id: string }>('POST', '/roles', {
        body: {
          name: newRoleName.trim(),
          handle: newRoleHandle.trim().toLowerCase(),
          description: newRoleDesc.trim() || null,
          permissions: [],
        },
      });

      if (res.ok) {
        setBanner({ type: 'success', message: `Custom role "${newRoleName}" created successfully.` });
        setCreateDialogOpen(false);
        setNewRoleName('');
        setNewRoleHandle('');
        setNewRoleDesc('');
        loadRoles();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => handleCreateRole);
        setStepUpOpen(true);
      } else {
        setRoleFormError(res.problem?.detail || 'Failed to create role.');
      }
    } catch {
      setRoleFormError('Network error while creating role.');
    } finally {
      setSavingRole(false);
    }
  };

  const handleCloneRole = async () => {
    if (!cloneTarget || !newRoleName.trim() || !newRoleHandle.trim()) {
      setRoleFormError('Name and handle are required.');
      return;
    }

    setSavingRole(true);
    setRoleFormError(null);

    try {
      const res = await apiClient.request<{ status: string; id: string }>(
        'POST',
        `/roles/${cloneTarget.id}/clone`,
        {
          body: {
            name: newRoleName.trim(),
            handle: newRoleHandle.trim().toLowerCase(),
            description: newRoleDesc.trim() || null,
          },
        }
      );

      if (res.ok) {
        setBanner({ type: 'success', message: `Role "${newRoleName}" cloned successfully.` });
        setCloneTarget(null);
        setNewRoleName('');
        setNewRoleHandle('');
        setNewRoleDesc('');
        loadRoles();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => handleCloneRole);
        setStepUpOpen(true);
      } else {
        setRoleFormError(res.problem?.detail || 'Failed to clone role.');
      }
    } catch {
      setRoleFormError('Network error while cloning role.');
    } finally {
      setSavingRole(false);
    }
  };

  const handleDeleteRole = async (role: RoleDetail) => {
    if (role.is_system) {
      setBanner({ type: 'error', message: 'System roles cannot be deleted.' });
      return;
    }

    try {
      const res = await apiClient.request<{ status: string }>('DELETE', `/roles/${role.id}`);
      if (res.ok) {
        setBanner({ type: 'success', message: `Role "${role.name}" deleted successfully.` });
        setDeleteTarget(null);
        loadRoles();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setPendingAction(() => () => handleDeleteRole(role));
        setStepUpOpen(true);
      } else {
        setBanner({ type: 'error', message: res.problem?.detail || 'Failed to delete role.' });
      }
    } catch {
      setBanner({ type: 'error', message: 'Network error while deleting role.' });
    }
  };

  // Helper to determine permissions for a role & subject
  const getSubjectPermissions = (role: RoleDetail, subjectKey: string) => {
    if (role.handle === 'owner') {
      return ['All (*)'];
    }

    const perms = role.permissions || [];
    const matched = perms.filter(
      (p) =>
        (p.subject_type === subjectKey || p.subject_type === '*') &&
        p.effect === 'allow'
    );

    if (matched.length === 0) return [];
    if (matched.some((p) => p.action === '*')) return ['All (*)'];
    return Array.from(new Set(matched.map((p) => p.action)));
  };

  if (loading) {
    return (
      <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
        <Spinner size={28} />
        <p className="text-13 text-muted-foreground">Loading roles & permissions...</p>
      </div>
    );
  }

  if (error) {
    return (
      <ErrorState
        title="Unable to load roles"
        message={error}
        onRetry={loadRoles}
      />
    );
  }

  return (
    <div className="space-y-6">
      {banner ? (
        <Alert
          variant={banner.type}
          role={banner.type === 'error' ? 'alert' : 'status'}
          className="border-border"
        >
          <div className="flex items-center justify-between">
            <p className="text-13 font-medium">{banner.message}</p>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => setBanner(null)}
              className="h-6 px-2 text-xs"
            >
              Dismiss
            </Button>
          </div>
        </Alert>
      ) : null}

      {/* Header and Controls */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-base font-semibold text-foreground">Role Hierarchy & Permission Matrix</h2>
          <p className="text-13 text-muted-foreground">
            System roles enforce non-negotiable security boundaries. Custom roles inherit granular privileges.
          </p>
        </div>

        <Button
          id="create-role-button"
          onClick={() => {
            setNewRoleName('');
            setNewRoleHandle('');
            setNewRoleDesc('');
            setRoleFormError(null);
            setCreateDialogOpen(true);
          }}
          className="gap-2 shrink-0"
        >
          <Plus className="h-4 w-4" />
          <span>Create Custom Role</span>
        </Button>
      </div>

      {/* Owner Protection Callout */}
      <Card className="border-primary/30 bg-primary/5">
        <CardContent className="p-4 flex items-start gap-3">
          <ShieldAlert className="h-5 w-5 shrink-0 text-primary mt-0.5" />
          <div className="space-y-1">
            <p className="text-13 font-medium text-foreground">
              Owner Permissions Are Immutable
            </p>
            <p className="text-12 text-muted-foreground">
              The <strong>Owner</strong> role retains universal wildcard authorization (<code className="text-xs bg-muted px-1 py-0.5 rounded">* on *</code>) across all system resources and security layers. It cannot be altered, restricted, or deleted.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Role Overview Cards */}
      <div className="space-y-3">
        <h3 className="text-sm font-semibold text-foreground">Role Definitions</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {roles.map((r) => {
            const isOwner = r.handle === 'owner';
            const isSystem = Boolean(r.is_system);

            return (
              <Card key={r.id} className="flex flex-col justify-between">
                <CardHeader className="pb-3">
                  <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-sm font-semibold">{r.name}</CardTitle>
                    <Badge variant={isOwner ? 'default' : isSystem ? 'secondary' : 'outline'}>
                      {isOwner ? 'Owner (Immutable)' : isSystem ? 'System Role' : 'Custom Role'}
                    </Badge>
                  </div>
                  <CardDescription className="text-12 line-clamp-2 mt-1">
                    {r.description || 'No description provided.'}
                  </CardDescription>
                </CardHeader>
                <CardContent className="pt-0 flex items-center justify-between border-t border-border/50 py-3 text-12">
                  <span className="font-mono text-muted-foreground text-11">handle: {r.handle}</span>
                  <div className="flex items-center gap-1.5">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setPreviewRole(r)}
                      className="h-7 px-2 text-11 gap-1"
                      title={`Preview as ${r.name}`}
                    >
                      <Eye className="h-3 w-3" />
                      <span>Preview</span>
                    </Button>

                    {!isSystem && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          setEditingRole(r);
                          const initial: Record<string, { effect: 'allow' | 'deny' | 'inherit'; own_only?: boolean }> = {};
                          CANONICAL_SUBJECTS.forEach((sub) => {
                            const subPerms = (r.permissions || []).filter((p) => p.subject_type === sub.key);
                            const hasAllow = subPerms.some((p) => p.effect === 'allow');
                            const hasDeny = subPerms.some((p) => p.effect === 'deny');
                            const ownOnly = subPerms.some((p) => p.conditions && Object.keys(p.conditions).length > 0);
                            initial[sub.key] = {
                              effect: hasDeny ? 'deny' : hasAllow ? 'allow' : 'inherit',
                              own_only: ownOnly,
                            };
                          });
                          setEditedPermissions(initial);
                        }}
                        className="h-7 px-2 text-11 gap-1 text-primary"
                        title={`Edit permissions for ${r.name}`}
                      >
                        <Sliders className="h-3 w-3" />
                        <span>Edit</span>
                      </Button>
                    )}

                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setCloneTarget(r);
                        setNewRoleName(`${r.name} Copy`);
                        setNewRoleHandle(`${r.handle}_copy`);
                        setNewRoleDesc(r.description || '');
                        setRoleFormError(null);
                      }}
                      className="h-7 px-2 text-11 gap-1"
                      title={`Clone ${r.name}`}
                    >
                      <Copy className="h-3 w-3" />
                      <span>Clone</span>
                    </Button>

                    {!isSystem ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setDeleteTarget(r)}
                        className="h-7 px-2 text-11 text-destructive hover:text-destructive"
                        title={`Delete ${r.name}`}
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    ) : null}
                  </div>
                </CardContent>
              </Card>
            );
          })}
        </div>
      </div>

      {/* Permissions Matrix Table */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-sm">Granular Access Matrix</CardTitle>
          <CardDescription className="text-12">
            Active permissions mapped across canonical resources and capabilities.
          </CardDescription>
        </CardHeader>
        <CardContent className="pt-0 overflow-x-auto">
          <table className="w-full text-left text-12 border-collapse" data-testid="roles-matrix-table">
            <thead>
              <tr className="border-b border-border bg-surface-200 font-medium text-foreground-lighter uppercase tracking-wider text-[11px]">
                <th className="sticky left-0 z-10 bg-card px-3 py-2 min-w-[170px] shadow-[1px_0_0_0_var(--border)]">
                  Subject / Area
                </th>
                {roles.map((r) => (
                  <th key={r.id} className="px-3 py-2 min-w-[120px]">
                    <div className="font-medium text-foreground">{r.name}</div>
                    <div className="text-[10px] font-mono text-muted-foreground">{r.handle}</div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {CANONICAL_SUBJECTS.map((sub) => (
                <tr key={sub.key} className="hover:bg-surface-200/50 transition-colors">
                  <td className="sticky left-0 z-10 bg-card px-3 py-2 font-medium text-foreground shadow-[1px_0_0_0_var(--border)]">
                    {sub.label}
                    <span className="block font-mono text-[10px] text-muted-foreground">{sub.key}</span>
                  </td>
                  {roles.map((r) => {
                    const perms = getSubjectPermissions(r, sub.key);
                    return (
                      <td key={r.id} className="px-3 py-2">
                        {perms.length > 0 ? (
                          <div className="flex flex-wrap gap-1">
                            {perms.map((p) => (
                              <Badge
                                key={p}
                                variant={r.handle === 'owner' ? 'default' : 'secondary'}
                                className="text-[10px] px-1.5 py-0 h-4"
                              >
                                {p}
                              </Badge>
                            ))}
                          </div>
                        ) : (
                          <span className="text-muted-foreground text-11 flex items-center gap-1">
                            <X className="h-3 w-3 text-muted-foreground/60" />
                            No Access
                          </span>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      {/* Create Role Dialog */}
      <Dialog open={createDialogOpen} onOpenChange={setCreateDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Create Custom Role</DialogTitle>
            <DialogDescription>
              Define a new permission group tailored for specific workflows.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-3">
            {roleFormError ? (
              <Alert variant="error" role="alert">
                <p className="text-13">{roleFormError}</p>
              </Alert>
            ) : null}

            <FormItemLayout id="role-name" label="Role Name" required>
              <Input
                id="role-name"
                value={newRoleName}
                onChange={(e) => {
                  setNewRoleName(e.target.value);
                  if (!newRoleHandle) {
                    setNewRoleHandle(e.target.value.toLowerCase().replace(/\s+/g, '_'));
                  }
                }}
                placeholder="e.g. Media Contributor"
                required
              />
            </FormItemLayout>

            <FormItemLayout
              id="role-handle"
              label="Role Handle"
              required
              helpText="Unique system identifier, e.g. media_contributor."
            >
              <Input
                id="role-handle"
                value={newRoleHandle}
                onChange={(e) => setNewRoleHandle(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
                placeholder="e.g. media_contributor"
                required
              />
            </FormItemLayout>

            <FormItemLayout id="role-desc" label="Description">
              <Textarea
                id="role-desc"
                value={newRoleDesc}
                onChange={(e) => setNewRoleDesc(e.target.value)}
                placeholder="Short summary of permissions and capabilities."
                rows={2}
              />
            </FormItemLayout>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setCreateDialogOpen(false)}
              disabled={savingRole}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleCreateRole}
              disabled={savingRole || !newRoleName.trim() || !newRoleHandle.trim()}
            >
              {savingRole ? (
                <span className="flex items-center gap-2">
                  <Spinner size={14} />
                  Creating...
                </span>
              ) : (
                'Create Role'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Clone Role Dialog */}
      <Dialog open={Boolean(cloneTarget)} onOpenChange={(open) => !open && setCloneTarget(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Clone Role</DialogTitle>
            <DialogDescription>
              Create a duplicate of <strong>{cloneTarget?.name}</strong> with its initial permissions.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-3">
            {roleFormError ? (
              <Alert variant="error" role="alert">
                <p className="text-13">{roleFormError}</p>
              </Alert>
            ) : null}

            <FormItemLayout id="clone-role-name" label="New Role Name" required>
              <Input
                id="clone-role-name"
                value={newRoleName}
                onChange={(e) => setNewRoleName(e.target.value)}
                placeholder="e.g. Senior Editor"
                required
              />
            </FormItemLayout>

            <FormItemLayout id="clone-role-handle" label="New Role Handle" required>
              <Input
                id="clone-role-handle"
                value={newRoleHandle}
                onChange={(e) => setNewRoleHandle(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
                placeholder="e.g. senior_editor"
                required
              />
            </FormItemLayout>

            <FormItemLayout id="clone-role-desc" label="Description">
              <Textarea
                id="clone-role-desc"
                value={newRoleDesc}
                onChange={(e) => setNewRoleDesc(e.target.value)}
                placeholder="Description of the cloned role."
                rows={2}
              />
            </FormItemLayout>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setCloneTarget(null)}
              disabled={savingRole}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleCloneRole}
              disabled={savingRole || !newRoleName.trim() || !newRoleHandle.trim()}
            >
              {savingRole ? (
                <span className="flex items-center gap-2">
                  <Spinner size={14} />
                  Cloning...
                </span>
              ) : (
                'Clone Role'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Modal */}
      <ConfirmationModal
        open={Boolean(deleteTarget)}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title="Delete Custom Role"
        body={`Permanently delete custom role "${deleteTarget?.name}"? Any users assigned to this role will lose its permissions.`}
        confirmLabel="Delete Role"
        destructive
        onConfirm={() => {
          if (deleteTarget) {
            return handleDeleteRole(deleteTarget);
          }
        }}
      />

      {/* Step-Up Authentication Dialog */}
      {stepUpOpen ? (
        <Suspense fallback={null}>
          <StepUpDialog
            open={stepUpOpen}
            onOpenChange={setStepUpOpen}
            title="Security Step-Up Required"
            description="Modifying role definitions requires verifying your credentials."
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

      {/* SPRINT-A: Preview As Role Modal */}
      <Dialog open={Boolean(previewRole)} onOpenChange={(open) => !open && setPreviewRole(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Simulated Capabilities: {previewRole?.name}</DialogTitle>
            <DialogDescription>
              Preview of what a user assigned to <code>{previewRole?.handle}</code> can see and perform.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2 max-h-80 overflow-y-auto">
            {CANONICAL_SUBJECTS.map((sub) => {
              if (!previewRole) return null;
              const perms = getSubjectPermissions(previewRole, sub.key);
              return (
                <div key={sub.key} className="flex items-center justify-between text-12 border-b border-border/40 pb-2">
                  <span className="font-medium text-foreground">{sub.label}</span>
                  {perms.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {perms.map((p) => (
                        <Badge key={p} variant="secondary" className="text-[10px]">
                          {p}
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <span className="text-muted-foreground text-[11px]">Forbidden (403)</span>
                  )}
                </div>
              );
            })}
          </div>

          <DialogFooter>
            <Button onClick={() => setPreviewRole(null)}>Close Preview</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* SPRINT-A: Custom Role Permissions Editor Modal */}
      <Dialog open={Boolean(editingRole)} onOpenChange={(open) => !open && setEditingRole(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Edit Permissions: {editingRole?.name}</DialogTitle>
            <DialogDescription>
              Configure allow/deny/inherit access rules and scoped ownership restrictions.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2 max-h-96 overflow-y-auto">
            {CANONICAL_SUBJECTS.map((sub) => {
              const current = editedPermissions[sub.key] || { effect: 'inherit', own_only: false };
              return (
                <div key={sub.key} className="p-2.5 rounded border border-border bg-card space-y-2 text-12">
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="font-medium text-foreground">{sub.label}</span>
                      <code className="block text-[10px] text-muted-foreground">{sub.key}</code>
                    </div>

                    <div className="flex items-center gap-1">
                      {(['inherit', 'allow', 'deny'] as const).map((eff) => (
                        <button
                          key={eff}
                          type="button"
                          onClick={() =>
                            setEditedPermissions((prev) => ({
                              ...prev,
                              [sub.key]: { ...prev[sub.key], effect: eff },
                            }))
                          }
                          className={`px-2 py-0.5 rounded text-11 font-mono uppercase transition-colors ${
                            current.effect === eff
                              ? eff === 'allow'
                                ? 'bg-success text-success-foreground font-semibold'
                                : eff === 'deny'
                                ? 'bg-destructive text-destructive-foreground font-semibold'
                                : 'bg-primary text-primary-foreground font-semibold'
                              : 'bg-muted text-muted-foreground hover:bg-muted/80'
                          }`}
                        >
                          {eff}
                        </button>
                      ))}
                    </div>
                  </div>

                  {current.effect === 'allow' && (
                    <div className="flex items-center justify-between pt-1 border-t border-border/40 text-[11px] text-muted-foreground">
                      <span>Restrict to own entries only</span>
                      <input
                        type="checkbox"
                        checked={Boolean(current.own_only)}
                        onChange={(e) => {
                          const isChecked = e.target.checked;
                          setEditedPermissions((prev) => {
                            const cur = prev[sub.key] ?? { effect: 'allow' as const };
                            return {
                              ...prev,
                              [sub.key]: { effect: cur.effect, own_only: isChecked },
                            };
                          });
                        }}
                        className="rounded border-border text-primary focus:ring-primary"
                      />
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setEditingRole(null)}>
              Cancel
            </Button>
            <Button
              loading={savingPermissions}
              onClick={async () => {
                if (!editingRole) return;
                setSavingPermissions(true);
                try {
                  const rules: Array<{ subject_type: string; subject_key: string; action: string; effect: string; conditions?: { own_entries: boolean } | null }> = [];
                  Object.entries(editedPermissions).forEach(([subjectKey, val]) => {
                    if (val.effect === 'allow') {
                      rules.push({
                        subject_type: subjectKey,
                        subject_key: '*',
                        action: '*',
                        effect: 'allow',
                        conditions: val.own_only ? { own_entries: true } : null,
                      });
                    } else if (val.effect === 'deny') {
                      rules.push({
                        subject_type: subjectKey,
                        subject_key: '*',
                        action: '*',
                        effect: 'deny',
                      });
                    }
                  });

                  const res = await apiClient.request('PUT', `/roles/${editingRole.id}/permissions`, {
                    body: { permissions: rules },
                  });
                  if (res.ok) {
                    setEditingRole(null);
                    loadRoles();
                  } else {
                    alert('Failed to update permissions.');
                  }
                } catch {
                  alert('Error saving role permissions.');
                } finally {
                  setSavingPermissions(false);
                }
              }}
            >
              Save Permissions
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
