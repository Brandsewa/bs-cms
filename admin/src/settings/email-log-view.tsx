import { FC, useState, useEffect } from 'react';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  Input,
  Spinner,
} from '@bs/ui';
import { RefreshCw, CheckCircle2, XCircle, Search } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

export interface EmailLogEntry {
  id: number;
  to_email: string;
  subject: string;
  template: string;
  status: 'sent' | 'failed';
  provider: string;
  error: string | null;
  created_at: string;
}

interface EmailLogResponse {
  items: EmailLogEntry[];
  next_cursor?: number | null;
}

export const EmailLogView: FC = () => {
  const [logs, setLogs] = useState<EmailLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [resendingId, setResendingId] = useState<number | null>(null);

  const loadLogs = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<EmailLogResponse | EmailLogEntry[]>('GET', '/settings/email/log', {
        query: search ? { to_email: search } : undefined,
      });
      if (res.ok) {
        if (Array.isArray(res.data)) {
          setLogs(res.data);
        } else if (res.data && Array.isArray(res.data.items)) {
          setLogs(res.data.items);
        } else {
          setLogs([]);
        }
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load email logs' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error loading email logs' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadLogs();
  }, []);

  const handleResend = async (id: number) => {
    setResendingId(id);
    try {
      const res = await apiClient.request<unknown>('POST', `/email-log/${id}/resend`);
      if (res.ok) {
        loadLogs();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to resend email' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error resending email' });
    } finally {
      setResendingId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-16 font-semibold text-foreground">Transactional Email Logs</h2>
          <p className="text-13 text-muted-foreground">
            Audit outbound messages, delivery states, template IDs, and manual retries.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div className="relative w-64">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search recipient..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && loadLogs()}
              className="pl-8 text-12"
            />
          </div>
          <Button variant="outline" size="sm" onClick={loadLogs} aria-label="Refresh logs">
            <RefreshCw className="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>

      {loading && logs.length === 0 ? (
        <div className="flex min-h-[200px] flex-col items-center justify-center gap-3">
          <Spinner size={24} />
          <p className="text-13 text-muted-foreground">Loading email audit records...</p>
        </div>
      ) : logs.length === 0 ? (
        <EmptyState
          title="No email logs found"
          description="Outbound transactional messages will be displayed here."
        />
      ) : (
        <div className="grid gap-3">
          {logs.map((entry) => (
            <Card key={entry.id} className="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  {entry.status === 'sent' ? (
                    <CheckCircle2 className="h-4 w-4 text-success" />
                  ) : (
                    <XCircle className="h-4 w-4 text-destructive" />
                  )}
                  <span className="font-medium text-foreground text-14">{entry.subject}</span>
                  <Badge variant={entry.status === 'sent' ? 'default' : 'destructive'} className="text-[10px]">
                    {entry.status}
                  </Badge>
                  <Badge variant="outline" className="text-[10px] font-mono">
                    {entry.provider}
                  </Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-12 text-muted-foreground">
                  <span className="font-mono text-foreground">{entry.to_email}</span>
                  <span>•</span>
                  <span>Template: <code className="font-mono">{entry.template}</code></span>
                  <span>•</span>
                  <span>
                    Sent:{' '}
                    {formatAdminDate(entry.created_at, {
                      month: 'short',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                    })}
                  </span>
                </div>
                {entry.error && (
                  <p className="text-11 text-destructive font-mono pt-1">{entry.error}</p>
                )}
              </div>

              <div className="self-end md:self-center">
                <Button
                  variant="outline"
                  size="sm"
                  loading={resendingId === entry.id}
                  onClick={() => handleResend(entry.id)}
                  aria-label={`Resend email to ${entry.to_email}`}
                >
                  <RefreshCw className="h-3.5 w-3.5 mr-1.5" /> Resend
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
};
