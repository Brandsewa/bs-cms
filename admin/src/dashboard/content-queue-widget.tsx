import { Card, CardHeader, CardTitle, CardContent, StatusBadge, RelativeTime } from '@bs/ui';
import { FileClock, Calendar, Sparkles, ArrowRight } from 'lucide-react';
import { parseApiUtcDate } from '../lib/datetime';

export interface DraftItem {
  id: string;
  collection: string;
  title: string;
  status: string;
  updated_at: string;
}

export interface ScheduledItem {
  id: string;
  collection: string;
  title: string;
  scheduled_at: string;
}

export interface PendingAiChangeItem {
  id: string;
  actor_label: string;
  action: string;
  target_collection?: string | null;
  target_id?: string | null;
  created_at: string;
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
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <FileClock className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-15 font-semibold">Content queue</CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Scheduled items */}
        <div>
          <div className="flex items-center justify-between text-12 font-medium text-muted-foreground mb-2">
            <span className="flex items-center gap-1.5">
              <Calendar className="h-3.5 w-3.5" /> Scheduled for release
            </span>
            <span className="text-11 tabular-nums">{scheduled.length} queued</span>
          </div>
          {scheduled.length === 0 ? (
            <p className="text-12 text-muted-foreground italic">No items currently scheduled.</p>
          ) : (
            <ul className="space-y-1.5" role="list">
              {scheduled.map((item) => {
                const schedDate = parseApiUtcDate(item.scheduled_at);
                return (
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
                      {schedDate ? <RelativeTime date={schedDate} /> : item.scheduled_at}
                    </span>
                  </li>
                );
              })}
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
              <span className="flex items-center gap-1.5 text-foreground font-semibold">
                <Sparkles className="h-3.5 w-3.5 text-ai" /> Pending AI actions ({pendingAiChanges.length})
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
                {pendingAiChanges.slice(0, 3).map((item) => (
                  <li
                    key={item.id}
                    className="flex items-center justify-between rounded border border-border/60 p-2 text-13 bg-ai/5"
                  >
                    <div className="flex flex-col truncate max-w-[220px]">
                      <span className="font-medium text-foreground truncate">{item.action}</span>
                      <span className="text-11 text-muted-foreground truncate">
                        By {item.actor_label}
                      </span>
                    </div>
                    <span className="text-11 text-muted-foreground tabular-nums">
                      {parseApiUtcDate(item.created_at) ? (
                        <RelativeTime date={parseApiUtcDate(item.created_at)!} />
                      ) : (
                        item.created_at
                      )}
                    </span>
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
