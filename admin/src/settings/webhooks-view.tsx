import { FC, useState, useEffect } from 'react';
import {
  Badge,
  Button,
  Card,
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
  Switch,
} from '@bs/ui';
import { Webhook, Plus, Trash2, Send, History, CheckCircle2, XCircle, AlertTriangle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

export interface WebhookItem {
  id: string;
  name: string;
  url: string;
  events: string[];
  collections: string[] | null;
  is_active: number;
  created_at: string;
}

export interface WebhookDelivery {
  id: number;
  webhook_id: string;
  event: string;
  status: 'pending' | 'success' | 'failed';
  response_code: number | null;
  error: string | null;
  duration_ms: number | null;
  created_at: string;
}

export const WebhooksView: FC = () => {
  const [webhooks, setWebhooks] = useState<WebhookItem[]>([]);
  const [loading, setLoading] = useState(true);

  // Dialog State
  const [createOpen, setCreateOpen] = useState(false);
  const [createLoading, setCreateLoading] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [eventsStr, setEventsStr] = useState('*');
  const [isActive, setIsActive] = useState(true);

  // Deliveries log state
  const [deliveriesOpen, setDeliveriesOpen] = useState(false);
  const [currentWebhook, setCurrentWebhook] = useState<WebhookItem | null>(null);
  const [deliveries, setDeliveries] = useState<WebhookDelivery[]>([]);
  const [deliveriesLoading, setDeliveriesLoading] = useState(false);
  const [retryingId, setRetryingId] = useState<number | null>(null);

  const loadWebhooks = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<WebhookItem[]>('GET', '/webhooks');
      if (res.ok) {
        setWebhooks(res.data);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load webhooks' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error loading webhooks' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadWebhooks();
  }, []);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreateLoading(true);

    try {
      const payload = {
        name,
        url,
        events: eventsStr.split(',').map((e) => e.trim()).filter(Boolean),
        is_active: isActive ? 1 : 0,
      };

      if (editingId) {
        const res = await apiClient.request('PUT', `/webhooks/${editingId}`, {
          body: payload,
        });
        if (res.ok) {
          setCreateOpen(false);
          loadWebhooks();
        } else {
          problemToast(res.problem, { fallbackMessage: 'Failed to update webhook' });
        }
      } else {
        const res = await apiClient.request('POST', '/webhooks', {
          body: payload,
        });
        if (res.ok) {
          setCreateOpen(false);
          loadWebhooks();
        } else {
          problemToast(res.problem, { fallbackMessage: 'Failed to create webhook' });
        }
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error saving webhook' });
    } finally {
      setCreateLoading(false);
    }
  };

  const handleDelete = async (id: string) => {
    if (!confirm('Are you sure you want to delete this webhook?')) return;
    try {
      const res = await apiClient.request('DELETE', `/webhooks/${id}`);
      if (res.ok) {
        loadWebhooks();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to delete webhook' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error deleting webhook' });
    }
  };

  const handleTestPing = async (id: string) => {
    try {
      const res = await apiClient.request<{ response_code?: number }>('POST', `/webhooks/${id}/test`);
      if (res.ok) {
        alert(`Test ping dispatched! Response code: ${res.data?.response_code ?? 'Sent'}`);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to trigger test ping' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error triggering test ping' });
    }
  };

  const handleViewDeliveries = async (item: WebhookItem) => {
    setCurrentWebhook(item);
    setDeliveriesOpen(true);
    setDeliveriesLoading(true);

    try {
      const res = await apiClient.request<WebhookDelivery[]>('GET', `/webhooks/${item.id}/deliveries`);
      if (res.ok) {
        setDeliveries(res.data);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load deliveries' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error loading deliveries' });
    } finally {
      setDeliveriesLoading(false);
    }
  };

  const handleRetryDelivery = async (deliveryId: number) => {
    setRetryingId(deliveryId);
    try {
      const res = await apiClient.request<unknown>('POST', `/deliveries/${deliveryId}/retry`);
      if (res.ok && currentWebhook) {
        // Refresh deliveries list
        const refresh = await apiClient.request<WebhookDelivery[]>('GET', `/webhooks/${currentWebhook.id}/deliveries`);
        if (refresh.ok) {
          setDeliveries(refresh.data);
        }
      } else if (!res.ok) {
        problemToast(res.problem, { fallbackMessage: 'Failed to retry delivery' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error retrying delivery' });
    } finally {
      setRetryingId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-16 font-semibold text-foreground">Outbound Webhooks</h2>
          <p className="text-13 text-muted-foreground">
            Stream CMS mutation events to external microservices, build pipelines, or serverless functions.
          </p>
        </div>
        <Button
          onClick={() => {
            setEditingId(null);
            setName('');
            setUrl('');
            setEventsStr('*');
            setIsActive(true);
            setCreateOpen(true);
          }}
          data-testid="create-webhook-btn"
        >
          <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Webhook
        </Button>
      </div>

      {loading && webhooks.length === 0 ? (
        <div className="flex min-h-[200px] flex-col items-center justify-center gap-3">
          <Spinner size={24} />
          <p className="text-13 text-muted-foreground">Loading webhooks...</p>
        </div>
      ) : webhooks.length === 0 ? (
        <EmptyState
          title="No webhooks registered"
          description="Trigger external HTTP POST requests whenever content or media entries change."
          action={
            <Button size="sm" onClick={() => setCreateOpen(true)}>
              <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Webhook
            </Button>
          }
        />
      ) : (
        <div className="grid gap-3">
          {webhooks.map((wh) => (
            <Card key={wh.id} className="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <Webhook className="h-4 w-4 text-primary" />
                  <span className="font-medium text-foreground text-14">{wh.name}</span>
                  <Badge variant={wh.is_active ? 'default' : 'secondary'} className="text-[10px]">
                    {wh.is_active ? 'Active' : 'Disabled'}
                  </Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-12 text-muted-foreground font-mono">
                  <span className="truncate max-w-sm text-foreground-light">{wh.url}</span>
                  <span>•</span>
                  <span>Events: {wh.events.join(', ')}</span>
                </div>
              </div>

              <div className="flex items-center gap-2 self-end md:self-center">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => handleTestPing(wh.id)}
                  aria-label={`Test ping ${wh.name}`}
                >
                  <Send className="h-3.5 w-3.5 mr-1.5" /> Test
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => handleViewDeliveries(wh)}
                  aria-label={`View deliveries for ${wh.name}`}
                >
                  <History className="h-3.5 w-3.5 mr-1.5" /> Logs
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setEditingId(wh.id);
                    setName(wh.name);
                    setUrl(wh.url);
                    setEventsStr(wh.events.join(', '));
                    setIsActive(wh.is_active === 1);
                    setCreateOpen(true);
                  }}
                >
                  Edit
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="text-destructive hover:bg-destructive/10"
                  onClick={() => handleDelete(wh.id)}
                  aria-label={`Delete ${wh.name}`}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Create / Edit Dialog */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-md">
          <form onSubmit={handleSave} className="space-y-4">
            <DialogHeader>
              <DialogTitle>{editingId ? 'Edit Webhook' : 'New Webhook'}</DialogTitle>
              <DialogDescription>
                Deliver signed HMAC-SHA256 event payloads to your endpoint.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3.5 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="wh_name">Name</Label>
                <Input
                  id="wh_name"
                  placeholder="e.g. Astro Revalidation Hook"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="wh_url">Endpoint URL</Label>
                <Input
                  id="wh_url"
                  placeholder="https://api.domain.com/webhooks/bscms"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="wh_events">Subscribed Events (Comma separated)</Label>
                <Input
                  id="wh_events"
                  placeholder="*, entry.published, entry.deleted"
                  value={eventsStr}
                  onChange={(e) => setEventsStr(e.target.value)}
                />
              </div>

              <div className="flex items-center justify-between pt-2">
                <Label htmlFor="wh_active" className="cursor-pointer">Active State</Label>
                <Switch id="wh_active" checked={isActive} onCheckedChange={setIsActive} />
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" loading={createLoading}>
                {editingId ? 'Save Changes' : 'Create Webhook'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Deliveries Log Drawer/Dialog */}
      <Dialog open={deliveriesOpen} onOpenChange={setDeliveriesOpen}>
        <DialogContent className="max-w-2xl">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Webhook Deliveries: {currentWebhook?.name}</DialogTitle>
              <DialogDescription>
                Recent HTTP delivery attempts, status codes, and manual replay.
              </DialogDescription>
            </DialogHeader>

            {deliveriesLoading ? (
              <div className="flex min-h-[150px] items-center justify-center">
                <Spinner size={24} />
              </div>
            ) : deliveries.length === 0 ? (
              <p className="text-13 text-muted-foreground py-6 text-center">
                No delivery logs recorded for this webhook yet.
              </p>
            ) : (
              <div className="max-h-96 overflow-y-auto divide-y divide-border rounded-md border border-border">
                {deliveries.map((del) => (
                  <div key={del.id} className="p-3 flex items-center justify-between gap-3 text-12 font-mono">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        {del.status === 'success' ? (
                          <CheckCircle2 className="h-4 w-4 text-success" />
                        ) : del.status === 'failed' ? (
                          <XCircle className="h-4 w-4 text-destructive" />
                        ) : (
                          <AlertTriangle className="h-4 w-4 text-warning" />
                        )}
                        <span className="font-semibold text-foreground">{del.event}</span>
                        <Badge variant="outline" className="text-[10px]">
                          {del.response_code ?? 'ERR'}
                        </Badge>
                        {del.duration_ms !== null && (
                          <span className="text-muted-foreground">{del.duration_ms}ms</span>
                        )}
                      </div>
                      {del.error && <p className="text-destructive text-[11px] font-sans">{del.error}</p>}
                      <span className="text-muted-foreground text-[10px]">
                        {formatAdminDate(del.created_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                      </span>
                    </div>

                    <Button
                      variant="outline"
                      size="sm"
                      loading={retryingId === del.id}
                      onClick={() => handleRetryDelivery(del.id)}
                    >
                      Retry
                    </Button>
                  </div>
                ))}
              </div>
            )}

            <DialogFooter>
              <Button onClick={() => setDeliveriesOpen(false)}>Close</Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
};
