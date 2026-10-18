import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Input } from '../src/components/input';

describe('Input', () => {
  it('accepts typed text', async () => {
    renderUI(<Input aria-label="Title" placeholder="Post title" />);
    const input = screen.getByLabelText('Title');
    await userEvent.type(input, 'Hello world');
    expect(input).toHaveValue('Hello world');
  });

  it('marks validation state with aria-invalid', () => {
    renderUI(<Input aria-label="Slug" aria-invalid="true" />);
    const input = screen.getByLabelText('Slug');
    expect(input).toHaveAttribute('aria-invalid', 'true');
  });

  it('is inert when disabled and readable when readOnly', async () => {
    const onChange = vi.fn();
    renderUI(
      <div>
        <Input aria-label="Disabled" disabled onChange={onChange} />
        <Input aria-label="Readonly" readOnly value="fixed" />
      </div>
    );
    await userEvent.type(screen.getByLabelText('Disabled'), 'x');
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Readonly')).toHaveValue('fixed');
  });

  it('passes axe with label, placeholder and invalid state', async () => {
    const { container } = renderUI(
      <div>
        <label htmlFor="a">Labelled</label>
        <Input id="a" />
        <Input aria-label="With placeholder" placeholder="Search…" />
        <Input aria-label="Invalid" aria-invalid="true" />
        <Input aria-label="Disabled" disabled />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
