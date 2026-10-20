import { Card, CardHeader, CardTitle, CardContent, StatusBadge, Badge } from '@bs/ui';
import { FileClock, Calendar, Sparkles, ArrowRight } from 'lucide-react';

interface DraftItem {
  id: string;
  collection: string;
  title: string;
  status: 'draft';
  updated_at: string;
}

interface ScheduledItem {
  id: string;
  collection: string;
  title: string;
  scheduled_at: string;
}

interface PendingAiChangeItem {
  id: string;
  actor_label: string;
  action: string;
  target_collection?: string;
  target_id?: string;
  created_at: string;
}

function formatDate(iso: string) {
  try {
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  } catch {
    return iso;
  }
}

export function ContentQueueWidget({
  drafts,
  scheduled,
  pendingAiChanges,
  canReviewAi = false,
  className,
}: {
  drafts: DraftItem[];
  scheduled: ScheduledItem[];
  pendingAiChanges: PendingAiChangeItem[];
  canReviewAi?: boolean;
  className?: string;
}) {
  return (
    <Card className={`min-w-0 ${className || ''}`} data-testid="content-queue-widget">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <FileClock className="h-4 w-4 text-muted-foreground" />
            <CardTitle className="text-15 font-semibold">Content queue & waiting</CardTitle>
          </div>
          <span className="text-12 text-muted-foreground tabular-nums">
            {drafts.length} drafts · {scheduled.length} scheduled
          </span>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Scheduled items */}
        <div>
          <div className="flex items-center justify-between text-12 font-medium text-muted-foreground mb-2">
            <span className="flex items-center gap-1.5 text-info">
              <Calendar className="h-3.5 w-3.5" /> Scheduled for release
            </span>
          </div>
          {scheduled.length === 0 ? (
            <p className="text-12 text-muted-foreground italic">No items currently scheduled.</p>
          ) : (
            <ul className="space-y-1.5" role="list">
              {scheduled.map((item) => (
                <li
                  key={item.id}
                  className="flex items-center justify-between rounded border border-border/60 bg-surface-100/30 p-2 text-13"
                >
                  <a
                    href={`/admin/entries/${item.collection}/${item.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline truncate max-w-[240px]"
                  >
                    {item.title}
                  </a>
                  <span className="text-11 text-muted-foreground whitespace-nowrap tabular-nums">
                    {formatDate(item.scheduled_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Drafts waiting */}
        <div>
          <div className="flex items-center justify-between text-12 font-medium text-muted-foreground mb-2">
            <span className="flex items-center gap-1.5">
              <FileClock className="h-3.5 w-3.5" /> Drafts in progress
            </span>
          </div>
          {drafts.length === 0 ? (
            <p className="text-12 text-muted-foreground italic">No drafts waiting.</p>
          ) : (
            <ul className="space-y-1.5" role="list">
              {drafts.slice(0, 3).map((item) => (
                <li
                  key={item.id}
                  className="flex items-center justify-between rounded border border-border/60 p-2 text-13"
                >
                  <a
                    href={`/admin/entries/${item.collection}/${item.id}`}
                    className="font-medium text-foreground hover:text-primary hover:underline truncate max-w-[240px]"
                  >
                    {item.title}
                  </a>
                  <StatusBadge status="draft" />
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Pending AI changes (if role permits viewing AI connections) */}
        {canReviewAi && (
          <div className="pt-2 border-t border-border/60">
            <div className="flex items-center justify-between text-12 font-medium text-muted-foreground mb-2">
              <span className="flex items-center gap-1.5 text-ai">
                <Sparkles className="h-3.5 w-3.5" /> Pending AI actions ({pendingAiChanges.length})
              </span>
              <a
                href="/admin/ai/pending-changes"
                className="inline-flex items-center gap-1 text-11 text-primary hover:underline"
              >
                Review queue <ArrowRight className="h-3 w-3" />
              </a>
            </div>
            {pendingAiChanges.length === 0 ? (
              <p className="text-12 text-muted-foreground italic">No AI changes pending approval.</p>
            ) : (
              <ul className="space-y-1.5" role="list">
                {pendingAiChanges.slice(0, 2).map((item) => (
                  <li
                    key={item.id}
                    className="flex items-center justify-between rounded border border-ai/30 bg-ai/5 p-2 text-13"
                  >
                    <div className="min-w-0">
                      <span className="font-medium text-foreground text-12 block truncate">
                        {item.action} on {item.target_collection || 'entry'}
                      </span>
                      <span className="text-11 text-muted-foreground block truncate">
                        {item.actor_label}
                      </span>
                    </div>
                    <Badge variant="outline" className="border-ai/50 text-ai text-10 uppercase">
                      Pending
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
