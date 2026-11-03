import { FC, useState, useEffect } from 'react';
import {
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
  Spinner,
} from '@bs/ui';
import { Globe, Rocket, RefreshCw } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

interface PublishingConfig {
  target_type: 'github_actions' | 'webhook' | 'manual';
  repository?: string;
  workflow_file?: string;
  github_token?: string;
  webhook_url?: string;
  debounce_seconds?: number;
  url_patterns?: string[];
  indexnow_key?: string;
  indexnow_status?: string;
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

  const loadData = async () => {
    setLoading(true);
    try {
      const [cfgRes, depRes] = await Promise.all([
        apiClient.request<PublishingConfig>('GET', '/settings/publishing'),
        apiClient.request<Deployment[]>('GET', '/deployments'),
      ]);

      if (cfgRes.ok) setConfig(cfgRes.data);
      if (depRes.ok) setDeployments(depRes.data);
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error loading publishing configuration' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleSaveConfig = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!config) return;
    setSaving(true);
    try {
      const res = await apiClient.request<PublishingConfig>('PUT', '/settings/publishing', {
        body: config,
      });
      if (res.ok) {
        setConfig(res.data);
        alert('Publishing settings saved.');
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to save settings' });
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
    return (
      <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
        <Spinner size={28} />
        <p className="text-13 text-muted-foreground">Loading publishing pipelines...</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Target & Pipeline Setup */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Deployment Target & Webhook Dispatch</CardTitle>
              <CardDescription>
                Optional: pages are published instantly by the built-in PHP renderer. Configure GitHub Actions or webhooks only for an external static-site pipeline.
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
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="target_type">Target Provider</Label>
                <Select
                  value={config.target_type}
                  onValueChange={(val) => setConfig({ ...config, target_type: val as "github_actions" | "webhook" | "manual" })}
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
                <Label htmlFor="debounce_seconds">Debounce Window (Seconds)</Label>
                <Input
                  id="debounce_seconds"
                  type="number"
                  min={5}
                  max={600}
                  value={config.debounce_seconds ?? 30}
                  onChange={(e) => setConfig({ ...config, debounce_seconds: Number(e.target.value) })}
                />
              </div>
            </div>

            {config.target_type === 'github_actions' && (
              <div className="space-y-3 pt-2">
                <div className="space-y-1.5">
                  <Label htmlFor="repository">Repository (owner/repo)</Label>
                  <Input
                    id="repository"
                    placeholder="e.g. Brandsewa/astro-frontend"
                    value={config.repository ?? ''}
                    onChange={(e) => setConfig({ ...config, repository: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="workflow_file">Workflow or Event Type</Label>
                  <Input
                    id="workflow_file"
                    placeholder="e.g. cms-deploy or deploy.yml"
                    value={config.workflow_file ?? ''}
                    onChange={(e) => setConfig({ ...config, workflow_file: e.target.value })}
                  />
                </div>
              </div>
            )}

            {config.target_type === 'webhook' && (
              <div className="space-y-1.5 pt-2">
                <Label htmlFor="webhook_url">Webhook Dispatch URL</Label>
                <Input
                  id="webhook_url"
                  placeholder="https://api.vercel.com/v1/integrations/deploy/..."
                  value={config.webhook_url ?? ''}
                  onChange={(e) => setConfig({ ...config, webhook_url: e.target.value })}
                />
              </div>
            )}

            <div className="flex justify-end pt-2">
              <Button type="submit" loading={saving}>Save Publishing Target</Button>
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
            Instantly notify Bing, Yandex, and participating search engines when URLs are published or deleted.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <div className="flex items-center justify-between text-13">
            <span>IndexNow Key Verification:</span>
            <Badge variant="outline" className="font-mono">
              {config.indexnow_key ? `${config.indexnow_key.substring(0, 10)}...` : 'Auto-generated on publish'}
            </Badge>
          </div>
          <p className="text-12 text-muted-foreground">
            Key verification file is served automatically at <code>/{'{key}'}.txt</code>.
          </p>
        </CardContent>
      </Card>


      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Recent Deployments</CardTitle>
              <CardDescription>Audit history of static generation dispatches and callbacks.</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={loadData}>
              <RefreshCw className="h-3.5 w-3.5" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {deployments.length === 0 ? (
            <p className="text-13 text-muted-foreground">No deployments recorded yet.</p>
          ) : (
            <div className="divide-y divide-border">
              {deployments.slice(0, 10).map((dep) => (
                <div key={dep.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-12">
                  <div className="space-y-0.5">
                    <div className="flex items-center gap-2">
                      <Badge
                        variant={
                          dep.status === 'succeeded'
                            ? 'default'
                            : dep.status === 'failed'
                            ? 'destructive'
                            : 'secondary'
                        }
                        className="text-[10px]"
                      >
                        {dep.status}
                      </Badge>
                      <span className="font-medium text-foreground">{dep.trigger_reason}</span>
                    </div>
                    {dep.error && <p className="text-destructive text-[11px] font-mono">{dep.error}</p>}
                  </div>
                  <div className="text-muted-foreground text-[11px] font-mono shrink-0">
                    {formatAdminDate(dep.dispatched_at, {
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
    </div>
  );
};
