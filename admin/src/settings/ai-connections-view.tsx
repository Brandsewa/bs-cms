import { FC, useState, useEffect } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  EmptyState,
  Input,
  Label,
  Spinner,
} from '@bs/ui';
import { Bot, Power, Trash2, CheckCircle2, XCircle, AlertTriangle, ShieldAlert } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

interface AiStatus {
  kill_switch_active: boolean;
  active_grants_count: number;
  mode_default: string;
}

interface AiGrant {
  id: string;
  client_name: string;
  oauth_client_id: string;
  user_email: string;
  role_name: string;
  scopes: string[];
  ai_mode: string;
  created_at: string;
  last_used_at: string | null;
  revoked_at: string | null;
}

interface PendingChange {
  id: string;
  actor_label: string;
  operation: string;
  subject_type: string;
  subject_id: string | null;
  payload: any;
  diff: any;
  status: 'pending' | 'approved' | 'rejected' | 'expired';
  expires_at: string;
  created_at: string;
}

export const AiConnectionsView: FC = () => {
  const [status, setStatus] = useState<AiStatus | null>(null);
  const [grants, setGrants] = useState<AiGrant[]>([]);
  const [pendingChanges, setPendingChanges] = useState<PendingChange[]>([]);
  const [loading, setLoading] = useState(true);

  // Kill Switch Step-Up Dialog
  const [stepUpOpen, setStepUpOpen] = useState(false);
  const [stepUpAction, setStepUpAction] = useState<'disconnect' | 'reconnect'>('disconnect');
  const [stepUpPassword, setStepUpPassword] = useState('');
  const [stepUpLoading, setStepUpLoading] = useState(false);

  // Pending change review dialog
  const [selectedChange, setSelectedChange] = useState<PendingChange | null>(null);
  const [decisionNote, setDecisionNote] = useState('');
  const [deciding, setDeciding] = useState(false);

  const loadData = async () => {
    setLoading(true);
    try {
      const [stRes, grRes, pcRes] = await Promise.all([
        apiClient.request<AiStatus>('GET', '/ai/status'),
        apiClient.request<{ items: AiGrant[] }>('GET', '/ai/grants'),
        apiClient.request<{ items: PendingChange[] }>('GET', '/pending-changes?status=pending'),
      ]);

      if (stRes.ok && stRes.data) setStatus(stRes.data);
      if (grRes.ok && grRes.data) setGrants(grRes.data.items || []);
      if (pcRes.ok && pcRes.data) setPendingChanges(pcRes.data.items || []);
    } catch {
      problemToast(500, null, 'Error loading AI governance data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleStepUpSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setStepUpLoading(true);

    try {
      const endpoint = stepUpAction === 'disconnect' ? '/ai/disconnect' : '/ai/reconnect';
      const res = await apiClient.request<any>('POST', endpoint, {
        password: stepUpPassword,
      });

      if (res.ok) {
        setStepUpOpen(false);
        setStepUpPassword('');
        loadData();
      } else {
        problemToast(res.status, res.data as any, 'Step-up authorization failed');
      }
    } catch {
      problemToast(500, null, 'Error executing AI kill switch toggle');
    } finally {
      setStepUpLoading(false);
    }
  };

  const handleRevokeGrant = async (id: string) => {
    if (!confirm('Revoke this AI Agent grant? The agent will be disconnected immediately.')) return;
    try {
      const res = await apiClient.request('DELETE', `/ai/grants/${id}`);
      if (res.ok) {
        loadData();
      } else {
        problemToast(res.status, res.data as any, 'Failed to revoke AI grant');
      }
    } catch {
      problemToast(500, null, 'Error revoking AI grant');
    }
  };

  const handleApproveChange = async (id: string) => {
    setDeciding(true);
    try {
      const res = await apiClient.request('POST', `/pending-changes/${id}/approve`, {
        decision_note: decisionNote,
      });
      if (res.ok) {
        setSelectedChange(null);
        setDecisionNote('');
        loadData();
      } else {
        problemToast(res.status, res.data as any, 'Failed to approve change');
      }
    } catch {
      problemToast(500, null, 'Error approving pending change');
    } finally {
      setDeciding(false);
    }
  };

  const handleRejectChange = async (id: string) => {
    setDeciding(true);
    try {
      const res = await apiClient.request('POST', `/pending-changes/${id}/reject`, {
        decision_note: decisionNote,
      });
      if (res.ok) {
        setSelectedChange(null);
        setDecisionNote('');
        loadData();
      } else {
        problemToast(res.status, res.data as any, 'Failed to reject change');
      }
    } catch {
      problemToast(500, null, 'Error rejecting pending change');
    } finally {
      setDeciding(false);
    }
  };

  if (loading && !status) {
    return (
      <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
        <Spinner size={28} />
        <p className="text-13 text-muted-foreground">Loading AI governance & connections...</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Emergency Kill Switch Banner */}
      <Card className={status?.kill_switch_active ? 'border-destructive bg-destructive/5' : 'border-border'}>
        <CardContent className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className={`p-2 rounded-full ${status?.kill_switch_active ? 'bg-destructive/20 text-destructive' : 'bg-primary/10 text-primary'}`}>
              <ShieldAlert className="h-5 w-5" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="font-semibold text-15 text-foreground">AI Kill Switch</h3>
                <Badge variant={status?.kill_switch_active ? 'destructive' : 'default'} className="text-[10px]">
                  {status?.kill_switch_active ? 'DISCONNECTED (All AI Halted)' : 'ONLINE'}
                </Badge>
              </div>
              <p className="text-12 text-muted-foreground">
                {status?.kill_switch_active
                  ? 'All autonomous agents, MCP tools, and pending changes are globally disabled.'
                  : 'Emergency circuit breaker. Step-up password verification required to toggle.'}
              </p>
            </div>
          </div>

          <Button
            variant={status?.kill_switch_active ? 'outline' : 'destructive'}
            size="sm"
            onClick={() => {
              setStepUpAction(status?.kill_switch_active ? 'reconnect' : 'disconnect');
              setStepUpPassword('');
              setStepUpOpen(true);
            }}
            data-testid="toggle-kill-switch-btn"
          >
            <Power className="mr-1.5 h-3.5 w-3.5" />
            {status?.kill_switch_active ? 'Reconnect AI Agents' : 'Disconnect All AI (Kill Switch)'}
          </Button>
        </CardContent>
      </Card>

      {/* Pending AI Changes Queue */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Pending AI Changes ({pendingChanges.length})</CardTitle>
              <CardDescription>
                Proposals submitted by AI agents running in <code>draft_only</code> mode awaiting human review.
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {pendingChanges.length === 0 ? (
            <p className="text-13 text-muted-foreground py-4 text-center">
              No pending AI proposals waiting for human approval.
            </p>
          ) : (
            <div className="divide-y divide-border">
              {pendingChanges.map((change) => (
                <div key={change.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="font-medium text-foreground text-13">{change.actor_label}</span>
                      <Badge variant="outline" className="text-[10px] font-mono">
                        {change.operation}
                      </Badge>
                      <span className="text-12 text-muted-foreground">
                        on {change.subject_type} {change.subject_id ? `(${change.subject_id})` : ''}
                      </span>
                    </div>
                    <span className="text-[11px] text-muted-foreground">
                      Expires: {formatAdminDate(change.expires_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    </span>
                  </div>

                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setSelectedChange(change);
                        setDecisionNote('');
                      }}
                    >
                      Review Diff
                    </Button>
                    <Button
                      variant="default"
                      size="sm"
                      onClick={() => handleApproveChange(change.id)}
                    >
                      Approve
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-destructive hover:bg-destructive/10"
                      onClick={() => handleRejectChange(change.id)}
                    >
                      Reject
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Connected AI Grants */}
      <Card>
        <CardHeader>
          <CardTitle>Authorized AI Agent Grants</CardTitle>
          <CardDescription>
            OAuth grants and MCP agent authorizations active on this workspace.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {grants.length === 0 ? (
            <EmptyState
              title="No active AI agent grants"
              description="Connect an MCP client or AI agent using OAuth 2.1 to manage content."
            />
          ) : (
            <div className="divide-y divide-border">
              {grants.map((g) => (
                <div key={g.id} className="py-3.5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <Bot className="h-4 w-4 text-primary" />
                      <span className="font-semibold text-foreground text-14">{g.client_name}</span>
                      <Badge variant={g.revoked_at ? 'destructive' : 'default'} className="text-[10px]">
                        {g.revoked_at ? 'Revoked' : g.ai_mode}
                      </Badge>
                      <Badge variant="outline" className="text-[10px]">
                        {g.role_name}
                      </Badge>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-12 text-muted-foreground font-mono">
                      <span>Client ID: {g.oauth_client_id}</span>
                      <span>•</span>
                      <span>Authorized by: {g.user_email}</span>
                      <span>•</span>
                      <span>
                        Last active:{' '}
                        {g.last_used_at
                          ? formatAdminDate(g.last_used_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
                          : 'Never'}
                      </span>
                    </div>
                  </div>

                  {!g.revoked_at && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-destructive hover:bg-destructive/10 self-end md:self-center"
                      onClick={() => handleRevokeGrant(g.id)}
                    >
                      <Trash2 className="h-4 w-4 mr-1.5" /> Disconnect
                    </Button>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Step-Up Password Modal */}
      <Dialog open={stepUpOpen} onOpenChange={setStepUpOpen}>
        <DialogContent className="max-w-md">
          <form onSubmit={handleStepUpSubmit} className="space-y-4">
            <DialogHeader>
              <DialogTitle>
                {stepUpAction === 'disconnect' ? 'Emergency Disconnect All AI' : 'Reconnect AI Agents'}
              </DialogTitle>
              <DialogDescription>
                Confirm your account password to verify administrative step-up authority.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-2 py-2">
              <Label htmlFor="stepup_password">Account Password</Label>
              <Input
                id="stepup_password"
                type="password"
                placeholder="Enter password"
                value={stepUpPassword}
                onChange={(e) => setStepUpPassword(e.target.value)}
                required
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setStepUpOpen(false)}>
                Cancel
              </Button>
              <Button
                type="submit"
                variant={stepUpAction === 'disconnect' ? 'destructive' : 'default'}
                loading={stepUpLoading}
              >
                Confirm {stepUpAction === 'disconnect' ? 'Disconnect' : 'Reconnect'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Diff Review Modal */}
      <Dialog open={!!selectedChange} onOpenChange={(open) => !open && setSelectedChange(null)}>
        <DialogContent className="max-w-2xl">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Review Proposed AI Change</DialogTitle>
              <DialogDescription>
                Inspect proposed mutation payload before applying to database.
              </DialogDescription>
            </DialogHeader>

            <div className="rounded-md border border-border bg-muted/40 p-3 max-h-80 overflow-y-auto font-mono text-12">
              <pre>{JSON.stringify(selectedChange?.diff || selectedChange?.payload, null, 2)}</pre>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="decision_note">Decision Note (Optional)</Label>
              <Input
                id="decision_note"
                placeholder="Approval rationale or review comments"
                value={decisionNote}
                onChange={(e) => setDecisionNote(e.target.value)}
              />
            </div>

            <DialogFooter>
              <Button
                variant="outline"
                className="text-destructive"
                loading={deciding}
                onClick={() => selectedChange && handleRejectChange(selectedChange.id)}
              >
                Reject Change
              </Button>
              <Button
                loading={deciding}
                onClick={() => selectedChange && handleApproveChange(selectedChange.id)}
              >
                Approve & Apply
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
};
