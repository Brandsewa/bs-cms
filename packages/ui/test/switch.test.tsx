import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Switch } from '../src/components/switch';

describe('Switch', () => {
  it('toggles on click with role=switch', async () => {
    const onCheckedChange = vi.fn();
    renderUI(
      <label>
        <Switch onCheckedChange={onCheckedChange} /> Email notifications
      </label>
    );
    const switchControl = screen.getByRole('switch', { name: 'Email notifications' });
    expect(switchControl).toHaveAttribute('data-state', 'unchecked');
    await userEvent.click(switchControl);
    expect(switchControl).toHaveAttribute('data-state', 'checked');
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('toggles with Space', async () => {
    const onCheckedChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <label>
        <Switch onCheckedChange={onCheckedChange} /> Compact density
      </label>
    );
    screen.getByRole('switch', { name: 'Compact density' }).focus();
    await user.keyboard(' ');
    expect(onCheckedChange).toHaveBeenCalledWith(true);
  });

  it('is inert when disabled', async () => {
    const onCheckedChange = vi.fn();
    renderUI(
      <label>
        <Switch disabled onCheckedChange={onCheckedChange} /> Enforced 2FA
      </label>
    );
    await userEvent.click(screen.getByRole('switch', { name: 'Enforced 2FA' }));
    expect(onCheckedChange).not.toHaveBeenCalled();
  });

  it('passes axe in both states', async () => {
    const { container } = renderUI(
      <div>
        <label>
          <Switch /> Off
        </label>
        <label>
          <Switch defaultChecked /> On
        </label>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
