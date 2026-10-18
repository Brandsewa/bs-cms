import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Checkbox } from '../src/components/checkbox';

describe('Checkbox', () => {
  it('toggles on click and reports the new state', async () => {
    const onCheckedChange = vi.fn();
    renderUI(
      <label>
        <Checkbox onCheckedChange={onCheckedChange} /> Feature
      </label>
    );
    const checkbox = screen.getByRole('checkbox', { name: 'Feature' });
    expect(checkbox).toHaveAttribute('data-state', 'unchecked');
    await userEvent.click(checkbox);
    expect(checkbox).toHaveAttribute('data-state', 'checked');
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('renders the indeterminate state with a mixed aria attribute', () => {
    renderUI(
      <label>
        <Checkbox checked="indeterminate" onCheckedChange={() => {}} /> Select all
      </label>
    );
    const checkbox = screen.getByRole('checkbox', { name: 'Select all' });
    expect(checkbox).toHaveAttribute('data-state', 'indeterminate');
    expect(checkbox).toHaveAttribute('aria-checked', 'mixed');
  });

  it('does not navigate the row when clicked (never wraps a link)', async () => {
    const onCheckedChange = vi.fn();
    renderUI(
      <a href="/entries/1">
        <label>
          <Checkbox onCheckedChange={onCheckedChange} /> Row select
        </label>
      </a>
    );
    await userEvent.click(screen.getByRole('checkbox', { name: 'Row select' }));
    expect(onCheckedChange).toHaveBeenCalled();
    expect(window.location.pathname).toBe('/');
  });

  it('toggles with Space from the keyboard', async () => {
    const onCheckedChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <label>
        <Checkbox onCheckedChange={onCheckedChange} /> Keyboard
      </label>
    );
    screen.getByRole('checkbox', { name: 'Keyboard' }).focus();
    await user.keyboard(' ');
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('is inert when disabled', async () => {
    const onCheckedChange = vi.fn();
    renderUI(
      <label>
        <Checkbox disabled onCheckedChange={onCheckedChange} /> Locked
      </label>
    );
    await userEvent.click(screen.getByRole('checkbox', { name: 'Locked' }));
    expect(onCheckedChange).not.toHaveBeenCalled();
  });

  it('passes axe in all three states', async () => {
    const { container } = render(
      <div>
        <label>
          <Checkbox /> Unchecked
        </label>
        <label>
          <Checkbox defaultChecked /> Checked
        </label>
        <label>
          <Checkbox checked="indeterminate" onCheckedChange={() => {}} /> Mixed
        </label>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
