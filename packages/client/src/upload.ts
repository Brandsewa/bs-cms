import type { ApiClient } from './client';

/**
 * Chunked media upload against the frozen contract:
 *
 *   POST /media/chunks/init          { original_name, total_chunks, total_size, folder_id? }
 *                                    → { session_id }
 *   POST /media/chunks/{session_id}  multipart { chunk, chunk_index }
 *   POST /media/chunks/{session_id}/assemble → Media
 *
 * One chunk request at a time. The init request fields and the session key
 * come from the generated contract (Op_postMediaChunksInit).
 */

export interface ChunkedUploadOptions {
  folderId?: string;
  /** Chunk size in bytes (default 2 MiB — matches shared-host guidance). */
  chunkSize?: number;
  /** Progress callback: fraction 0..1 plus the last completed chunk index. */
  onProgress?: (progress: { fraction: number; chunksDone: number; chunksTotal: number }) => void;
  signal?: AbortSignal;
}

export interface UploadedMedia {
  id?: string;
  [field: string]: unknown;
}

export interface ChunkedUploadResult {
  ok: boolean;
  media?: UploadedMedia;
  error?: string;
}

export async function uploadChunked(
  client: ApiClient,
  file: File,
  options: ChunkedUploadOptions = {}
): Promise<ChunkedUploadResult> {
  const chunkSize = options.chunkSize ?? 2 * 1024 * 1024;
  const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));

  const init = await client.request<{ session_id: string }>('POST', '/media/chunks/init', {
    body: {
      original_name: file.name,
      total_chunks: totalChunks,
      total_size: file.size,
      ...(options.folderId ? { folder_id: options.folderId } : {}),
    },
    signal: options.signal,
  });
  if (!init.ok) {
    return { ok: false, error: init.problem.detail ?? init.problem.title };
  }
  const sessionId = init.data.session_id;

  for (let index = 0; index < totalChunks; index += 1) {
    const start = index * chunkSize;
    const blob = file.slice(start, Math.min(start + chunkSize, file.size));
    const form = new FormData();
    form.set('chunk', blob, file.name);
    form.set('chunk_index', String(index));

    const saved = await client.requestMultipart<{ status: string; chunk_index: number }>(
      'POST',
      '/media/chunks/{id}',
      { id: sessionId },
      form,
      { signal: options.signal, retry: true }
    );
    if (!saved.ok) {
      return { ok: false, error: saved.problem.detail ?? saved.problem.title };
    }
    options.onProgress?.({
      fraction: (index + 1) / totalChunks,
      chunksDone: index + 1,
      chunksTotal: totalChunks,
    });
  }

  const assembled = await client.request<UploadedMedia>('POST', '/media/chunks/{id}/assemble', {
    params: { id: sessionId },
    signal: options.signal,
  });
  if (!assembled.ok) {
    return { ok: false, error: assembled.problem.detail ?? assembled.problem.title };
  }
  return { ok: true, media: assembled.data };
}
