import { Button } from '@bs/ui';
import { Plus, Upload, FileText } from 'lucide-react';

interface QuickActionsWidgetProps {
  canCreatePosts?: boolean;
  canCreatePages?: boolean;
  canUploadMedia?: boolean;
  className?: string;
}

export function QuickActionsWidget({
  canCreatePosts = true,
  canCreatePages = true,
  canUploadMedia = true,
  className,
}: QuickActionsWidgetProps) {
  return (
    <div
      className={`flex flex-wrap items-center gap-3 ${className || ''}`}
      data-testid="quick-actions-widget"
    >
      {canCreatePosts && (
        <Button asChild variant="default" size="sm" className="gap-1.5 shadow-sm">
          <a href="/admin/entries/posts/new">
            <Plus className="h-4 w-4" /> New post
          </a>
        </Button>
      )}

      {canCreatePages && (
        <Button asChild variant="outline" size="sm" className="gap-1.5 bg-card">
          <a href="/admin/entries/pages/new">
            <FileText className="h-4 w-4" /> New page
          </a>
        </Button>
      )}

      {canUploadMedia && (
        <Button asChild variant="outline" size="sm" className="gap-1.5 bg-card">
          <a href="/admin/media">
            <Upload className="h-4 w-4" /> Upload media
          </a>
        </Button>
      )}
    </div>
  );
}
