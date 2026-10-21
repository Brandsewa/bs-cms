import { useMemo, useState } from 'react';
import { Badge, Button } from '@bs/ui';
import { ChevronDown, ChevronRight, Copy, Check } from 'lucide-react';

export interface DiffViewerProps {
  diff: Record<string, unknown> | null | undefined;
  className?: string;
}

interface FieldDiff {
  key: string;
  before: unknown;
  after: unknown;
  type: 'added' | 'removed' | 'modified' | 'unchanged';
}

function formatValue(val: unknown): string {
  if (val === null) return 'null';
  if (val === undefined) return 'undefined';
  if (typeof val === 'string') return val;
  if (typeof val === 'number' || typeof val === 'boolean') return String(val);
  try {
    return JSON.stringify(val, null, 2);
  } catch {
    return String(val);
  }
}

/**
 * Computes changed fields from an audit diff structure.
 * Supports standard diff formats:
 * 1) { before: {...}, after: {...} }
 * 2) { before: {...} } (e.g. deletion / trash)
 * 3) { after: {...} } (e.g. creation)
 * 4) Arbitrary key-value change objects
 */
function extractFieldDiffs(diff: Record<string, unknown> | null | undefined): FieldDiff[] {
  if (!diff || typeof diff !== 'object') return [];

  const hasBefore = 'before' in diff && typeof diff.before === 'object' && diff.before !== null;
  const hasAfter = 'after' in diff && typeof diff.after === 'object' && diff.after !== null;

  if (hasBefore || hasAfter) {
    const beforeObj = (hasBefore ? diff.before : {}) as Record<string, unknown>;
    const afterObj = (hasAfter ? diff.after : {}) as Record<string, unknown>;

    const allKeys = Array.from(new Set([...Object.keys(beforeObj), ...Object.keys(afterObj)])).sort();

    return allKeys.map((key) => {
      const inBefore = key in beforeObj;
      const inAfter = key in afterObj;
      const beforeVal = beforeObj[key];
      const afterVal = afterObj[key];

      let type: FieldDiff['type'] = 'unchanged';
      if (!inBefore && inAfter) {
        type = 'added';
      } else if (inBefore && !inAfter) {
        type = 'removed';
      } else {
        const beforeStr = JSON.stringify(beforeVal);
        const afterStr = JSON.stringify(afterVal);
        if (beforeStr !== afterStr) {
          type = 'modified';
        }
      }

      return {
        key,
        before: beforeVal,
        after: afterVal,
        type,
      };
    });
  }

  // Fallback for flat change key-value map
  return Object.keys(diff)
    .sort()
    .map((key) => ({
      key,
      before: undefined,
      after: diff[key],
      type: 'modified',
    }));
}

