import { useState, useEffect } from 'react';
import {
  Alert,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  FormItemLayout,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@bs/ui';
import { ShieldAlert } from 'lucide-react';
import { apiClient } from '../lib/api';
import type { RoleOption } from './invite-user-dialog';

export interface UserSummary {
  id: string;
  name: string;
  email: string;
  roles: Array<{ id: string; handle: string; name: string }>;
}

export interface ChangeRoleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: UserSummary | null;
  roles: RoleOption[];
  activeOwnerCount: number;
  onSuccess: () => void;
  onRequireStepUp?: () => void;
}

export function ChangeRoleDialog({
  open,
  onOpenChange,
  user,
  roles,
  activeOwnerCount,
  onSuccess,
  onRequireStepUp,
}: ChangeRoleDialogProps) {
  const currentRoleId = user?.roles?.[0]?.id || '';
  const [selectedRoleId, setSelectedRoleId] = useState(currentRoleId);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (user?.roles?.[0]?.id) {
      setSelectedRoleId(user.roles[0].id);
    }
  }, [user]);

  const isCurrentOwner = user?.roles?.some((r) => r.handle === 'owner') ?? false;
  const targetRole = roles.find((r) => r.id === (selectedRoleId || currentRoleId));
  const isTargetOwner = targetRole?.handle === 'owner';
  const isDowngradingLastOwner = isCurrentOwner && !isTargetOwner && activeOwnerCount <= 1;

  const handleClose = () => {
    if (!saving) {
      setError(null);
      onOpenChange(false);
    }
  };

  const handleSave = async () => {
    if (!user || !selectedRoleId) return;
    if (isDowngradingLastOwner) {
      setError('Cannot downgrade the last active workspace owner.');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      const res = await apiClient.request<{ status: string }>('PATCH', `/users/${user.id}`, {
        body: {
          roles: [selectedRoleId],
        },
      });

      if (res.ok) {
        onSuccess();
        handleClose();
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        handleClose();
        onRequireStepUp?.();
      } else {
        setError(res.problem?.detail || 'Failed to update user role.');
      }
    } catch {
      setError('A network error occurred while updating role.');
    } finally {
      setSaving(false);
    }
  };

  if (!user) return null;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Change User Role</DialogTitle>
          <DialogDescription>
            Update role assignments for <strong className="text-foreground">{user.email}</strong>.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-3">
          {error ? (
            <Alert variant="error" role="alert">
              <p className="text-13">{error}</p>
            </Alert>
          ) : null}

          {isDowngradingLastOwner ? (
            <Alert variant="error" role="alert" className="flex items-start gap-2">
              <ShieldAlert className="h-4 w-4 shrink-0 text-destructive mt-0.5" />
              <div>
                <p className="text-13 font-medium">Last Owner Protection</p>
                <p className="text-12 text-muted-foreground mt-0.5">
                  The last active owner cannot have their owner role removed. Designate another user as Owner before changing this role.
                </p>
              </div>
            </Alert>
          ) : null}

          <FormItemLayout
            id="user-role-select"
            label="Assigned Role"
            required
            helpText="Changes will take effect immediately on the user's next authenticated request."
          >
            <Select
              value={selectedRoleId || currentRoleId}
              onValueChange={setSelectedRoleId}
            >
              <SelectTrigger id="user-role-select">
                <SelectValue placeholder="Select a role" />
              </SelectTrigger>
              <SelectContent>
                {roles.map((r) => (
                  <SelectItem key={r.id} value={r.id}>
                    <span className="font-medium">{r.name}</span>
                    {r.description ? (
                      <span className="block text-11 text-muted-foreground truncate max-w-xs">
                        {r.description}
                      </span>
                    ) : null}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormItemLayout>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={handleClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleSave}
            disabled={saving || isDowngradingLastOwner || (selectedRoleId === currentRoleId && !!currentRoleId)}
          >
            {saving ? (
              <span className="flex items-center gap-2">
                <Spinner size={14} />
                Saving...
              </span>
            ) : (
              'Save Role'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
