import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { RadioCard, RadioGroup, RadioGroupItem } from '../src/components/radio-group';

describe('RadioGroup', () => {
  it('selects an option on click', async () => {
    const onValueChange = vi.fn();
    renderUI(
      <RadioGroup onValueChange={onValueChange} aria-label="Visibility">
        <label className="flex items-center gap-2">
          <RadioGroupItem value="public" /> Public
        </label>
        <label className="flex items-center gap-2">
          <RadioGroupItem value="private" /> Private
        </label>
      </RadioGroup>
    );
    await userEvent.click(screen.getByRole('radio', { name: 'Public' }));
    expect(screen.getByRole('radio', { name: 'Public' })).toBeChecked();
    expect(onValueChange).toHaveBeenCalledWith('public');
  });

  it('moves focus with arrow keys and selects with Space', async () => {
    // Radix moves roving focus on arrows; actual selection on arrows comes
    // from native radio semantics, which jsdom does not implement. In a real
    // browser the hidden bubble input gives native arrow-to-select behaviour.
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <RadioGroup onValueChange={onValueChange} defaultValue="a" aria-label="Choose">
        <label>
          <RadioGroupItem value="a" /> Alpha
        </label>
        <label>
          <RadioGroupItem value="b" /> Beta
        </label>
      </RadioGroup>
    );
    screen.getByRole('radio', { name: 'Alpha' }).focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('radio', { name: 'Beta' })).toHaveFocus();
    await user.keyboard(' ');
    await waitFor(() => expect(onValueChange).toHaveBeenCalledWith('b'));
  });

  it('skips disabled items', async () => {
    const user = userEvent.setup();
    renderUI(
      <RadioGroup defaultValue="a" aria-label="Choose">
        <label>
          <RadioGroupItem value="a" /> Alpha
        </label>
        <label>
          <RadioGroupItem value="b" disabled /> Beta
        </label>
        <label>
          <RadioGroupItem value="c" /> Gamma
        </label>
      </RadioGroup>
    );
    screen.getByRole('radio', { name: 'Alpha' }).focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('radio', { name: 'Gamma' })).toHaveFocus();
  });
});

describe('RadioCard', () => {
  it('renders title and description and checks like a radio', async () => {
    const onValueChange = vi.fn();
    renderUI(
      <RadioGroup onValueChange={onValueChange} aria-label="Editor profile">
        <RadioCard value="article" title="Article" description="Full block editor for posts" />
        <RadioCard value="minimal" title="Minimal" description="Inline marks only" />
      </RadioGroup>
    );
    const card = screen.getByRole('radio', { name: /article/i });
    await userEvent.click(card);
    expect(card).toHaveAttribute('data-state', 'checked');
    expect(onValueChange).toHaveBeenCalledWith('article');
    expect(screen.getByText('Full block editor for posts')).toBeInTheDocument();
  });

  it('passes axe for both radio group forms', async () => {
    const { container } = render(
      <div>
        <RadioGroup defaultValue="a" aria-label="Plain group">
          <label>
            <RadioGroupItem value="a" /> One
          </label>
          <label>
            <RadioGroupItem value="b" /> Two
          </label>
        </RadioGroup>
        <RadioGroup aria-label="Card group">
          <RadioCard value="x" title="Card one" description="First card" />
          <RadioCard value="y" title="Card two" description="Second card" />
        </RadioGroup>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
