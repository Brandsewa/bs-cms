import { useState } from 'react';
import { Button, ErrorState } from '@bs/ui';
import { Copy, CheckCircle2 } from 'lucide-react';
import React from 'react';

interface ErrorBoundaryProps {
  children: React.ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
  requestId: string | null;
}

export class ShellErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null, requestId: null };
  }

  static getDerivedStateFromError(error: Error): Partial<ErrorBoundaryState> {
    // Generate or extract a traceable client error ID
    const genId = 'ERR-' + Math.random().toString(36).substring(2, 10).toUpperCase();
    return { hasError: true, error, requestId: genId };
  }

  override componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
    console.error('Shell Error Boundary caught an error:', error, errorInfo);
  }

  handleReset = () => {
    this.setState({ hasError: false, error: null, requestId: null });
    window.location.reload();
  };

  override render() {
    if (this.state.hasError) {
      return (
        <ErrorFallback
          error={this.state.error}
          requestId={this.state.requestId}
          onReset={this.handleReset}
        />
      );
    }

    return this.props.children;
  }
}

function ErrorFallback({
  error,
  requestId,
  onReset,
}: {
  error: Error | null;
  requestId: string | null;
  onReset: () => void;
}) {
  const [copied, setCopied] = useState(false);

  const handleCopyId = () => {
    if (requestId) {
      void navigator.clipboard.writeText(requestId);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  return (
    <div className="flex min-h-[500px] flex-col items-center justify-center p-6">
      <div className="w-full max-w-md space-y-4">
        <ErrorState
          title="Something went wrong"
          message={error?.message || 'An unexpected internal application error occurred.'}
          onRetry={onReset}
        />

        {requestId && (
          <div className="rounded-md border border-border bg-surface-100 p-3 text-center space-y-2">
            <p className="text-11 text-muted-foreground">Error Reference / Request ID:</p>
            <div className="flex items-center justify-center gap-2">
              <code className="rounded bg-surface-200 px-2 py-0.5 font-mono text-12 font-semibold text-foreground">
                {requestId}
              </code>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs"
                onClick={handleCopyId}
                aria-label="Copy request id"
              >
                {copied ? (
                  <>
                    <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500 mr-1" /> Copied
                  </>
                ) : (
                  <>
                    <Copy className="h-3.5 w-3.5 mr-1" /> Copy ID
                  </>
                )}
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
