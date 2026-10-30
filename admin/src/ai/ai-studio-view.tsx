import { FC, useEffect, useState, useCallback } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  PageContainer,
  PageHeader,
  Spinner,
} from '@bs/ui';
import { apiClient } from '../lib/api';
import { problemToast } from '../lib/problem-toast';

interface PendingChange {
  id: string;
  operation: string;
  subject_type: string;
  subject_id?: string | null;
  status?: string;
  actor_type?: string;
  actor_label?: string;
  created_at?: string;
}

interface PendingChangesResponse {
  items: PendingChange[];
  total: number;
  limit: number;
  offset: number;
}

const STATUS_TABS = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'applied', label: 'Applied' },
] as const;

/**
 * AI Studio: reviews changes proposed by AI agents / API actors before they
 * are applied to content. Backed by the pending-changes API.
 */
export const AiStudioView: FC = () => {
  const [items, setItems] = useState<PendingChange[]>([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState<string>('pending');
  const [loading, setLoading] = useState(true);

  const load = useCallback(async (statusKey: string) => {
    setLoading(true);
    try {
      const res = await apiClient.request<PendingChangesResponse>('GET', '/pending-changes', {
        query: { status: statusKey, limit: 50 },
      });
      if (!res.ok) {
        problemToast(res.problem, { fallbackMessage: 'Failed to load pending changes.' });
      } else {
        setItems(res.data?.items || []);
        setTotal(res.data?.total || 0);
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Failed to load pending changes.' });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load(status);
  }, [status, load]);

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'AI Studio' },
        ]}
      />
      <PageHeader
        title="AI Studio & Pending Changes"
        description="Review changes proposed by AI agents and API actors before they are applied to content."
        actions={
          <Button size="sm" variant="outline" onClick={() => load(status)}>
            Refresh
          </Button>
        }
      />

      <div className="flex gap-2">
        {STATUS_TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setStatus(t.key)}
            className={`px-3.5 py-1.5 text-13 rounded-md border transition-colors ${
              status === t.key
                ? 'bg-primary text-primary-foreground border-primary font-medium'
                : 'border-border text-muted-foreground hover:text-foreground hover:bg-muted'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="flex min-h-[240px] flex-col items-center justify-center gap-3">
          <Spinner size={26} />
          <p className="text-13 text-muted-foreground">Loading pending changes...</p>
        </div>
      ) : items.length === 0 ? (
        <Card>
          <CardContent className="py-14 text-center">
            <p className="text-14 font-medium text-foreground">Nothing awaiting review</p>
            <p className="text-13 text-muted-foreground mt-1">
              No {status} changes from AI agents or API actors. Proposals appear here when an
              agent submits content changes through the API or MCP.
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          <p className="text-12 text-muted-foreground">{total} item(s)</p>
          {items.map((item) => (
            <Card key={item.id}>
              <CardContent className="py-4 flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <Badge variant="secondary" className="text-[10px] uppercase font-mono">
                      {item.operation}
                    </Badge>
                    <Badge variant="outline" className="text-[10px]">
                      {item.subject_type}
                    </Badge>
                    {item.status && (
                      <Badge variant="outline" className="text-[10px]">
                        {item.status}
                      </Badge>
                    )}
                  </div>
                  <p className="text-13 text-foreground mt-2 font-mono truncate">
                    {item.subject_id || item.id}
                  </p>
                  <p className="text-11 text-muted-foreground mt-1">
                    {item.actor_label || item.actor_type || 'unknown actor'}
                    {item.created_at ? ` · ${item.created_at}` : ''}
                  </p>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </PageContainer>
  );
};
