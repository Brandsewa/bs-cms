import {
  useMutation,
  useQuery,
  type UseMutationOptions,
  type UseMutationResult,
  type UseQueryOptions,
  type UseQueryResult,
} from '@tanstack/react-query';
import type { Operations } from './generated/operations';
import type { ApiClient } from './client';

/**
 * TanStack Query hooks bound to an ApiClient instance.
 *
 * const hooks = defineApiHooks(client);
 * const terms = hooks.useApiQuery('GET /taxonomies/{handle}/terms', {
 *   params: { handle: 'categories' },
 *   query: { tree: '1' },
 * });
 *
 * Query keys are [method, path, params, query] so invalidation and prefetch
 * stay predictable.
 */

export type OperationKey = keyof Operations & string;

export type ApiQueryKey = [string, string, Record<string, unknown>, Record<string, unknown>];

export interface RequestInput {
  params?: Record<string, string>;
  query?: Record<string, string | number | boolean | undefined>;
}

export interface ApiHooks {
  useApiQuery<Op extends Extract<OperationKey, `GET ${string}`>>(
    operation: Op,
    input: RequestInput,
    options?: Partial<UseQueryOptions<Operations[Op]['response'], Error>>
  ): UseQueryResult<Operations[Op]['response'], Error>;

  useApiMutation<Op extends OperationKey>(
    operation: Op,
    options?: Partial<
      UseMutationOptions<
        Operations[Op]['response'],
        Error,
        { input?: RequestInput; body?: Operations[Op] extends { body: infer B } ? B : never; ifMatch?: string }
      >
    >
  ): UseMutationResult<
    Operations[Op]['response'],
    Error,
    { input?: RequestInput; body?: Operations[Op] extends { body: infer B } ? B : never; ifMatch?: string }
  >;
}

export function defineApiHooks(client: ApiClient): ApiHooks {
  return {
    useApiQuery(operation, input, options) {
      const queryKey: ApiQueryKey = [
        operation.split(' ')[0]!,
        operation.split(' ').slice(1).join(' '),
        input.params ?? {},
        input.query ?? {},
      ];
      return useQuery<Operations[Extract<OperationKey, `GET ${string}`>]['response'], Error>({
        queryKey,
        queryFn: async () => {
          const result = await client.request<Operations[Extract<OperationKey, `GET ${string}`>]['response']>(
            'GET',
            operation.split(' ').slice(1).join(' '),
            input
          );
          if (!result.ok) {
            throw Object.assign(new Error(result.problem.title), {
              status: result.status,
              problem: result.problem,
            });
          }
          return result.data;
        },
        ...options,
      });
    },

    useApiMutation(operation, options) {
      const [method, ...pathParts] = operation.split(' ');
      const pathTemplate = pathParts.join(' ');
      return useMutation({
        mutationFn: async (variables: {
          input?: RequestInput;
          body?: unknown;
          ifMatch?: string;
        }) => {
          const result = await client.request<Operations[OperationKey]['response']>(
            (method as 'POST' | 'PUT' | 'PATCH' | 'DELETE') ?? 'POST',
            pathTemplate,
            {
              params: variables.input?.params,
              query: variables.input?.query,
              body: variables.body,
              ifMatch: variables.ifMatch,
            }
          );
          if (!result.ok) {
            throw Object.assign(new Error(result.problem.title), {
              status: result.status,
              problem: result.problem,
            });
          }
          return result.data as Operations[OperationKey]['response'];
        },
        ...(options as Record<string, never>),
      }) as unknown as ReturnType<ApiHooks['useApiMutation']>;
    },
  };
}
