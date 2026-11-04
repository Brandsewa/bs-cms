import { FormPageSkeleton } from '../components/page-skeletons';
import { FC, useState, useEffect, lazy, Suspense } from 'react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Switch,
  problemToast,
  successToast,
} from '@bs/ui';
import { Globe, Rocket, RefreshCw, CheckCircle2, Clock } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';

const StepUpDialog = lazy(() =>
  import('../auth/step-up-dialog').then((m) => ({ default: m.StepUpDialog }))
);

interface PublishingConfig {
  target_type: 'github_actions' | 'webhook' | 'manual';
  enabled: boolean;
  debounce_window: number;
  github_repository: string;
  github_workflow_id: string;
  has_github_token: boolean;
  webhook_url: string | null;
  has_webhook_secret: boolean;
  url_patterns?: Record<string, string>;
  indexnow_key?: string;
}

interface Deployment {
  id: string;
  trigger_reason: string;
  status: 'pending' | 'building' | 'succeeded' | 'failed' | 'cancelled';
  dispatched_at: string;
  finished_at: string | null;
  error?: string | null;
}

export const PublishingSettingsView: FC = () => {
  const [config, setConfig] = useState<PublishingConfig | null>(null);
  const [deployments, setDeployments] = useState<Deployment[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deploying, setDeploying] = useState(false);
  const [savedSuccess, setSavedSuccess] = useState(false);
  const [stepUpOpen, setStepUpOpen] = useState(false);

  // Secret form fields (write-only)
  const [githubToken, setGithubToken] = useState('');
  const [webhookSecret, setWebhookSecret] = useState('');

  const loadData = async () => {
    setLoading(true);
    try {
      const [cfgRes, depRes] = await Promise.all([
        apiClient.request<PublishingConfig>('GET', '/settings/publishing'),
        apiClient.request<Deployment[]>('GET', '/deployments'),
      ]);

      if (cfgRes.ok && cfgRes.data) {
        setConfig({
          target_type: cfgRes.data.target_type || 'manual',
          enabled: cfgRes.data.enabled ?? false,
          debounce_window: cfgRes.data.debounce_window || 60,
          github_repository: cfgRes.data.github_repository || '',
          github_workflow_id: cfgRes.data.github_workflow_id || 'deploy.yml',
          has_github_token: Boolean(cfgRes.data.has_github_token),
          webhook_url: cfgRes.data.webhook_url || '',
          has_webhook_secret: Boolean(cfgRes.data.has_webhook_secret),
          url_patterns: cfgRes.data.url_patterns,
          indexnow_key: cfgRes.data.indexnow_key,
        });
      }
      if (depRes.ok && Array.isArray(depRes.data)) {
        setDeployments(depRes.data);
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error loading publishing configuration' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleSaveConfig = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!config) return;
    setSaving(true);
    setSavedSuccess(false);

    const payload: Record<string, unknown> = {
      target_type: config.target_type,
      enabled: config.enabled,
      debounce_window: config.debounce_window,
      github_repository: config.github_repository.trim(),
      github_workflow_id: config.github_workflow_id.trim(),
      webhook_url: config.webhook_url ? config.webhook_url.trim() : '',
    };

    if (githubToken.trim()) {
      payload.github_token = githubToken.trim();
    }
    if (webhookSecret.trim()) {
      payload.webhook_secret = webhookSecret.trim();
    }

    try {
      const res = await apiClient.request<PublishingConfig>('PUT', '/settings/publishing', {
        body: payload,
      });

      if (res.ok) {
        if (res.data) {
          setConfig((prev) => ({
            ...prev,
            ...res.data,
          }));
        }
        setGithubToken('');
        setWebhookSecret('');
        setSavedSuccess(true);
        successToast('Publishing settings saved successfully.');
        setTimeout(() => setSavedSuccess(false), 3000);
      } else if (res.status === 403 && (res.problem?.title?.includes('Step-Up') || res.problem?.detail?.includes('Step-up'))) {
        setStepUpOpen(true);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to save publishing settings' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error saving settings' });
    } finally {
      setSaving(false);
    }
  };

  const handleDeployNow = async () => {
    setDeploying(true);
    try {
      const res = await apiClient.request<unknown>('POST', '/deployments/trigger', {
        body: { reason: 'Manual dashboard deployment' },
      });
      if (res.ok) {
        successToast('Deployment triggered successfully.');
        loadData();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to trigger deployment' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error triggering deployment' });
    } finally {
      setDeploying(false);
    }
  };

  if (loading || !config) {
    return <FormPageSkeleton title="Publishing & Deployments" description="Static build pipelines, preview deployments, and webhooks." />;
  }

  return (
    <div className="space-y-6">
      {savedSuccess && (
        <Alert variant="success" role="status" className="border-border">
          <p className="text-13 text-foreground">Publishing settings saved successfully.</p>
        </Alert>
      )}

      {/* Target & Pipeline Setup */}
      <Card>
        <CardHeader>
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <CardTitle>Deployment Target & Pipeline</CardTitle>
              <CardDescription>
                Configure external static generation dispatch. Pages are also published instantly by the built-in renderer.
              </CardDescription>
            </div>
            <Button
              variant="default"
              size="sm"
              loading={deploying}
              onClick={handleDeployNow}
              data-testid="deploy-now-btn"
            >
              <Rocket className="mr-1.5 h-3.5 w-3.5" /> Deploy Now
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSaveConfig} className="space-y-4">
            <div className="flex items-center justify-between pb-2 border-b border-border">
              <div className="space-y-0.5">
                <Label htmlFor="pub_enabled" className="cursor-pointer font-medium">Enable Automatic Publishing</Label>
                <p className="text-12 text-muted-foreground">Trigger build pipeline automatically when entries are published or updated.</p>
              </div>
              <Switch
                id="pub_enabled"
                checked={config.enabled}
                onCheckedChange={(val) => setConfig({ ...config, enabled: val })}
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="target_type">Target Provider</Label>
                <Select
                  value={config.target_type}
                  onValueChange={(val) =>
                    setConfig({ ...config, target_type: val as 'github_actions' | 'webhook' | 'manual' })
                  }
                >
                  <SelectTrigger id="target_type">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="github_actions">GitHub Actions (Repository Dispatch)</SelectItem>
                    <SelectItem value="webhook">Generic HTTPS Webhook</SelectItem>
                    <SelectItem value="manual">Manual / Static Export Only</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="debounce_window">Debounce Window (Seconds)</Label>
                <Input
                  id="debounce_window"
                  type="number"
                  min={5}
                  max={600}
                  value={config.debounce_window}
                  onChange={(e) => setConfig({ ...config, debounce_window: Math.max(5, Number(e.target.value)) })}
                />
                <span className="text-11 text-muted-foreground">Batches rapid edits within this timeframe into a single build.</span>
              </div>
            </div>

            {config.target_type === 'github_actions' && (
              <div className="space-y-3 pt-2">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label htmlFor="github_repository">Repository (owner/repo)</Label>
                    <Input
                      id="github_repository"
                      placeholder="e.g. Brandsewa/bs-cms"
                      value={config.github_repository}
                      onChange={(e) => setConfig({ ...config, github_repository: e.target.value })}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="github_workflow_id">Workflow File or Event</Label>
                    <Input
                      id="github_workflow_id"
                      placeholder="e.g. deploy.yml or cms-publish"
                      value={config.github_workflow_id}
                      onChange={(e) => setConfig({ ...config, github_workflow_id: e.target.value })}
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="github_token">GitHub Personal Access Token (PAT)</Label>
                    {config.has_github_token && (
                      <Badge variant="outline" className="text-[10px] text-success">
                        <CheckCircle2 className="h-3 w-3 mr-1" /> Configured
                      </Badge>
                    )}
                  </div>
                  <Input
                    id="github_token"
                    type="password"
                    placeholder={config.has_github_token ? '•••••••• (stored - enter new token to replace)' : 'ghp_...'}
                    value={githubToken}
                    onChange={(e) => setGithubToken(e.target.value)}
                  />
                  <span className="text-11 text-muted-foreground">
                    Token requires <code>repo</code> or <code>workflow</code> dispatch scopes. Encrypted in database.
                  </span>
                </div>
              </div>
            )}

            {config.target_type === 'webhook' && (
              <div className="space-y-3 pt-2">
                <div className="space-y-1.5">
                  <Label htmlFor="webhook_url">Webhook Dispatch URL</Label>
                  <Input
                    id="webhook_url"
                    placeholder="https://api.vercel.com/v1/integrations/deploy/..."
                    value={config.webhook_url || ''}
                    onChange={(e) => setConfig({ ...config, webhook_url: e.target.value })}
                  />
                </div>

                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="webhook_secret">Webhook Secret (Optional HMAC Token)</Label>
                    {config.has_webhook_secret && (
                      <Badge variant="outline" className="text-[10px] text-success">
                        <CheckCircle2 className="h-3 w-3 mr-1" /> Configured
                      </Badge>
                    )}
                  </div>
                  <Input
                    id="webhook_secret"
                    type="password"
                    placeholder={config.has_webhook_secret ? '•••••••• (stored - enter new secret to replace)' : 'Secret key'}
                    value={webhookSecret}
                    onChange={(e) => setWebhookSecret(e.target.value)}
                  />
                </div>
              </div>
            )}

            <div className="flex justify-end pt-2">
              <Button type="submit" loading={saving} data-testid="save-publishing-btn">
                Save Publishing Target
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {/* IndexNow Engine Integration */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Globe className="h-4 w-4 text-primary" />
            <CardTitle>IndexNow Search Engine Ping</CardTitle>
          </div>
          <CardDescription>
            Instantly notify Bing, Yandex, and participating search engines when URLs are published or updated.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <div className="flex items-center justify-between text-13">
            <span>IndexNow Key Verification:</span>
            <Badge variant="outline" className="font-mono">
              {config.indexnow_key ? `${config.indexnow_key.substring(0, 10)}...` : 'Active / Auto-generated'}
            </Badge>
          </div>
          <p className="text-12 text-muted-foreground">
            Search engines automatically fetch verification credentials via your domain.
          </p>
        </CardContent>
      </Card>

      {/* Recent Deployments */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Recent Deployments</CardTitle>
              <CardDescription>Audit history of static generation dispatches and callbacks.</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={loadData} aria-label="Refresh deployments">
              <RefreshCw className="h-3.5 w-3.5" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {deployments.length === 0 ? (
            <p className="text-13 text-muted-foreground py-2">No deployments dispatched yet.</p>
          ) : (
            <div className="space-y-2.5">
              {deployments.map((d) => (
                <div
                  key={d.id}
                  className="p-3 rounded-md border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-13"
                >
                  <div className="space-y-0.5">
                    <div className="flex items-center gap-2">
                      <Badge
                        variant={
                          d.status === 'succeeded'
                            ? 'default'
                            : d.status === 'failed'
                            ? 'destructive'
                            : 'secondary'
                        }
                        className="text-[10px]"
                      >
                        {d.status}
                      </Badge>
                      <span className="font-medium text-foreground">{d.trigger_reason}</span>
                    </div>
                    {d.error && <p className="text-11 text-destructive font-mono pt-0.5">{d.error}</p>}
                  </div>
                  <div className="text-12 text-muted-foreground font-mono flex items-center gap-1 self-end sm:self-center">
                    <Clock className="h-3.5 w-3.5" />
                    {formatAdminDate(d.dispatched_at, {
                      month: 'short',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Step-up Dialog */}
      {stepUpOpen && (
        <Suspense fallback={null}>
          <StepUpDialog
            open={stepUpOpen}
            onOpenChange={setStepUpOpen}
            title="Step-Up Authentication Required"
            description="Enter your credentials to modify production publishing targets."
            onSuccess={() => handleSaveConfig()}
          />
        </Suspense>
      )}
    </div>
  );
};
