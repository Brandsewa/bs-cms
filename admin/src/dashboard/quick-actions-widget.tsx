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
      className={`flex flex-wrap items-center gap-2 ${className || ''}`}
      data-testid="quick-actions-widget"
    >
      {canCreatePosts && (
        <Button asChild variant="default" size="sm" className="h-7 gap-1.5 px-2.5 text-xs font-medium shadow-2xs">
          <a href="/admin/entries/posts/new">
            <Plus className="h-3.5 w-3.5" /> New post
          </a>
        </Button>
      )}

      {canCreatePages && (
        <Button asChild variant="outline" size="sm" className="h-7 gap-1.5 px-2.5 text-xs font-medium bg-card">
          <a href="/admin/entries/pages/new">
            <FileText className="h-3.5 w-3.5" /> New page
          </a>
        </Button>
      )}

      {canUploadMedia && (
        <Button asChild variant="outline" size="sm" className="h-7 gap-1.5 px-2.5 text-xs font-medium bg-card">
          <a href="/admin/media">
            <Upload className="h-3.5 w-3.5" /> Upload media
          </a>
        </Button>
      )}
    </div>
  );
}
