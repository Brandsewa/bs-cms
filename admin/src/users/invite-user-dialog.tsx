import { useState } from 'react';
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
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@bs/ui';
import { Copy, Check, Key } from 'lucide-react';
import { apiClient } from '../lib/api';

export interface RoleOption {
  id: string;
  handle: string;
  name: string;
  description?: string;
  is_system: number;
}

export interface InviteUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  roles: RoleOption[];
  onSuccess: () => void;
}

export function InviteUserDialog({
  open,
  onOpenChange,
  roles,
  onSuccess,
}: InviteUserDialogProps) {
  const [email, setEmail] = useState('');
  const [roleId, setRoleId] = useState('');
  const [inviting, setInviting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [generatedToken, setGeneratedToken] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const handleClose = () => {
    if (!inviting) {
      setEmail('');
      setRoleId('');
      setError(null);
      setGeneratedToken(null);
      setCopied(false);
      onOpenChange(false);
    }
  };

  const handleInvite = async () => {
    if (!email.trim() || !roleId) {
      setError('Please provide a valid email and select a role.');
      return;
    }

    setInviting(true);
    setError(null);

    try {
      const res = await apiClient.request<{
        status?: string;
        token?: string;
        invitation?: { token?: string };
      }>('POST', '/users/invite', {
        body: {
          email: email.trim(),
          role_id: roleId,
        },
      });

      if (res.ok) {
        const token = res.data?.token || res.data?.invitation?.token;
        if (token) {
          // Token provided by backend (e.g. dev or unconfigured email mode)
          setGeneratedToken(token);
        } else {
          // Email dispatched normally
          onSuccess();
          handleClose();
        }
      } else {
        setError(res.problem?.detail || 'Failed to send invitation.');
      }
    } catch {
      setError('A network error occurred while inviting user.');
    } finally {
      setInviting(false);
    }
  };

  const inviteUrl = generatedToken
    ? `${window.location.origin}/admin/accept-invitation?token=${encodeURIComponent(generatedToken)}`
    : '';

  const handleCopyLink = async () => {
    if (inviteUrl) {
      try {
        await navigator.clipboard.writeText(inviteUrl);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      } catch {
        // Fallback or ignore
      }
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Invite New User</DialogTitle>
          <DialogDescription>
            Send an invitation to join your workspace with specific role permissions.
          </DialogDescription>
        </DialogHeader>

        {generatedToken ? (
          <div className="space-y-4 py-3">
            <Alert variant="success" role="status" className="border-border">
              <p className="text-13 font-medium">User Invited Successfully</p>
              <p className="text-12 mt-0.5 text-muted-foreground">
                An invitation token has been created for <strong className="text-foreground">{email}</strong>.
              </p>
            </Alert>

            <div className="space-y-2 rounded-lg border border-border bg-muted/40 p-3">
              <div className="flex items-center gap-2 text-12 font-medium text-foreground">
                <Key className="h-4 w-4 text-primary" />
                <span>Direct Invitation Link</span>
              </div>
              <p className="text-12 text-muted-foreground break-all font-mono select-all bg-background p-2 rounded border border-border">
                {inviteUrl}
              </p>
              <div className="pt-1 flex justify-end">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={handleCopyLink}
                  className="gap-1.5"
                >
                  {copied ? (
                    <>
                      <Check className="h-3.5 w-3.5 text-success" />
                      <span>Copied!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="h-3.5 w-3.5" />
                      <span>Copy Link</span>
                    </>
                  )}
                </Button>
              </div>
            </div>

            <DialogFooter>
              <Button
                type="button"
                onClick={() => {
                  onSuccess();
                  handleClose();
                }}
              >
                Done
              </Button>
            </DialogFooter>
          </div>
        ) : (
          <div className="space-y-4 py-3">
            {error ? (
              <Alert variant="error" role="alert">
                <p className="text-13">{error}</p>
              </Alert>
            ) : null}

            <FormItemLayout
              id="invite-email"
              label="Email Address"
              required
              helpText="The invited user will receive an onboarding link at this email."
            >
              <Input
                id="invite-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="colleague@example.com"
                required
              />
            </FormItemLayout>

            <FormItemLayout
              id="invite-role"
              label="Assigned Role"
              required
              helpText="Determines permissions and access levels across collections and settings."
            >
              <Select value={roleId} onValueChange={setRoleId}>
                <SelectTrigger id="invite-role-trigger">
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

            <DialogFooter>
              <Button type="button" variant="outline" onClick={handleClose} disabled={inviting}>
                Cancel
              </Button>
              <Button
                type="button"
                onClick={handleInvite}
                disabled={inviting || !email.trim() || !roleId}
              >
                {inviting ? (
                  <span className="flex items-center gap-2">
                    <Spinner size={14} />
                    Inviting...
                  </span>
                ) : (
                  'Send Invitation'
                )}
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
