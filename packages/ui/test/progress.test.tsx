import { describe, expect, it } from 'vitest';
import { render, renderUI, screen } from './test-utils';
import { runAxe } from './axe';
import { Progress } from '../src/components/progress';

describe('Progress', () => {
  it('exposes the determinate value to assistive technology', () => {
    renderUI(<Progress value={40} aria-label="Upload progress" />);
    const bar = screen.getByRole('progressbar', { name: 'Upload progress' });
    expect(bar).toHaveAttribute('aria-valuenow', '40');
    expect(bar).not.toHaveAttribute('data-indeterminate');
  });

  it('renders an indeterminate state when no value is given', () => {
    renderUI(<Progress aria-label="Bulk publish running" />);
    const bar = screen.getByRole('progressbar', { name: 'Bulk publish running' });
    expect(bar).not.toHaveAttribute('aria-valuenow');
    expect(bar).toHaveAttribute('data-indeterminate');
  });

  it('passes axe in both modes', async () => {
    const { container } = render(
      <div>
        <Progress value={70} aria-label="Determinate" />
        <Progress aria-label="Indeterminate" />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
