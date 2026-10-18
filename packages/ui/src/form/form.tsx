import type { SubmitHandler, UseFormProps, UseFormReturn } from 'react-hook-form';
import { FormProvider, useForm } from 'react-hook-form';
import type { ReactNode } from 'react';
import type { ZodType } from 'zod';
import { cn } from '../lib/utils';
import { zodResolver } from './zod-resolver';

export interface FormProps<TValues extends Record<string, unknown>> {
  /**
   * Fields, or a render function receiving the form instance (for register,
   * formState, watch — needed by FormItemLayout, the error summary and the
   * dirty guard).
   */
  children: ReactNode | ((form: UseFormReturn<TValues>) => ReactNode);
  /** Submit handler; invoked only when client validation passes. */
  onSubmit: SubmitHandler<TValues>;
  /** Zod schema for client-side validation (mirrors the server contract). */
  schema?: ZodType<TValues>;
  defaultValues?: UseFormProps<TValues>['defaultValues'];
  /** Use an instance owned by the screen (dirty guard, autosave, dialogs). */
  form?: UseFormReturn<TValues>;
  className?: string;
  'aria-label'?: string;
  noValidate?: boolean;
}

/**
 * Form shell wiring react-hook-form + the Zod resolver (docs/02-ui-spec.md
 * 3.2). Submit is bound to handleSubmit; validation errors never submit.
 */
export function Form<TValues extends Record<string, unknown>>({
  children,
  onSubmit,
  schema,
  defaultValues,
  form: controlledForm,
  className,
  'aria-label': ariaLabel,
  noValidate = true,
}: FormProps<TValues>) {
  const localForm = useForm<TValues>({
    ...(schema ? { resolver: zodResolver(schema) } : {}),
    defaultValues,
  });
  const form = controlledForm ?? localForm;

  return (
    <FormProvider {...form}>
      <form
        aria-label={ariaLabel}
        noValidate={noValidate}
        className={cn('space-y-4', className)}
        onSubmit={form.handleSubmit(onSubmit)}
      >
        <FormProvider {...form}>
          {typeof children === 'function' ? children(form) : children}
        </FormProvider>
      </form>
    </FormProvider>
  );
}