export function DiffViewer({ diff, className = '' }: DiffViewerProps) {
  const [viewMode, setViewMode] = useState<'visual' | 'raw'>('visual');
  const [showUnchanged, setShowUnchanged] = useState(false);
  const [copied, setCopied] = useState(false);
  const [expandedKeys, setExpandedKeys] = useState<Record<string, boolean>>({});

  const fieldDiffs = useMemo(() => extractFieldDiffs(diff), [diff]);

  const changedFields = useMemo(
    () => fieldDiffs.filter((f) => f.type !== 'unchanged'),
    [fieldDiffs]
  );

  const displayedFields = showUnchanged ? fieldDiffs : changedFields;

  const rawJson = useMemo(() => {
    try {
      return JSON.stringify(diff, null, 2);
    } catch {
      return String(diff);
    }
  }, [diff]);

  const handleCopy = () => {
    if (navigator?.clipboard) {
      navigator.clipboard.writeText(rawJson);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const toggleExpand = (key: string) => {
    setExpandedKeys((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  if (!diff || Object.keys(diff).length === 0) {
    return (
      <div className={`p-4 text-center rounded-md border border-border bg-surface-100/30 text-muted-foreground text-13 ${className}`}>
        No diff payload recorded for this operation.
      </div>
    );
  }

  return (
    <div className={`space-y-3 ${className}`} data-testid="audit-diff-viewer">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-1.5">
          <Button
            type="button"
            size="sm"
            variant={viewMode === 'visual' ? 'secondary' : 'ghost'}
            className="h-7 text-12 px-2.5"
            onClick={() => setViewMode('visual')}
          >
            Visual Diff ({changedFields.length} changed)
          </Button>
          <Button
            type="button"
            size="sm"
            variant={viewMode === 'raw' ? 'secondary' : 'ghost'}
            className="h-7 text-12 px-2.5"
            onClick={() => setViewMode('raw')}
          >
            Raw JSON
          </Button>
        </div>

        <div className="flex items-center gap-2">
          {viewMode === 'visual' && fieldDiffs.length > changedFields.length && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-7 text-11 px-2 text-muted-foreground"
              onClick={() => setShowUnchanged((v) => !v)}
            >
              {showUnchanged ? 'Hide unchanged' : `Show all (${fieldDiffs.length})`}
            </Button>
          )}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-11 px-2 gap-1 text-muted-foreground"
            onClick={handleCopy}
            title="Copy payload"
          >
            {copied ? <Check className="h-3 w-3 text-emerald-500" /> : <Copy className="h-3 w-3" />}
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </div>
      </div>

      {viewMode === 'raw' ? (
        <div className="relative rounded-md border border-border bg-surface-200/50 p-3 max-h-96 overflow-auto font-mono text-12 text-foreground">
          <pre className="whitespace-pre-wrap break-all leading-relaxed">{rawJson}</pre>
        </div>
      ) : (
        <div className="space-y-2 max-h-[32rem] overflow-y-auto pr-1">
          {displayedFields.length === 0 ? (
            <p className="text-12 text-muted-foreground italic py-2">
              No field-level differences detected between before and after snapshots.
            </p>
          ) : (
            displayedFields.map((field) => {
              const isExpanded = expandedKeys[field.key] ?? false;
              const beforeStr = formatValue(field.before);
              const afterStr = formatValue(field.after);
              const isComplex = beforeStr.includes('\n') || afterStr.includes('\n') || beforeStr.length > 60 || afterStr.length > 60;

              return (
                <div
                  key={field.key}
                  className="rounded-md border border-border bg-card overflow-hidden text-12"
                >
                  <div
                    className={`flex items-center justify-between px-3 py-2 border-b border-border/50 ${
                      field.type === 'added'
                        ? 'bg-emerald-500/10'
                        : field.type === 'removed'
                          ? 'bg-destructive/10'
                          : field.type === 'modified'
                            ? 'bg-amber-500/10'
                            : 'bg-muted/30'
                    }`}
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      {isComplex && (
                        <button
                          type="button"
                          onClick={() => toggleExpand(field.key)}
                          className="text-muted-foreground hover:text-foreground"
                          aria-label={`Toggle field ${field.key}`}
                        >
                          {isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                        </button>
                      )}
                      <span className="font-mono font-medium text-foreground truncate">{field.key}</span>
                    </div>

                    <Badge
                      variant={
                        field.type === 'added'
                          ? 'success'
                          : field.type === 'removed'
                            ? 'destructive'
                            : field.type === 'modified'
                              ? 'warning'
                              : 'secondary'
                      }
                      className="text-[10px] uppercase font-mono px-1.5 py-0 h-4"
                    >
                      {field.type}
                    </Badge>
                  </div>

                  <div className="p-2.5">
                    {field.type === 'added' ? (
                      <div className="rounded bg-emerald-500/10 border border-emerald-500/20 p-2 font-mono text-12 text-emerald-800 dark:text-emerald-300 break-all whitespace-pre-wrap max-h-48 overflow-y-auto">
                        + {afterStr}
                      </div>
                    ) : field.type === 'removed' ? (
                      <div className="rounded bg-destructive/10 border border-destructive/20 p-2 font-mono text-12 text-destructive break-all whitespace-pre-wrap max-h-48 overflow-y-auto line-through opacity-80">
                        - {beforeStr}
                      </div>
                    ) : field.type === 'modified' ? (
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <div className="space-y-1">
                          <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Before</span>
                          <div className="rounded bg-destructive/10 border border-destructive/20 p-2 font-mono text-12 text-destructive break-all whitespace-pre-wrap max-h-48 overflow-y-auto">
                            {beforeStr}
                          </div>
                        </div>
                        <div className="space-y-1">
                          <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">After</span>
                          <div className="rounded bg-emerald-500/10 border border-emerald-500/20 p-2 font-mono text-12 text-emerald-800 dark:text-emerald-300 break-all whitespace-pre-wrap max-h-48 overflow-y-auto">
                            {afterStr}
                          </div>
                        </div>
                      </div>
                    ) : (
                      <div className="rounded bg-muted/40 p-2 font-mono text-12 text-muted-foreground break-all whitespace-pre-wrap max-h-36 overflow-y-auto">
                        {afterStr}
                      </div>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}
    </div>
  );
}
