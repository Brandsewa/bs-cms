import { useState } from 'react';
import {
  Alert,
  Button,
  ConfirmationModal,
  MetricCard,
  StatusBadge,
  StatusDot,
  Toaster,
  TypeToConfirmDialog,
  undoToast,
} from '@bs/ui';
import type { GallerySection } from '../registry';

function AlertsShowcase() {
  return (
    <div className="max-w-2xl space-y-3">
      <Alert variant="info" title="Cron is running">Last job tick 5 minutes ago.</Alert>
      <Alert variant="warning" title="Email is not configured">Password resets cannot be delivered until SMTP is set up.</Alert>
      <Alert variant="error" title="Backup failed">The nightly backup could not write to storage.</Alert>
      <Alert variant="success" title="Starter site deployed">The Astro build finished in 42 s.</Alert>
    </div>
  );
}

function StatusShowcase() {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <StatusBadge status="draft" />
        <StatusBadge status="in_review" />
        <StatusBadge status="scheduled" />
        <StatusBadge status="published" />
        <StatusBadge status="archived" />
        <StatusBadge status="trashed" />
        <StatusBadge status="ai_created" />
      </div>
      <div className="flex items-center gap-4 text-13">
        <span className="flex items-center gap-1.5">
          <StatusDot colour="var(--success)" label="Cron healthy" /> Cron
        </span>
        <span className="flex items-center gap-1.5">
          <StatusDot colour="var(--warning)" label="Mail queue backed up" /> Mail
        </span>
        <span className="flex items-center gap-1.5">
          <StatusDot colour="var(--destructive)" label="Webhook disabled after 50 failures" /> Webhooks
        </span>
      </div>
    </div>
  );
}

function MetricsShowcase() {
  return (
    <div className="grid max-w-2xl gap-3 sm:grid-cols-3">
      <MetricCard label="Published entries" value="1,284" trend={{ direction: 'up', label: '+12%' }} hint="this month" />
      <MetricCard label="Pending AI changes" value="3" hint="awaiting approval" />
      <MetricCard label="Entries" value="" loading />
    </div>
  );
}

function ConfirmationsShowcase() {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [typeOpen, setTypeOpen] = useState(false);
  const [toasted, setToasted] = useState(false);
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" onClick={() => setConfirmOpen(true)}>
          Confirmation modal
        </Button>
        <Button variant="destructive" onClick={() => setTypeOpen(true)}>
          Type-to-confirm
        </Button>
        <Button
          variant="secondary"
          onClick={() => {
            setToasted(true);
            undoToast({
              message: 'Entry moved to trash',
              onUndo: () => setToasted(false),
            });
          }}
        >
          Undo toast (soft delete)
        </Button>
      </div>
      {toasted ? <p className="text-13 text-muted-foreground">Trashed — undo within 8 s.</p> : null}

      <ConfirmationModal
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Unpublish 2 entries?"
        body="The public pages will be removed immediately."
        confirmLabel="Unpublish"
        onConfirm={() => setConfirmOpen(false)}
      />
      <TypeToConfirmDialog
        open={typeOpen}
        onOpenChange={setTypeOpen}
        title="Delete collection?"
        body="All 12 entries and their versions are removed permanently."
        phrase="old-campaign"
        onConfirm={() => setTypeOpen(false)}
      />
      <Toaster />
    </div>
  );
}

export const feedbackSection: GallerySection = {
  id: 'feedback',
  name: 'Feedback & status',
  entries: [
    {
      id: 'alerts',
      name: 'Alert / Admonition',
      description: 'Info, warning, error and success banners used inline and in the system banner area.',
      render: () => <AlertsShowcase />,
      props: [
        { name: 'variant', type: "'info' | 'warning' | 'error' | 'success'", defaultValue: "'info'", description: 'Colour semantics.' },
        { name: 'title', type: 'ReactNode', description: 'Bold heading line.' },
      ],
    },
    {
      id: 'status',
      name: 'Status Badge & Status Dot',
      description: 'The six entry status colours; dots for job/mail/webhook health with tooltip labels.',
      render: () => <StatusShowcase />,
      props: [
        { name: 'status (StatusBadge)', type: 'EntryStatus', description: 'draft | in_review | scheduled | published | archived | trashed | ai_created.' },
        { name: 'colour (StatusDot)', type: 'string', description: 'CSS colour or token reference.' },
      ],
    },
    {
      id: 'metrics',
      name: 'Metric Card',
      description: 'Dashboard metric with trend and loading skeleton sized like the content.',
      render: () => <MetricsShowcase />,
      props: [
        { name: 'trend', type: "{ direction: 'up' | 'down' | 'flat'; label: string }", description: 'Colour-coded delta.' },
        { name: 'loading', type: 'boolean', defaultValue: 'false', description: 'Skeleton with aria-busy.' },
      ],
    },
    {
      id: 'confirmations',
      name: 'Confirmation Modal, Type-to-Confirm, Undo toast',
      description: 'Plain confirm for low-risk actions; type-the-name for destructive ones; undo window after soft delete.',
      render: () => <ConfirmationsShowcase />,
      props: [
        { name: 'phrase (TypeToConfirmDialog)', type: 'string', description: 'Exact text required before Confirm enables.' },
        { name: 'onUndo / onExpire (undoToast)', type: '() => void', description: 'Rollback on undo; finalise when the window lapses.' },
      ],
    },
  ],
};
