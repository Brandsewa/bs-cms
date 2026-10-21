import { Button } from '@bs/ui';
import { Eye, X } from 'lucide-react';

export interface RolePreviewBannerProps {
  roleName: string;
  onExit: () => void;
}

export function RolePreviewBanner({ roleName, onExit }: RolePreviewBannerProps) {
  return (
    <div
      role="status"
      className="flex items-center justify-between border-b border-primary/40 bg-primary/10 px-4 py-2 text-12 font-medium text-primary"
    >
      <div className="flex items-center gap-2">
        <Eye className="h-4 w-4" />
        <span>
          Previewing BS CMS Studio as <strong className="font-semibold uppercase tracking-wider">{roleName}</strong>
        </span>
      </div>
      <Button
        variant="ghost"
        size="sm"
        onClick={onExit}
        className="h-6 px-2 text-xs text-primary hover:bg-primary/20"
      >
        <X className="h-3 w-3 mr-1" /> Exit Preview
      </Button>
    </div>
  );
}
