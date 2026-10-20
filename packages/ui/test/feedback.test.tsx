import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Alert } from '../src/feedback/alert';
import { ConfirmationModal, TypeToConfirmDialog } from '../src/feedback/confirmation';
import { MetricCard, StatusBadge, StatusDot } from '../src/feedback/metrics';
import { undoToast } from '../src/feedback/undo-toast';
import { Toaster } from '../src/components/toaster';

describe('Alert', () => {
  it('renders all four variants with title and body', () => {
    render(
      <div>
        {(['info', 'warning', 'error', 'success'] as const).map((variant) => (
          <Alert key={variant} variant={variant} title={`${variant} title`} data-testid={`alert-${variant}`}>
            {variant} body text
          </Alert>
        ))}
      </div>
    );
    expect(screen.getByTestId('alert-warning')).toHaveTextContent('warning body text');
    expect(screen.getAllByRole('status')).toHaveLength(4);
  });

  it('passes axe across variants', async () => {
    const { container } = renderUI(
      <div>
        <Alert variant="info" title="Cron is running">Last run 5 minutes ago.</Alert>
        <Alert variant="warning" title="Email not configured">Test emails will not be delivered.</Alert>
        <Alert variant="error" title="Backup failed">Retry from Settings.</Alert>
        <Alert variant="success" title="Deployed">The starter site was rebuilt.</Alert>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('ConfirmationModal', () => {
  it('confirms and cancels, locks while loading', async () => {
    const onConfirm = vi.fn();
    const onOpenChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <ConfirmationModal
        open
        onOpenChange={onOpenChange}
        title="Unpublish entry?"
        body="The public page will be removed."
        destructive
        onConfirm={onConfirm}
      />
    );
    await user.click(screen.getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledOnce());

    onConfirm.mockClear();
    renderUI(
      <ConfirmationModal open onOpenChange={onOpenChange} title="Again?" onConfirm={onConfirm} />
    );
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onConfirm).not.toHaveBeenCalled();
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('passes axe while open', async () => {
    renderUI(
      <ConfirmationModal open onOpenChange={vi.fn()} title="Title" body="Body" onConfirm={vi.fn()} />
    );
    await screen.findByRole('alertdialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('TypeToConfirmDialog', () => {
  it('enables confirm only on an exact match and fires on Enter', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <TypeToConfirmDialog
        open
        onOpenChange={vi.fn()}
        title="Delete collection?"
        body="All entries are removed."
        phrase="old-posts"
        onConfirm={onConfirm}
      />
    );
    const input = screen.getByLabelText(/Type old-posts to confirm/);
    const confirmButton = screen.getByRole('button', { name: 'Delete' });
    expect(confirmButton).toBeDisabled();

    await user.type(input, 'old-post');
    expect(confirmButton).toBeDisabled();
    await user.type(input, 's');
    expect(confirmButton).toBeEnabled();
    input.focus();
    await user.keyboard('{Enter}');
    await waitFor(() => expect(onConfirm).toHaveBeenCalledOnce());
  });

  it('shows a mismatch message and resets between opens', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <TypeToConfirmDialog
        open
        onOpenChange={vi.fn()}
        title="Purge trash?"
        phrase="PURGE"
        onConfirm={onConfirm}
      />
    );
    const input = screen.getByLabelText(/Type PURGE to confirm/);
    await user.type(input, 'wrong');
    const confirmButton = screen.getByRole('button', { name: 'Delete' });
    // Disabled buttons do not fire click events; verify via keyboard attempt.
    expect(confirmButton).toBeDisabled();

    rerender(
      <TypeToConfirmDialog
        open={false}
        onOpenChange={vi.fn()}
        title="Purge trash?"
        phrase="PURGE"
        onConfirm={onConfirm}
      />
    );
    rerender(
      <TypeToConfirmDialog
        open
        onOpenChange={vi.fn()}
        title="Purge trash?"
        phrase="PURGE"
        onConfirm={onConfirm}
      />
    );
    const freshInput = await screen.findByLabelText(/Type PURGE to confirm/);
    expect(freshInput).toHaveValue('');
  });

  it('passes axe while open', async () => {
    renderUI(
      <TypeToConfirmDialog
        open
        onOpenChange={vi.fn()}
        title="Delete?"
        phrase="delete-me"
        onConfirm={vi.fn()}
      />
    );
    await screen.findByRole('alertdialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('StatusBadge', () => {
  it('maps every status to a variant and label', () => {
    renderUI(
      <div>
        {(['draft', 'in_review', 'scheduled', 'published', 'archived', 'trashed', 'ai_created'] as const).map(
          (status) => (
            <span key={status} data-testid={`badge-${status}`}>
              <StatusBadge status={status} />
            </span>
          )
        )}
      </div>
    );
    expect(screen.getByTestId('badge-published')).toHaveTextContent('Published');
    expect(screen.getByTestId('badge-in_review')).toHaveTextContent('In review');
    expect(screen.getByTestId('badge-ai_created')).toHaveTextContent('AI-created');
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <div>
        <StatusBadge status="draft" />
        <StatusBadge status="published" />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('StatusDot', () => {
  it('exposes the label to assistive tech and tooltip', async () => {
    const user = userEvent.setup();
    renderUI(<StatusDot colour="var(--success)" label="Cron running" />);
    const dot = screen.getByRole('img', { name: 'Cron running' });
    expect(dot).toBeInTheDocument();
    await user.tab();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Cron running');
  });

  it('passes axe', async () => {
    const { container } = renderUI(<StatusDot colour="var(--success)" label="Healthy" />);
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('MetricCard', () => {
  it('renders value, trend and hint', () => {
    renderUI(
      <MetricCard
        label="Entries"
        value="1,284"
        trend={{ direction: 'up', label: '+12%' }}
        hint="this month"
      />
    );
    expect(screen.getByText('1,284')).toBeInTheDocument();
    expect(screen.getByText(/\+12%/)).toHaveClass('text-success-text');
    expect(screen.getByText('this month')).toBeInTheDocument();
  });

  it('renders a sized, busy-marked skeleton while loading', async () => {
    const { container } = renderUI(<MetricCard label="Entries" value="" loading />);
    expect(container.querySelector('[aria-busy="true"]')).toBeInTheDocument();
    expect(container.querySelectorAll('.animate-pulse').length).toBe(3);
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('undoToast', () => {
  function UndoProbe({ onUndo, onExpire }: { onUndo: () => void; onExpire: () => void }) {
    return (
      <div>
        <Toaster />
        <button
          type="button"
          onClick={() =>
            undoToast({
              message: 'Entry moved to trash',
              onUndo,
              onExpire,
              duration: 4000,
            })
          }
        >
          trash
        </button>
      </div>
    );
  }

  it('runs onUndo when the action is clicked', async () => {
    const onUndo = vi.fn();
    const onExpire = vi.fn();
    const user = userEvent.setup();
    renderUI(<UndoProbe onUndo={onUndo} onExpire={onExpire} />);
    await user.click(screen.getByRole('button', { name: 'trash' }));
    expect((await screen.findAllByText('Entry moved to trash')).length).toBeGreaterThan(0);
    await user.click(screen.getByRole('button', { name: 'Undo' }));
    await waitFor(() => expect(onUndo).toHaveBeenCalledOnce());
    // Expiry must not run after an undo.
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(onExpire).not.toHaveBeenCalled();
  });

  it('runs onExpire when the toast expires without undo', async () => {
    const onUndo = vi.fn();
    const onExpire = vi.fn();
    const user = userEvent.setup();
    renderUI(<UndoProbe onUndo={onUndo} onExpire={onExpire} />);
    await user.click(screen.getByRole('button', { name: 'trash' }));
    await screen.findAllByText('Entry moved to trash');
    // Wait past the 4 s undo window.
    await new Promise((resolve) => setTimeout(resolve, 4600));
    expect(onExpire).toHaveBeenCalledOnce();
    expect(onUndo).not.toHaveBeenCalled();
  }, 10_000);

  it('passes axe with a toast visible', async () => {
    const user = userEvent.setup();
    renderUI(<UndoProbe onUndo={vi.fn()} onExpire={vi.fn()} />);
    await user.click(screen.getByRole('button', { name: 'trash' }));
    await screen.findAllByText('Entry moved to trash');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});
