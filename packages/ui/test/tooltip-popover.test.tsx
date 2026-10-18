import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Tooltip } from '../src/components/tooltip';
import { Popover, PopoverContent, PopoverTrigger } from '../src/components/popover';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '../src/components/hover-card';
import { Button } from '../src/components/button';

describe('Tooltip', () => {
  it('shows content on keyboard focus and hides on Escape', async () => {
    const user = userEvent.setup();
    renderUI(
      <Tooltip content="Publish now" delayDuration={0}>
        <button type="button" aria-label="Publish">
          <span aria-hidden="true">↑</span>
        </button>
      </Tooltip>
    );
    const trigger = screen.getByRole('button', { name: 'Publish' });
    await user.tab();
    expect(trigger).toHaveFocus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Publish now');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('tooltip')).not.toBeInTheDocument());
  });

  it('does not render content until hover/focus', () => {
    renderUI(
      <Tooltip content="Refresh" delayDuration={0}>
        <button type="button">R</button>
      </Tooltip>
    );
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  });

  it('passes axe when open', async () => {
    const { container } = renderUI(
      <Tooltip content="Add a category" delayDuration={0}>
        <button type="button" aria-label="Add">
          +
        </button>
      </Tooltip>
    );
    await userEvent.tab();
    await screen.findByRole('tooltip');
    // Tooltip content is portalled; check the whole document.
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
    expect(container).toBeInTheDocument();
  });
});

describe('Popover', () => {
  it('opens on click, closes on Escape and on outside click', async () => {
    const onOpenChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Popover onOpenChange={onOpenChange}>
        <PopoverTrigger>Filters</PopoverTrigger>
        <PopoverContent>
          <p>Filter options</p>
        </PopoverContent>
      </Popover>
    );
    await user.click(screen.getByRole('button', { name: 'Filters' }));
    expect(await screen.findByText('Filter options')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByText('Filter options')).not.toBeInTheDocument());
    expect(onOpenChange).toHaveBeenLastCalledWith(false);
    await user.click(screen.getByRole('button', { name: 'Filters' }));
    await screen.findByText('Filter options');
    await user.click(document.body);
    await waitFor(() => expect(screen.queryByText('Filter options')).not.toBeInTheDocument());
  });

  it('returns focus to the trigger on close', async () => {
    const user = userEvent.setup();
    renderUI(
      <Popover>
        <PopoverTrigger>Open</PopoverTrigger>
        <PopoverContent>
          <button type="button">Inside</button>
        </PopoverContent>
      </Popover>
    );
    const trigger = screen.getByRole('button', { name: 'Open' });
    await user.click(trigger);
    await screen.findByRole('button', { name: 'Inside' });
    await user.keyboard('{Escape}');
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <Popover>
        <PopoverTrigger aria-label="More info">i</PopoverTrigger>
        {/* Popover content has an implicit dialog role, so it needs a name. */}
        <PopoverContent aria-label="Entry details">
          <p>Useful details</p>
        </PopoverContent>
      </Popover>
    );
    await user.click(screen.getByRole('button', { name: 'More info' }));
    await screen.findByText('Useful details');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('HoverCard', () => {
  it('opens on hover of the trigger', async () => {
    const user = userEvent.setup();
    renderUI(
      <HoverCard>
        <HoverCardTrigger>@author</HoverCardTrigger>
        <HoverCardContent>
          <p>Managing editor</p>
        </HoverCardContent>
      </HoverCard>
    );
    await user.hover(screen.getByText('@author'));
    expect(await screen.findByText('Managing editor')).toBeInTheDocument();
    await user.unhover(screen.getByText('@author'));
    await waitFor(() => expect(screen.queryByText('Managing editor')).not.toBeInTheDocument());
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <HoverCard>
        <HoverCardTrigger asChild>
          <Button aria-label="User card">@grace</Button>
        </HoverCardTrigger>
        <HoverCardContent aria-label="About this user">
          <p>Grace Hopper</p>
        </HoverCardContent>
      </HoverCard>
    );
    await user.hover(screen.getByRole('button', { name: 'User card' }));
    await screen.findByText('Grace Hopper');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('overlay axe (closed)', () => {
  it('passes axe for trigger-only markup', async () => {
    const { container } = render(
      <div>
        <Tooltip content="Save" delayDuration={0}>
          <button type="button" aria-label="Save">
            S
          </button>
        </Tooltip>
        <Popover>
          <PopoverTrigger>Open popover</PopoverTrigger>
        </Popover>
        <HoverCard>
          <HoverCardTrigger aria-label="Profile">P</HoverCardTrigger>
        </HoverCard>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
