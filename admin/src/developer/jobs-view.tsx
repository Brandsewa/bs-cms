import { FC, useState, useEffect } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  Card,
  EmptyState,
  PageContainer,
  PageHeader,
  Spinner,
} from '@bs/ui';
import { CheckCircle2, XCircle, RotateCcw, Clock, Play, RefreshCw } from 'lucide-react';
import { apiClient } from '../lib/api';
import { formatAdminDate } from '../lib/formatters';
import { problemToast } from '../lib/problem-toast';

export interface JobItem {
  id: number;
  queue: string;
  type: string;
  status: 'queued' | 'reserved' | 'failed' | 'completed';
  attempts: number;
  max_attempts: number;
  available_at: string;
  reserved_at: string | null;
  last_error: string | null;
  created_at: string;
}

interface JobsListResponse {
  items: JobItem[];
  counts: Record<string, number>;
  total: number;
}

export const JobsView: FC = () => {
  const [jobs, setJobs] = useState<JobItem[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string | null>(null);
  const [retryingId, setRetryingId] = useState<number | null>(null);

  const loadJobs = async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<JobsListResponse>('GET', '/jobs', {
        query: statusFilter ? { status: statusFilter } : undefined,
      });
      if (res.ok) {
        setJobs(res.data.items || []);
        setCounts(res.data.counts || {});
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load background jobs' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error connecting to jobs service' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadJobs();
  }, [statusFilter]);

  const handleRetry = async (id: number) => {
    setRetryingId(id);
    try {
      const res = await apiClient.request<unknown>('POST', `/jobs/${id}/retry`);
      if (res.ok) {
        loadJobs();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to retry job' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error retrying job' });
    } finally {
      setRetryingId(null);
    }
  };

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Developer', href: '/admin/developer' },
          { label: 'Background Jobs' },
        ]}
      />

      <PageHeader
        title="Background Jobs & Queues"
        description="Monitor asynchronous tasks, image optimization batches, webhook dispatches, and dead-letter retries."
        actions={
          <Button variant="outline" size="sm" onClick={loadJobs}>
            <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Refresh Queue
          </Button>
        }
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <Card
          className={`p-3.5 cursor-pointer hover:border-primary transition-colors ${statusFilter === null ? 'border-primary ring-1 ring-primary' : ''}`}
          onClick={() => setStatusFilter(null)}
        >
          <p className="text-12 font-medium text-muted-foreground">Total In Queue</p>
          <p className="mt-1 text-20 font-bold tabular-nums text-foreground">
            {Object.values(counts).reduce((a, b) => a + b, 0)}
          </p>
        </Card>

        <Card
          className={`p-3.5 cursor-pointer hover:border-primary transition-colors ${statusFilter === 'queued' ? 'border-primary ring-1 ring-primary' : ''}`}
          onClick={() => setStatusFilter('queued')}
        >
          <p className="text-12 font-medium text-muted-foreground">Queued</p>
          <p className="mt-1 text-20 font-bold tabular-nums text-primary">{counts['queued'] ?? 0}</p>
        </Card>

        <Card
          className={`p-3.5 cursor-pointer hover:border-primary transition-colors ${statusFilter === 'failed' ? 'border-primary ring-1 ring-primary' : ''}`}
          onClick={() => setStatusFilter('failed')}
        >
          <p className="text-12 font-medium text-destructive">Failed (Dead Letter)</p>
          <p className="mt-1 text-20 font-bold tabular-nums text-destructive">{counts['failed'] ?? 0}</p>
        </Card>

        <Card
          className={`p-3.5 cursor-pointer hover:border-primary transition-colors ${statusFilter === 'completed' ? 'border-primary ring-1 ring-primary' : ''}`}
          onClick={() => setStatusFilter('completed')}
        >
          <p className="text-12 font-medium text-success-text">Completed</p>
          <p className="mt-1 text-20 font-bold tabular-nums text-success-text">{counts['completed'] ?? 0}</p>
        </Card>
      </div>

      {loading && jobs.length === 0 ? (
        <div className="flex min-h-[250px] flex-col items-center justify-center gap-3">
          <Spinner size={28} />
          <p className="text-13 text-muted-foreground">Inspecting queue status...</p>
        </div>
      ) : jobs.length === 0 ? (
        <EmptyState
          title="Queue is empty"
          description="No background jobs found matching the selected filter."
        />
      ) : (
        <div className="grid gap-3">
          {jobs.map((job) => (
            <Card key={job.id} className="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  {job.status === 'completed' ? (
                    <CheckCircle2 className="h-4 w-4 text-success" />
                  ) : job.status === 'failed' ? (
                    <XCircle className="h-4 w-4 text-destructive" />
                  ) : job.status === 'reserved' ? (
                    <Play className="h-4 w-4 text-primary animate-pulse" />
                  ) : (
                    <Clock className="h-4 w-4 text-muted-foreground" />
                  )}
                  <span className="font-semibold text-foreground text-14">{job.type}</span>
                  <Badge
                    variant={
                      job.status === 'failed'
                        ? 'destructive'
                        : job.status === 'completed'
                        ? 'default'
                        : 'secondary'
                    }
                    className="text-[10px]"
                  >
                    {job.status}
                  </Badge>
                  <Badge variant="outline" className="text-[10px] font-mono">
                    queue: {job.queue}
                  </Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-12 text-muted-foreground font-mono">
                  <span>
                    Attempts: {job.attempts}/{job.max_attempts}
                  </span>
                  <span>•</span>
                  <span>
                    Available:{' '}
                    {formatAdminDate(job.available_at, {
                      month: 'short',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                      second: '2-digit',
                    })}
                  </span>
                </div>
                {job.last_error && (
                  <p className="text-11 text-destructive font-mono bg-destructive/5 p-2 rounded border border-destructive/20 mt-1 max-w-2xl break-all">
                    {job.last_error}
                  </p>
                )}
              </div>

              {job.status === 'failed' && (
                <div className="self-end md:self-center">
                  <Button
                    variant="outline"
                    size="sm"
                    loading={retryingId === job.id}
                    onClick={() => handleRetry(job.id)}
                    aria-label={`Retry job ${job.id}`}
                  >
                    <RotateCcw className="mr-1.5 h-3.5 w-3.5" /> Requeue Job
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </PageContainer>
  );
};
