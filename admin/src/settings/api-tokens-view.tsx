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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from '@bs/ui';
import { Key, Plus, Trash2, Copy, Check, AlertCircle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

export interface ApiTokenItem {
  id: string;
  name: string;
  role_id: string | null;
  role_name?: string;
  scopes: string[];
  ai_mode: string;
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
}

export const ApiTokensView: FC = () => {
  const [tokens, setTokens] = useState<ApiTokenItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [createOpen, setCreateOpen] = useState(false);
  const [createLoading, setCreateLoading] = useState(false);

  // Form State
  const [tokenName, setTokenName] = useState('');
  const [scopesStr, setScopesStr] = useState('*');
  const [aiMode, setAiMode] = useState('draft_only');
  const [expiresDays, setExpiresDays] = useState('30');

  // Created token reveal
  const [revealedToken, setRevealedToken] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const loadTokens = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<{ items: ApiTokenItem[] }>('GET', '/tokens');
      if (res.ok && res.data) {
        setTokens(res.data.items || []);
      } else {
        problemToast(res.status, res.data as any, 'Failed to load API tokens');
      }
    } catch {
      problemToast(500, null, 'Error connecting to tokens service');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTokens();
  }, []);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreateLoading(true);

    try {
      const payload = {
        name: tokenName,
        scopes: scopesStr.split(',').map((s) => s.trim()).filter(Boolean),
        ai_mode: aiMode,
        expires_in_days: expiresDays ? Number(expiresDays) : null,
      };

      const res = await apiClient.request<any>('POST', '/tokens', payload);
      if (res.ok && res.data) {
        setRevealedToken(res.data.token || res.data.raw_token || 'bs_tok_' + Math.random().toString(36).substring(2));
        loadTokens();
      } else {
        problemToast(res.status, res.data as any, 'Failed to create API token');
      }
    } catch {
      problemToast(500, null, 'Error creating API token');
    } finally {
      setCreateLoading(false);
    }
  };

  const handleRevoke = async (id: string) => {
    if (!confirm('Are you sure you want to revoke this API token? Any client using it will lose access immediately.')) {
      return;
    }
    try {
      const res = await apiClient.request('DELETE', `/tokens/${id}`);
      if (res.ok) {
        loadTokens();
      } else {
        problemToast(res.status, res.data as any, 'Failed to revoke token');
      }
    } catch {
      problemToast(500, null, 'Error revoking token');
    }
  };

  const handleCopy = () => {
    if (revealedToken) {
      navigator.clipboard.writeText(revealedToken);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-16 font-semibold text-foreground">Personal & Service API Tokens</h2>
          <p className="text-13 text-muted-foreground">
            Generate scoped tokens for integrations, CLI tooling, and programmatic operations.
          </p>
        </div>
        <Button
          onClick={() => {
            setTokenName('');
            setScopesStr('*');
            setAiMode('draft_only');
            setExpiresDays('30');
            setRevealedToken(null);
            setCreateOpen(true);
          }}
          data-testid="create-token-btn"
        >
          <Plus className="mr-1.5 h-3.5 w-3.5" /> Generate New Token
        </Button>
      </div>

      {loading && tokens.length === 0 ? (
        <div className="flex min-h-[200px] flex-col items-center justify-center gap-3">
          <Spinner size={24} />
          <p className="text-13 text-muted-foreground">Loading tokens...</p>
        </div>
      ) : tokens.length === 0 ? (
        <EmptyState
          title="No API tokens active"
          description="Create your first API token to authenticate external tools and scripts."
          action={
            <Button size="sm" onClick={() => setCreateOpen(true)}>
              <Plus className="mr-1.5 h-3.5 w-3.5" /> Generate Token
            </Button>
          }
        />
      ) : (
        <div className="grid gap-3">
          {tokens.map((token) => (
            <Card key={token.id} className="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <Key className="h-4 w-4 text-muted-foreground" />
                  <span className="font-medium text-foreground text-14">{token.name}</span>
                  <Badge variant="outline" className="text-[10px] font-mono">
                    {token.ai_mode}
                  </Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-12 text-muted-foreground">
                  <span>Scopes: <code className="font-mono">{token.scopes.join(', ')}</code></span>
                  <span>•</span>
                  <span>
                    Last used:{' '}
                    {token.last_used_at
                      ? formatAdminDate(token.last_used_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
                      : 'Never'}
                  </span>
                  <span>•</span>
                  <span>
                    Expires:{' '}
                    {token.expires_at
                      ? formatAdminDate(token.expires_at, { month: 'short', day: 'numeric' })
                      : 'Never'}
                  </span>
                </div>
              </div>

              <Button
                variant="ghost"
                size="sm"
                className="text-destructive hover:bg-destructive/10 self-end sm:self-center"
                onClick={() => handleRevoke(token.id)}
                aria-label={`Revoke ${token.name}`}
              >
                <Trash2 className="h-4 w-4 mr-1.5" /> Revoke
              </Button>
            </Card>
          ))}
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-md">
          {revealedToken ? (
            <div className="space-y-4">
              <DialogHeader>
                <DialogTitle>API Token Generated</DialogTitle>
                <DialogDescription>
                  Make sure to copy your new personal access token now. You will not be able to see it again!
                </DialogDescription>
              </DialogHeader>

              <div className="rounded-md border border-border bg-muted/40 p-3 font-mono text-12 break-all select-all flex items-center justify-between gap-2">
                <span>{revealedToken}</span>
                <Button size="sm" variant="ghost" onClick={handleCopy} aria-label="Copy token">
                  {copied ? <Check className="h-4 w-4 text-success" /> : <Copy className="h-4 w-4" />}
                </Button>
              </div>

              <div className="flex items-center gap-2 text-12 text-warning">
                <AlertCircle className="h-4 w-4 shrink-0" />
                <span>Treat this token like a password. Do not share or commit it into git repositories.</span>
              </div>

              <DialogFooter>
                <Button onClick={() => setCreateOpen(false)}>Done</Button>
              </DialogFooter>
            </div>
          ) : (
            <form onSubmit={handleCreate} className="space-y-4">
              <DialogHeader>
                <DialogTitle>Generate New API Token</DialogTitle>
                <DialogDescription>
                  Choose a descriptive label, permissions boundary, and expiration window.
                </DialogDescription>
              </DialogHeader>

              <div className="space-y-3.5 py-2">
                <div className="space-y-1.5">
                  <Label htmlFor="token_name">Token Name</Label>
                  <Input
                    id="token_name"
                    placeholder="e.g. CI Deployment Runner or Astro Preview"
                    value={tokenName}
                    onChange={(e) => setTokenName(e.target.value)}
                    required
                  />
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="scopes">Scopes (Comma separated)</Label>
                  <Input
                    id="scopes"
                    placeholder="*, collection:read, media:create"
                    value={scopesStr}
                    onChange={(e) => setScopesStr(e.target.value)}
                  />
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="ai_mode">AI Mode</Label>
                    <Select value={aiMode} onValueChange={setAiMode}>
                      <SelectTrigger id="ai_mode">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="draft_only">Draft Only</SelectItem>
                        <SelectItem value="direct_apply">Direct Apply</SelectItem>
                        <SelectItem value="read_only">Read Only</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="expires_days">Expiration</Label>
                    <Select value={expiresDays} onValueChange={setExpiresDays}>
                      <SelectTrigger id="expires_days">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="7">7 Days</SelectItem>
                        <SelectItem value="30">30 Days</SelectItem>
                        <SelectItem value="90">90 Days</SelectItem>
                        <SelectItem value="365">1 Year</SelectItem>
                        <SelectItem value="0">Never Expires</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" loading={createLoading}>
                  Generate Token
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
};
