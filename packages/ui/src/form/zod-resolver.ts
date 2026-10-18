import type { FieldErrors, FieldValues, Resolver, ResolverResult } from 'react-hook-form';
import type { ZodType } from 'zod';

function setPathError(
  errors: FieldErrors,
  path: Array<string | number>,
  message: string,
  type: string
): void {
  let node: FieldErrors = errors;
  path.forEach((segment, index) => {
    const isLast = index === path.length - 1;
    const key = String(segment);
    if (isLast) {
      const existing = node[key];
      node[key] =
        existing && typeof existing === 'object' && 'message' in existing
          ? existing
          : { type, message, ref: undefined };
      return;
    }
    if (typeof node[key] !== 'object' || node[key] === null) {
      node[key] = {};
    }
    node = node[key] as FieldErrors;
  });
}

/**
 * Maps a Zod schema onto react-hook-form's resolver contract, including
 * nested and array paths (`data.items.0.title`). Hand-rolled: the common
 * `@hookform/resolvers` package is not on the dependency allow-list. The
 * same Zod schema the server validates against drives client validation
 * (docs/02-ui-spec.md 3.2).
 */
export function zodResolver<TValues extends FieldValues>(
  schema: ZodType<TValues>
): Resolver<TValues> {
  return async (values): Promise<ResolverResult<TValues>> => {
    const result = schema.safeParse(values);
    if (result.success) {
      return { values: result.data as TValues, errors: {} };
    }
    const errors: FieldErrors = {};
    for (const issue of result.error.issues) {
      const path = issue.path.filter(
        (segment): segment is string | number => typeof segment !== 'symbol'
      );
      setPathError(errors, path, issue.message, issue.code);
    }
    return { values: {}, errors: errors as ResolverResult<TValues>['errors'] };
  };
}
