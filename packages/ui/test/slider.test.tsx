import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Slider } from '../src/components/slider';

describe('Slider', () => {
  it('changes value with arrow keys', async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<Slider aria-label="Quality" defaultValue={[70]} onValueChange={onValueChange} />);
    const thumb = screen.getByRole('slider', { name: 'Quality' });
    expect(thumb).toHaveAttribute('aria-valuenow', '70');
    thumb.focus();
    await user.keyboard('{ArrowRight}');
    expect(thumb).toHaveAttribute('aria-valuenow', '71');
    expect(onValueChange).toHaveBeenCalledWith([71]);
  });

  it('renders two thumbs in range mode', () => {
    renderUI(
      <Slider aria-label="Date range zoom" defaultValue={[10, 90]} min={0} max={100} />
    );
    const thumbs = screen.getAllByRole('slider');
    expect(thumbs).toHaveLength(2);
    expect(thumbs[0]).toHaveAttribute('aria-valuenow', '10');
    expect(thumbs[1]).toHaveAttribute('aria-valuenow', '90');
  });

  it('is inert when disabled', async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<Slider aria-label="Opacity" defaultValue={[50]} disabled onValueChange={onValueChange} />);
    screen.getByRole('slider', { name: 'Opacity' }).focus();
    await user.keyboard('{ArrowRight}');
    expect(onValueChange).not.toHaveBeenCalled();
  });

  it('passes axe in single and range modes', async () => {
    const { container } = renderUI(
      <div>
        <Slider aria-label="Single" defaultValue={[30]} />
        <Slider aria-label="Range" defaultValue={[20, 80]} />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
