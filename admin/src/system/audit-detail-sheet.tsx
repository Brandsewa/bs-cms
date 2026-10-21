import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@bs/ui';
import {
  CheckCircle,
  Clock,
  Copy,
  Check,
  FileCode,
  Globe,
  Layers,
  Shield,
  User,
  XCircle,
} from 'lucide-react';
import { DiffViewer } from './diff-viewer';
import type { AuditEntry } from '@bs/client';

export interface AuditDetailSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  entry: AuditEntry | null;
}

export function AuditDetailSheet({ open, onOpenChange, entry }: AuditDetailSheetProps) {
  const [copiedReqId, setCopiedReqId] = useState(false);

  const handleCopyRequestId = () => {
    if (entry?.request_id && navigator?.clipboard) {
      navigator.clipboard.writeText(entry.request_id);
      setCopiedReqId(true);
      setTimeout(() => setCopiedReqId(false), 2000);
    }
  };

  if (!entry) return null;

  const isSuccess = entry.result === 'success';

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="w-full sm:max-w-xl md:max-w-2xl overflow-y-auto p-6 space-y-6"
        data-testid="audit-detail-sheet"
      >
        <SheetHeader className="space-y-2 border-b border-border pb-4">
          <div className="flex items-center gap-2">
            <Badge
              variant={isSuccess ? 'success' : 'destructive'}
              className="text-[11px] font-mono uppercase"
            >
              {isSuccess ? <CheckCircle className="mr-1 h-3 w-3" /> : <XCircle className="mr-1 h-3 w-3" />}
              {entry.result.toUpperCase()}
            </Badge>
            <span className="font-mono text-12 text-muted-foreground">ID #{entry.id}</span>
          </div>

          <SheetTitle className="text-18 font-bold leading-tight text-foreground">
            {entry.summary || entry.action}
          </SheetTitle>

          <SheetDescription className="text-13 text-muted-foreground flex items-center gap-1.5 pt-1">
            <Clock className="h-3.5 w-3.5" />
            <span className="font-mono">{entry.created_at}</span>
          </SheetDescription>
        </SheetHeader>

        {/* Request Correlation & Telemetry */}
        <Card className="bg-surface-100/30 border-border">
          <CardHeader className="py-3 px-4 border-b border-border/50">
            <CardTitle className="text-13 font-semibold flex items-center gap-2">
              <Shield className="h-4 w-4 text-primary" />
              Security Context & Correlation
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-12">
            <div>
              <span className="text-muted-foreground block mb-0.5">Request ID</span>
              <div className="flex items-center gap-1.5">
                <span className="font-mono font-medium text-foreground text-11 truncate max-w-[200px]" title={entry.request_id}>
                  {entry.request_id}
                </span>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-6 w-6 p-0 text-muted-foreground hover:text-foreground"
                  onClick={handleCopyRequestId}
                  title="Copy Request ID"
                >
                  {copiedReqId ? <Check className="h-3 w-3 text-emerald-500" /> : <Copy className="h-3 w-3" />}
                  <span className="sr-only">Copy Request ID</span>
                </Button>
              </div>
            </div>

            <div>
              <span className="text-muted-foreground block mb-0.5">IP Address</span>
              <span className="font-mono text-foreground font-medium flex items-center gap-1">
                <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                {entry.ip || '—'}
              </span>
            </div>

            <div>
              <span className="text-muted-foreground block mb-0.5">Actor</span>
              <span className="text-foreground font-medium flex items-center gap-1">
                <User className="h-3.5 w-3.5 text-muted-foreground" />
                {entry.actor_label || entry.actor_id || 'System'}
                <Badge variant="outline" className="text-[10px] uppercase font-mono ml-1 py-0 px-1">
                  {entry.actor_type}
                </Badge>
              </span>
            </div>

            <div>
              <span className="text-muted-foreground block mb-0.5">Subject</span>
              <span className="text-foreground font-medium flex items-center gap-1">
                <Layers className="h-3.5 w-3.5 text-muted-foreground" />
                {entry.subject_type}
                {entry.collection_key ? ` (${entry.collection_key})` : ''}
                {entry.subject_id ? `: #${entry.subject_id}` : ''}
              </span>
            </div>

            {entry.user_agent && (
              <div className="sm:col-span-2">
                <span className="text-muted-foreground block mb-0.5">User Agent</span>
                <span className="font-mono text-11 text-muted-foreground break-all bg-background/50 p-1.5 rounded border border-border/50 block">
                  {entry.user_agent}
                </span>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Diff View Section */}
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <FileCode className="h-4 w-4 text-primary" />
            <h3 className="text-14 font-semibold text-foreground">Mutation Diff & Payload</h3>
          </div>

          <DiffViewer diff={entry.diff} />
        </div>
      </SheetContent>
    </Sheet>
  );
}
