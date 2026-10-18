import { describe, expect, it, vi } from 'vitest';
import { render, renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Button } from '../src/components/button';

describe('Button', () => {
  it('renders a button with type=button by default and fires on click', async () => {
    const onClick = vi.fn();
    renderUI(
      <Button onClick={onClick}>Save</Button>
    );
    const button = screen.getByRole('button', { name: 'Save' });
    expect(button).toHaveAttribute('type', 'button');
    await userEvent.click(button);
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('respects an explicit type=submit', () => {
    renderUI(<Button type="submit">Submit</Button>);
    expect(screen.getByRole('button')).toHaveAttribute('type', 'submit');
  });

  it('shows a spinner and disables while loading', async () => {
    const onClick = vi.fn();
    renderUI(
      <Button loading onClick={onClick}>
        Save
      </Button>
    );
    const button = screen.getByRole('button', { name: /save/i });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(button.querySelector('svg')).toBeInTheDocument();
    await userEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('is inert when disabled', async () => {
    const onClick = vi.fn();
    renderUI(
      <Button disabled onClick={onClick}>
        Delete
      </Button>
    );
    await userEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(onClick).not.toHaveBeenCalled();
  });

  it('activates with Enter and Space', async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    renderUI(<Button onClick={onClick}>Publish</Button>);
    const button = screen.getByRole('button', { name: 'Publish' });
    button.focus();
    await user.keyboard('{Enter}');
    await user.keyboard(' ');
    expect(onClick).toHaveBeenCalledTimes(2);
  });

  it('renders a link through asChild', () => {
    render(
      <Button asChild>
        <a href="/posts">All posts</a>
      </Button>
    );
    const link = screen.getByRole('link', { name: 'All posts' });
    expect(link).toBeInTheDocument();
    expect(link).not.toHaveAttribute('disabled');
  });

  it('supports icon-only buttons with an accessible name', () => {
    renderUI(
      <Button size="icon" aria-label="Refresh">
        <svg data-testid="icon" />
      </Button>
    );
    expect(screen.getByRole('button', { name: 'Refresh' })).toBeInTheDocument();
  });

  it('passes axe across all variants and sizes', async () => {
    const { container } = render(
      <div>
        {(['default', 'secondary', 'destructive', 'outline', 'ghost', 'link'] as const).map(
          (variant) => (
            <Button key={variant} variant={variant}>
              {variant}
            </Button>
          )
        )}
        <Button loading>Saving</Button>
        <Button disabled>Disabled</Button>
        <Button size="icon" aria-label="Icon only">
          <svg />
        </Button>
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
