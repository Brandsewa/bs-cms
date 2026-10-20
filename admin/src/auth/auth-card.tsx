import React from 'react';

export function AuthCard({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4 sm:p-6">
      <div className="w-full max-w-sm rounded-lg border border-border bg-card p-6 shadow-sm">
        <div className="mb-6 space-y-1 text-center">
          <h1 className="text-xl font-bold tracking-tight text-foreground">{title}</h1>
          {description && <p className="text-13 text-muted-foreground">{description}</p>}
        </div>
        {children}
        {footer && <div className="mt-6 border-t border-border pt-4 text-center text-13 text-muted-foreground">{footer}</div>}
      </div>
    </div>
  );
}
