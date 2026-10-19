export {
  hasFieldErrors,
  isProblemDetails,
  parseProblemResponse,
  type ProblemDetails,
} from './problem-details';

export {
  CmsBuildClient,
  type BuildClientOptions,
  type BuildManifest,
  type EntryItem,
  type FetchEntriesOptions,
  type PaginatedResult,
} from './build-client';

// Typed client over the frozen api-v1 contract (generated + runtime).
export { ApiClient, type ApiClientOptions, type ApiError, type ApiPreconditionFailure, type ApiResult, type ApiSuccess, type RequestOptions } from './client';
export { buildListQuery, listAll, nextCursor, type ListEnvelope, type ListMeta, type ListQueryOptions } from './cursor';
export { uploadChunked, type ChunkedUploadOptions, type ChunkedUploadResult, type UploadedMedia } from './upload';
export { defineApiHooks, type ApiHooks, type ApiQueryKey, type OperationKey, type RequestInput } from './hooks';
export type * from './generated/schema';
export type { Operations } from './generated/operations';
