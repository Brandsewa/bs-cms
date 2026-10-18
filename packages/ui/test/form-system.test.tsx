import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { z } from 'zod';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Form } from '../src/form/form';
import { FormItemLayout } from '../src/form/form-item-layout';
import { FormErrorSummary, collectErrors } from '../src/form/error-summary';
import { useDirtyGuard } from '../src/form/use-dirty-guard';
import {
  ConditionalField,
  KeyValueArrayField,
  SingleValueArrayField,
} from '../src/form/field-arrays';
import type { KeyValueItem } from '../src/form/field-arrays';

const schema = z.object({
  title: z.string().min(3, 'Title needs at least 3 characters'),
  slug: z.string().regex(/^[a-z0-9-]*$/, 'Lowercase letters, digits and dashes only'),
});

type Values = z.infer<typeof schema>;

describe('Form + zodResolver', () => {
  function ValidatedForm({ onSubmit }: { onSubmit: (values: Values) => void }) {
    return (
      <Form<Values>
        schema={schema}
        defaultValues={{ title: '', slug: '' }}
        onSubmit={onSubmit}
        aria-label="Entry"
      >
        {({ register, formState }) => (
          <>
            <FormItemLayout
              label="Title"
              id="bs-field-title"
              required
              error={formState.errors.title?.message}
            >
              <input id="bs-field-title" {...register('title')} className="w-full rounded-md border border-input px-2 py-1" />
            </FormItemLayout>
            <FormItemLayout label="Slug" id="bs-field-slug" error={formState.errors.slug?.message}>
              <input id="bs-field-slug" {...register('slug')} className="w-full rounded-md border border-input px-2 py-1" />
            </FormItemLayout>
            <button type="submit">Save</button>
          </>
        )}
      </Form>
    );
  }

  it('blocks submit on invalid values and reports field errors', async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();
    renderUI(<ValidatedForm onSubmit={onSubmit} />);
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Title needs at least 3 characters'
    );
  });

  it('submits valid values', async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();
    renderUI(<ValidatedForm onSubmit={onSubmit} />);
    await user.type(screen.getByLabelText(/^Title/), 'Hello world');
    await user.type(screen.getByLabelText(/^Slug/), 'hello-world');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() =>
      expect(onSubmit).toHaveBeenCalledWith(
        { title: 'Hello world', slug: 'hello-world' },
        expect.anything()
      )
    );
  });

  it('passes axe with errors rendered', async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();
    const { container } = renderUI(<ValidatedForm onSubmit={onSubmit} />);
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await screen.findByRole('alert');
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('FormItemLayout', () => {
  it('wires label, help text, error and aria-describedby', () => {
    renderUI(
      <FormItemLayout label="SEO title" id="seo-title" required helpText="Aim for 60 characters" error="Too long">
        <input id="seo-title" defaultValue={'x'.repeat(70)} />
      </FormItemLayout>
    );
    const input = screen.getByLabelText(/SEO title/);
    expect(input).toHaveAttribute('aria-describedby');
    const describedBy = input.getAttribute('aria-describedby')!.split(' ');
    const text = describedBy.map((id) => document.getElementById(id)?.textContent).join(' ');
    expect(text).toContain('Aim for 60 characters');
    expect(text).toContain('Too long');
    expect(screen.getByText('(Required)', { exact: false })).toBeInTheDocument();
  });

  it('shows a character counter with over-limit styling', () => {
    renderUI(
      <FormItemLayout
        label="Meta description"
        id="meta"
        counter={{ current: 165, max: 160 }}
      >
        <input id="meta" />
      </FormItemLayout>
    );
    expect(screen.getByText('5 over the 160 character limit')).toHaveClass('text-destructive-text');
  });

  it('renders horizontal layout for settings pages', () => {
    const { container } = renderUI(
      <FormItemLayout label="Site name" id="site-name" layout="horizontal">
        <input id="site-name" />
      </FormItemLayout>
    );
    expect(container.firstElementChild).toHaveClass('flex-row');
  });

  it('passes axe with error and counter', async () => {
    const { container } = renderUI(
      <FormItemLayout label="Excerpt" id="excerpt" error="Required" counter={{ current: 0, max: 200 }}>
        <input id="excerpt" />
      </FormItemLayout>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('FormErrorSummary', () => {
  it('lists errors and focuses the field on click', async () => {
    const user = userEvent.setup();
    renderUI(
      <div>
        <FormErrorSummary
          errors={[
            { name: 'title', label: 'Title', message: 'Required' },
            { name: 'slug', label: 'Slug', message: 'Taken' },
          ]}
        />
        <input id="bs-field-slug" />
      </div>
    );
    const summary = screen.getByRole('alert');
    expect(summary).toHaveTextContent('The form has 2 errors.');
    await user.click(screen.getByRole('button', { name: /Slug: Taken/ }));
    expect(document.getElementById('bs-field-slug')).toHaveFocus();
  });

  it('collectErrors flattens nested RHF error objects', () => {
    const errors = {
      title: { type: 'required', message: 'Required' },
      items: [{ title: { type: 'min', message: 'Too short' } }],
    };
    const flat = collectErrors(errors, { title: 'Title', 'items.0.title': 'Item title' });
    expect(flat).toEqual([
      { name: 'title', label: 'Title', message: 'Required' },
      { name: 'items.0.title', label: 'Item title', message: 'Too short' },
    ]);
  });
});

describe('useDirtyGuard', () => {
  function GuardProbe({ dirty, onLeave }: { dirty: boolean; onLeave: (allowed: boolean) => void }) {
    const guard = useDirtyGuard({ when: dirty });
    return (
      <div>
        <button type="button" onClick={() => void guard.confirmLeave().then(onLeave)}>
          leave
        </button>
        {guard.dialog}
      </div>
    );
  }

  it('passes through immediately when the form is clean', async () => {
    const onLeave = vi.fn();
    const user = userEvent.setup();
    renderUI(<GuardProbe dirty={false} onLeave={onLeave} />);
    await user.click(screen.getByRole('button', { name: 'leave' }));
    await waitFor(() => expect(onLeave).toHaveBeenCalledWith(true));
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('asks for confirmation when dirty and respects the choice', async () => {
    const onLeave = vi.fn();
    const user = userEvent.setup();
    renderUI(<GuardProbe dirty onLeave={onLeave} />);
    await user.click(screen.getByRole('button', { name: 'leave' }));
    const dialog = await screen.findByRole('alertdialog');
    expect(dialog).toHaveTextContent('You have unsaved changes');
    // Keep editing: guard resolves false, dialog closes, can re-ask.
    await user.click(screen.getByRole('button', { name: 'Keep editing' }));
    await waitFor(() => expect(onLeave).toHaveBeenCalledWith(false));
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: 'leave' }));
    await screen.findByRole('alertdialog');
    await user.click(screen.getByRole('button', { name: 'Discard changes' }));
    await waitFor(() => expect(onLeave).toHaveBeenLastCalledWith(true));
  });

  it('adds a beforeunload handler only while dirty', () => {
    const beforeUnloadSpy = vi.fn();
    window.addEventListener('beforeunload', beforeUnloadSpy);
    const { rerender } = renderUI(<GuardProbe dirty={false} onLeave={vi.fn()} />);
    rerender(<GuardProbe dirty onLeave={vi.fn()} />);
    window.removeEventListener('beforeunload', beforeUnloadSpy);
    // The handler registration is an effect; presence is verified by
    // dispatching the event.
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });

  it('passes axe while the guard dialog is open', async () => {
    const user = userEvent.setup();
    renderUI(<GuardProbe dirty onLeave={vi.fn()} />);
    await user.click(screen.getByRole('button', { name: 'leave' }));
    await screen.findByRole('alertdialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('Field arrays', () => {
  function SingleHost({ onChange }: { onChange: (values: string[]) => void }) {
    const [values, setValues] = useState<string[]>(['first']);
    return (
      <SingleValueArrayField
        values={values}
        onChange={(next) => {
          setValues(next);
          onChange(next);
        }}
        inputLabel="Redirect source"
        addLabel="Add source"
      />
    );
  }

  it('SingleValueArrayField adds, edits and removes rows', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<SingleHost onChange={onChange} />);
    await user.click(screen.getByRole('button', { name: 'Add source' }));
    expect(onChange).toHaveBeenLastCalledWith(['first', '']);

    await user.type(screen.getByLabelText('Redirect source 2'), '/old');
    expect(onChange).toHaveBeenLastCalledWith(['first', '/old']);

    await user.click(screen.getByRole('button', { name: 'Remove 1' }));
    expect(onChange).toHaveBeenLastCalledWith(['/old']);
    expect(screen.queryByLabelText('Redirect source 2')).not.toBeInTheDocument();
  });

  function KeyValueHost({ onChange }: { onChange: (items: KeyValueItem[]) => void }) {
    const [items, setItems] = useState<KeyValueItem[]>([{ key: '', value: '' }]);
    return (
      <KeyValueArrayField
        items={items}
        onChange={(next) => {
          setItems(next);
          onChange(next);
        }}
        keyPlaceholder="Name"
        valuePlaceholder="Content"
        addLabel="Add row"
        aria-label="Meta tags"
      />
    );
  }

  it('KeyValueArrayField manages key/value rows', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<KeyValueHost onChange={onChange} />);
    await user.type(screen.getByLabelText('Meta tags key 1'), 'og:title');
    expect(onChange).toHaveBeenLastCalledWith([{ key: 'og:title', value: '' }]);

    await user.type(screen.getByLabelText('Meta tags value 1'), 'Home');
    expect(onChange).toHaveBeenLastCalledWith([{ key: 'og:title', value: 'Home' }]);

    await user.click(screen.getByRole('button', { name: /Remove row 1/ }));
    expect(onChange).toHaveBeenLastCalledWith([]);
  });

  it('ConditionalField hides children when the condition is false', () => {
    const { rerender } = renderUI(
      <ConditionalField show={false}>
        <div>Advanced options</div>
      </ConditionalField>
    );
    expect(screen.queryByText('Advanced options')).not.toBeInTheDocument();
    rerender(
      <ConditionalField show>
        <div>Advanced options</div>
      </ConditionalField>
    );
    expect(screen.getByText('Advanced options')).toBeInTheDocument();
  });

  it('passes axe for field arrays', async () => {
    const { container } = renderUI(
      <div>
        <SingleValueArrayField values={['a', 'b']} onChange={vi.fn()} inputLabel="Source" />
        <KeyValueArrayField
          items={[{ key: 'k', value: 'v' }]}
          onChange={vi.fn()}
          aria-label="Meta"
        />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
