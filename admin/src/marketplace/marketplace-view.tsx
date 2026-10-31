import { FC, useState, useEffect } from 'react';
import {
  Breadcrumbs,
  PageContainer,
  PageHeader,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  CardFooter,
  Badge,
  Button,
  Spinner,
  Switch,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Input,
  Label,
} from '@bs/ui';
import {
  Mail,
  Sparkles,
  Activity,
  CheckCircle2,
  Settings as SettingsIcon,
  ExternalLink,
  Puzzle,
  RefreshCw,
} from 'lucide-react';
import { apiClient } from '../lib/api';

export interface PluginItem {
  handle: string;
  name: string;
  version: string;
  description: string;
  author: string;
  category: string;
  icon?: string;
  is_first_party?: boolean;
  features?: string[];
  is_installed: boolean;
  is_enabled: boolean;
  installed_version?: string | null;
  installed_at?: string | null;
  config?: Record<string, unknown> | null;
}

export const MarketplaceView: FC = () => {
  const [plugins, setPlugins] = useState<PluginItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [configPlugin, setConfigPlugin] = useState<PluginItem | null>(null);
  const [configEmail, setConfigEmail] = useState('');
  const [configHoneypot, setConfigHoneypot] = useState(true);

  const fetchPlugins = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<{ data: PluginItem[] }>('GET', '/marketplace/plugins');
      if (res.ok && res.data && Array.isArray(res.data.data)) {
        setPlugins(res.data.data);
      }
    } catch {
      // Keep state
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPlugins();
  }, []);

  const handleInstall = async (handle: string) => {
    setActionLoading(handle);
    try {
      const res = await apiClient.request('POST', `/marketplace/plugins/${handle}/install`);
      if (res.ok) {
        await fetchPlugins();
      }
    } finally {
      setActionLoading(null);
    }
  };

  const handleToggle = async (handle: string, enabled: boolean) => {
    setActionLoading(handle);
    try {
      const res = await apiClient.request('POST', `/marketplace/plugins/${handle}/toggle`, {
        body: { enabled },
      });
      if (res.ok) {
        await fetchPlugins();
      }
    } finally {
      setActionLoading(null);
    }
  };

  const handleOpenConfig = (plugin: PluginItem) => {
    setConfigPlugin(plugin);
    const cfg = plugin.config || {};
    setConfigEmail(String(cfg.default_notify_email || ''));
    setConfigHoneypot(cfg.enable_honeypot !== false);
  };

  const handleSaveConfig = async () => {
    if (!configPlugin) return;
    setActionLoading(configPlugin.handle);
    try {
      const res = await apiClient.request('PUT', `/marketplace/plugins/${configPlugin.handle}/config`, {
        body: {
          config: {
            default_notify_email: configEmail,
            enable_honeypot: configHoneypot,
          },
        },
      });
      if (res.ok) {
        setConfigPlugin(null);
        await fetchPlugins();
      }
    } finally {
      setActionLoading(null);
    }
  };

  const renderIcon = (iconName?: string) => {
    switch (iconName) {
      case 'mail':
        return <Mail className="h-5 w-5 text-primary" />;
      case 'sparkles':
        return <Sparkles className="h-5 w-5 text-amber-500" />;
      case 'activity':
        return <Activity className="h-5 w-5 text-emerald-500" />;
      default:
        return <Puzzle className="h-5 w-5 text-primary" />;
    }
  };

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Marketplace' },
        ]}
      />

      <PageHeader
        title="Plugin Marketplace"
        description="Extend your BS CMS installation with first-party interactive blocks, SEO optimizations, and integrations."
        actions={
          <Button variant="outline" size="sm" onClick={fetchPlugins} disabled={loading}>
            <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
        }
      />

      {loading ? (
        <div className="flex h-64 items-center justify-center">
          <Spinner className="h-8 w-8 text-primary" />
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
          {plugins.map((plugin) => {
            const isContactForm = plugin.handle === 'contact-form';
            const isPending = actionLoading === plugin.handle;

            return (
              <Card key={plugin.handle} className="flex flex-col justify-between border shadow-sm transition-all hover:border-primary/40">
                <CardHeader className="space-y-3 pb-3">
                  <div className="flex items-start justify-between">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      {renderIcon(plugin.icon)}
                    </div>
                    <div className="flex items-center gap-2">
                      {plugin.is_first_party && (
                        <Badge variant="outline" className="border-primary/30 text-primary text-11">
                          Official
                        </Badge>
                      )}
                      {plugin.is_installed ? (
                        plugin.is_enabled ? (
                          <Badge variant="default" className="bg-emerald-600 text-white text-11">
                            Active
                          </Badge>
                        ) : (
                          <Badge variant="secondary" className="text-11">
                            Disabled
                          </Badge>
                        )
                      ) : (
                        <Badge variant="outline" className="text-muted-foreground text-11">
                          Available
                        </Badge>
                      )}
                    </div>
                  </div>

                  <div>
                    <CardTitle className="text-16 font-semibold">{plugin.name}</CardTitle>
                    <p className="mt-1 text-12 text-muted-foreground">
                      v{plugin.version} &bull; By {plugin.author}
                    </p>
                  </div>
                  <CardDescription className="text-13 leading-relaxed text-foreground/80">
                    {plugin.description}
                  </CardDescription>
                </CardHeader>

                <CardContent className="space-y-3 pb-4">
                  {plugin.features && plugin.features.length > 0 && (
                    <div className="space-y-1.5 rounded-md bg-muted/30 p-2.5">
                      <p className="text-11 font-medium uppercase tracking-wider text-muted-foreground">Highlights</p>
                      <ul className="space-y-1 text-12 text-foreground/90">
                        {plugin.features.map((f, idx) => (
                          <li key={idx} className="flex items-center gap-1.5">
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500 shrink-0" />
                            <span>{f}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </CardContent>

                <CardFooter className="flex items-center justify-between border-t bg-muted/10 pt-3">
                  {plugin.is_installed ? (
                    <div className="flex w-full items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Switch
                          id={`toggle-${plugin.handle}`}
                          checked={plugin.is_enabled}
                          disabled={isPending}
                          onCheckedChange={(checked) => handleToggle(plugin.handle, checked)}
                        />
                        <Label htmlFor={`toggle-${plugin.handle}`} className="cursor-pointer text-12 font-medium">
                          {plugin.is_enabled ? 'Enabled' : 'Disabled'}
                        </Label>
                      </div>

                      <div className="flex items-center gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleOpenConfig(plugin)}
                          title="Configure Settings"
                        >
                          <SettingsIcon className="h-4 w-4" />
                        </Button>

                        {isContactForm && plugin.is_enabled && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="text-12"
                            onClick={() => {
                              window.location.href = '/admin/forms';
                            }}
                          >
                            Forms Hub
                            <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                          </Button>
                        )}
                      </div>
                    </div>
                  ) : (
                    <Button
                      variant="default"
                      size="sm"
                      className="w-full"
                      disabled={isPending}
                      onClick={() => handleInstall(plugin.handle)}
                    >
                      {isPending ? (
                        <>
                          <Spinner className="mr-2 h-3.5 w-3.5" />
                          Installing...
                        </>
                      ) : (
                        'Install Plugin'
                      )}
                    </Button>
                  )}
                </CardFooter>
              </Card>
            );
          })}
        </div>
      )}

      {/* Settings Modal */}
      {configPlugin && (
        <Dialog open={Boolean(configPlugin)} onOpenChange={() => setConfigPlugin(null)}>
          <DialogContent className="sm:max-w-[480px]">
            <DialogHeader>
              <DialogTitle>Configure {configPlugin.name}</DialogTitle>
            </DialogHeader>

            <div className="space-y-4 py-3">
              <div className="space-y-1.5">
                <Label htmlFor="cfg-email">Default Notification Recipient Email</Label>
                <Input
                  id="cfg-email"
                  type="email"
                  placeholder="e.g. notifications@example.com"
                  value={configEmail}
                  onChange={(e) => setConfigEmail(e.target.value)}
                />
                <p className="text-12 text-muted-foreground">
                  New submissions can optionally notify this address.
                </p>
              </div>

              <div className="flex items-center justify-between rounded-lg border p-3">
                <div className="space-y-0.5">
                  <Label htmlFor="cfg-hp" className="text-14 font-medium">Honeypot Anti-Spam</Label>
                  <p className="text-12 text-muted-foreground">
                    Silently trap automated form submission bots without bothering real visitors.
                  </p>
                </div>
                <Switch
                  id="cfg-hp"
                  checked={configHoneypot}
                  onCheckedChange={setConfigHoneypot}
                />
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setConfigPlugin(null)}>
                Cancel
              </Button>
              <Button onClick={handleSaveConfig} disabled={Boolean(actionLoading)}>
                {actionLoading ? 'Saving...' : 'Save Configuration'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </PageContainer>
  );
};
