import { useState } from 'react';
import {
  Button,
  ConditionalField,
  Form,
  FormErrorSummary,
  FormItemLayout,
  Input,
  KeyValueArrayField,
  SingleValueArrayField,
  Textarea,
} from '@bs/ui';
import { z } from 'zod';
import type { GallerySection } from '../registry';

const settingsSchema = z.object({
  siteName: z.string().min(2, 'Site name needs at least 2 characters'),
  description: z.string().max(160, 'Keep it under 160 characters'),
});

type SettingsValues = z.infer<typeof settingsSchema>;

function SettingsFormShowcase() {
  const onSubmit = () => {};
  return (
    <Form<SettingsValues>
      schema={settingsSchema}
      defaultValues={{ siteName: '', description: '' }}
      onSubmit={onSubmit}
      aria-label="Site settings"
      className="max-w-xl"
    >
      {({ register, formState }) => (
        <>
          <FormItemLayout
            label="Site name"
            id="g-site-name"
            required
            layout="horizontal"
            error={formState.errors.siteName?.message}
          >
            <Input id="g-site-name" {...register('siteName')} />
          </FormItemLayout>
          <FormItemLayout
            label="Description"
            id="g-site-description"
            layout="horizontal"
            helpText="Used as the meta description fallback."
            error={formState.errors.description?.message}
          >
            <Textarea id="g-site-description" {...register('description')} showCounter maxLength={160} />
          </FormItemLayout>
          {formState.errors.siteName || formState.errors.description ? (
            <FormErrorSummary
              errors={[
                ...(formState.errors.siteName
                  ? [{ name: 'siteName', label: 'Site name', message: formState.errors.siteName.message ?? '' }]
                  : []),
                ...(formState.errors.description
                  ? [{ name: 'description', label: 'Description', message: formState.errors.description.message ?? '' }]
                  : []),
              ]}
            />
          ) : null}
          <Button type="submit" className="self-end">
            Save settings
          </Button>
        </>
      )}
    </Form>
  );
}

function ArraysShowcase() {
  const [values, setValues] = useState<string[]>(['/old-url']);
  const [items, setItems] = useState<Array<{ key: string; value: string }>>([
    { key: 'og:title', value: 'Home' },
  ]);
  const [showExtra, setShowExtra] = useState(false);
  return (
    <div className="max-w-xl space-y-6">
      <FormItemLayout label="Redirect sources" id="g-redirects" helpText="One source per row.">
        <SingleValueArrayField
          values={values}
          onChange={setValues}
          inputLabel="Redirect source"
          addLabel="Add source"
        />
      </FormItemLayout>
      <FormItemLayout label="Meta tags" id="g-meta">
        <KeyValueArrayField
          items={items}
          onChange={setItems}
          keyPlaceholder="Property"
          valuePlaceholder="Content"
          addLabel="Add tag"
          aria-label="Meta tags"
        />
      </FormItemLayout>
      <div className="space-y-2">
        <label className="flex items-center gap-2 text-13">
          <input
            type="checkbox"
            checked={showExtra}
            onChange={(event) => setShowExtra(event.target.checked)}
          />
          Show advanced field (conditional)
        </label>
        <ConditionalField show={showExtra}>
          <FormItemLayout label="Canonical override" id="g-canonical">
            <Input id="g-canonical" placeholder="https://example.com/page" />
          </FormItemLayout>
        </ConditionalField>
      </div>
    </div>
  );
}

export const formSection: GallerySection = {
  id: 'form',
  name: 'Form system',
  entries: [
    {
      id: 'settings-form',
      name: 'Form + FormItemLayout + Error summary',
      description:
        'Horizontal settings layout, zod validation with server-shaped errors, counters, summary with click-to-focus.',
      render: () => <SettingsFormShowcase />,
      props: [
        { name: 'schema', type: 'ZodType<TValues>', description: 'Same contract the server validates; wired via zodResolver.' },
        { name: 'layout (FormItemLayout)', type: "'horizontal' | 'vertical'", defaultValue: "'vertical'", description: 'Horizontal for settings, vertical for sheets/editor.' },
        { name: 'copyValue (FormItemLayout)', type: 'string', description: 'Shows a copy button for read-only values.' },
      ],
    },
    {
      id: 'field-arrays',
      name: 'Field arrays & conditional fields',
      description: 'Single-value rows, key/value rows and ConditionalField driven by another value.',
      render: () => <ArraysShowcase />,
      props: [
        { name: 'items / onChange', type: 'KeyValueItem[]', description: 'Controlled rows with add/remove.' },
        { name: 'show (ConditionalField)', type: 'boolean', description: 'Render children only when true.' },
      ],
    },
  ],
};
