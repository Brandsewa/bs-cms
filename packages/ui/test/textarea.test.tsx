import { describe, expect, it } from 'vitest';
import { renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Textarea } from '../src/components/textarea';

describe('Textarea', () => {
  it('accepts typed text', async () => {
    renderUI(<Textarea aria-label="Excerpt" />);
    await userEvent.type(screen.getByLabelText('Excerpt'), 'First line');
    expect(screen.getByLabelText('Excerpt')).toHaveValue('First line');
  });

  it('auto-grows with content', async () => {
    renderUI(<Textarea aria-label="Body" />);
    const area = screen.getByLabelText('Body') as HTMLTextAreaElement;
    await userEvent.type(area, 'line{Enter}line{Enter}line');
    expect(area.style.height).not.toBe('');
  });

  it('shows a character counter and switches to over-limit styling', async () => {
    renderUI(<Textarea aria-label="SEO description" showCounter maxLength={10} />);
    const area = screen.getByLabelText('SEO description');
    await userEvent.type(area, '0123456789');
    expect(screen.getByText('10 of 10 characters')).toBeInTheDocument();

    // Programmatically set a controlled value beyond the limit: the browser
    // blocks typing, but a form fill or server value can still overflow.
    area.removeAttribute('maxlength');
    await userEvent.type(area, 'X');
    expect(screen.getByText('1 over the 10 character limit')).toBeInTheDocument();
    expect(screen.getByText(/over the 10 character limit/)).toHaveClass('text-destructive');
  });

  it('links the counter to the field via aria-describedby', () => {
    renderUI(<Textarea aria-label="Counted" showCounter maxLength={10} />);
    const area = screen.getByLabelText('Counted');
    const describedBy = area.getAttribute('aria-describedby');
    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy!)).toHaveTextContent('0 of 10 characters');
  });

  it('passes axe with a counter and a filled value', async () => {
    const { container } = renderUI(
      <label>
        Description
        <Textarea showCounter maxLength={20} defaultValue="Hello" />
      </label>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
