import { Card, CardHeader, CardTitle, CardContent, StatusBadge, RelativeTime, Skeleton } from '@bs/ui';
import type { EntryStatus } from '@bs/ui';
import { FileEdit, ArrowRight } from 'lucide-react';
import { parseApiUtcDate } from '../lib/datetime';

export interface RecentEditItem {
  id: string;
  collection: string;
  title: string;
  status: string;
  updated_at: string;
  updated_by?: string | null;
}

interface RecentEditsWidgetProps {
  edits: RecentEditItem[];
  loading?: boolean;
  className?: string;
}

function toEntryStatus(status: string): EntryStatus {
  const allowed: EntryStatus[] = ['draft', 'in_review', 'scheduled', 'published', 'archived', 'trashed', 'ai_created'];
  return (allowed.includes(status as EntryStatus) ? status : 'draft') as EntryStatus;
}

export function RecentEditsWidget({ edits, loading, className }: RecentEditsWidgetProps) {
  return (
    <Card className={`min-w-0 ${className || ''}`} data-testid="recent-edits-widget">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <FileEdit className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-15 font-semibold">Recent edits</CardTitle>
        </div>
        <a
          href="/admin/entries"
          className="inline-flex items-center gap-1 text-12 font-medium text-primary hover:underline"
        >
          View all <ArrowRight className="h-3 w-3" />
        </a>
      </CardHeader>
      <CardContent>
        {loading ? (
          <div className="space-y-3 py-2" aria-hidden="true" data-testid="recent-edits-skeleton">
            {[0, 1, 2].map((i) => (
              <div key={i} className="flex items-center gap-3">
                <Skeleton className="h-4 flex-1" />
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-4 w-16" />
                <Skeleton className="h-4 w-16" />
              </div>
            ))}
          </div>
        ) : edits.length === 0 ? (
          <p className="py-6 text-center text-13 text-muted-foreground">No recent content edits found.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-13" role="table">
              <thead>
                <tr className="border-b border-border/60 text-11 font-medium text-muted-foreground uppercase tracking-wider">
                  <th scope="col" className="pb-2">Title</th>
                  <th scope="col" className="pb-2">Collection</th>
                  <th scope="col" className="pb-2">Status</th>
                  <th scope="col" className="pb-2 text-right">Updated</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/40">
                {edits.map((item) => {
                  const updatedDate = parseApiUtcDate(item.updated_at);
                  return (
                    <tr key={item.id} className="group hover:bg-surface-100/50">
                      <td className="py-2.5 pr-2 font-medium">
                        <a
                          href={`/admin/entries/${item.collection}/${item.id}`}
                          className="text-foreground hover:text-primary hover:underline"
                        >
                          {item.title}
                        </a>
                      </td>
                      <td className="py-2.5 px-2 text-muted-foreground capitalize">
                        {item.collection}
                      </td>
                      <td className="py-2.5 px-2">
                        <StatusBadge status={toEntryStatus(item.status)} />
                      </td>
                      <td className="py-2.5 pl-2 text-right text-12 text-muted-foreground tabular-nums whitespace-nowrap">
                        {updatedDate ? <RelativeTime date={updatedDate} /> : item.updated_at}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
