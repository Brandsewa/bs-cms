import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Toggle } from '../src/components/toggle';
import { ToggleGroup, ToggleGroupItem } from '../src/components/toggle-group';

describe('Toggle', () => {
  it('toggles pressed state on click', async () => {
    const onPressedChange = vi.fn();
    renderUI(
      <Toggle aria-label="Bold" onPressedChange={onPressedChange}>
        B
      </Toggle>
    );
    const toggle = screen.getByRole('button', { name: 'Bold' });
    expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await userEvent.click(toggle);
    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    expect(onPressedChange).toHaveBeenCalledWith(true);
  });

  it('is inert when disabled', async () => {
    const onPressedChange = vi.fn();
    renderUI(
      <Toggle aria-label="Italic" disabled onPressedChange={onPressedChange}>
        I
      </Toggle>
    );
    await userEvent.click(screen.getByRole('button', { name: 'Italic' }));
    expect(onPressedChange).not.toHaveBeenCalled();
  });
});

describe('ToggleGroup', () => {
  it('single mode keeps exactly one item pressed', async () => {
    renderUI(
      <ToggleGroup type="single" aria-label="Density" defaultValue="compact">
        <ToggleGroupItem value="compact" aria-label="Compact">
          Compact
        </ToggleGroupItem>
        <ToggleGroupItem value="default" aria-label="Default">
          Default
        </ToggleGroupItem>
      </ToggleGroup>
    );
    const compact = screen.getByRole('radio', { name: 'Compact' });
    const normal = screen.getByRole('radio', { name: 'Default' });
    expect(compact).toHaveAttribute('data-state', 'on');
    await userEvent.click(normal);
    expect(normal).toHaveAttribute('data-state', 'on');
    expect(compact).toHaveAttribute('data-state', 'off');
  });

  it('multiple mode allows several pressed items', async () => {
    renderUI(
      <ToggleGroup type="multiple" aria-label="Formatting">
        <ToggleGroupItem value="bold" aria-label="Bold">
          B
        </ToggleGroupItem>
        <ToggleGroupItem value="italic" aria-label="Italic">
          I
        </ToggleGroupItem>
      </ToggleGroup>
    );
    await userEvent.click(screen.getByRole('button', { name: 'Bold' }));
    await userEvent.click(screen.getByRole('button', { name: 'Italic' }));
    expect(screen.getByRole('button', { name: 'Bold' })).toHaveAttribute('data-state', 'on');
    expect(screen.getByRole('button', { name: 'Italic' })).toHaveAttribute('data-state', 'on');
  });

  it('moves focus between items with arrow keys', async () => {
    const user = userEvent.setup();
    renderUI(
      <ToggleGroup type="single" aria-label="Views">
        <ToggleGroupItem value="all" aria-label="All">
          All
        </ToggleGroupItem>
        <ToggleGroupItem value="mine" aria-label="Mine">
          Mine
        </ToggleGroupItem>
      </ToggleGroup>
    );
    screen.getByRole('radio', { name: 'All' }).focus();
    await user.keyboard('{ArrowRight}');
    expect(screen.getByRole('radio', { name: 'Mine' })).toHaveFocus();
  });
});

describe('toggle axe', () => {
  it('passes axe for toggle and toggle groups', async () => {
    const { container } = render(
      <div>
        <Toggle aria-label="Underline">U</Toggle>
        <ToggleGroup type="single" aria-label="Sizes" defaultValue="sm">
          <ToggleGroupItem value="sm">Small</ToggleGroupItem>
          <ToggleGroupItem value="lg">Large</ToggleGroupItem>
        </ToggleGroup>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
