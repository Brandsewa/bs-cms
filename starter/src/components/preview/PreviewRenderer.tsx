import { useEffect, useState } from 'react';
import type { ReactElement } from 'react';
import { Render } from '@puckeditor/core';
import { puckConfig, sanitizePuckData } from '@bs/page-builder';

/**
 * Client island for the /preview/ page.
 *
 * Reads collection/id/token from the runtime query string (not available at
 * build time), fetches the draft from the CMS preview API, and renders Puck
 * page-builder content (or the legacy fallbacks) as React. Mounted with
 * `client:only="react"` from src/pages/preview.astro.
 */

interface PreviewEntry {
  title?: string;
  status?: string;
  id?: string;
  content?: unknown;
  content_html?: string;
  body_markdown?: string;
  summary?: string;
}

type PreviewState =
  | { phase: 'missing-params' }
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; entry: PreviewEntry; id: string };

const bannerStyle: React.CSSProperties = {
  background: '#fef3c7',
  border: '1px solid #f59e0b',
  padding: '0.75rem 1rem',
  borderRadius: '6px',
  marginBottom: '2rem',
  fontSize: '0.875rem',
  color: '#92400e',
};

const centerStyle: React.CSSProperties = { padding: '3rem 1rem', textAlign: 'center' };

const errorStyle: React.CSSProperties = {
  ...centerStyle,
  color: '#dc2626',
};

/** Fetch the draft entry from the CMS preview API (logic from the former inline script). */
async function fetchPreviewEntry(
  collection: string,
  id: string,
  token: string,
): Promise<PreviewEntry> {
  // Production serves the static site and the PHP API from one origin.
  // Split deployments (local sim) override it via localStorage.
  const cmsBase =
    window.localStorage.getItem('bs.cmsOrigin') || window.location.origin;
  const res = await fetch(
    `${cmsBase}/api/v1/preview/${encodeURIComponent(collection)}/${encodeURIComponent(id)}?token=${encodeURIComponent(token)}`,
  );
  if (!res.ok) {
    const errData = await res.json().catch(() => ({}));
    throw new Error(errData.detail || `Server returned HTTP ${res.status}`);
  }
  const json = await res.json();
  return json.data || {};
}

export default function PreviewRenderer() {
  const [state, setState] = useState<PreviewState>({ phase: 'loading' });

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const collection = params.get('collection');
    const id = params.get('id');
    const token = params.get('token');

    if (!collection || !id || !token) {
      setState({ phase: 'missing-params' });
      return;
    }

    fetchPreviewEntry(collection, id, token)
      .then((entry) => setState({ phase: 'ready', entry, id }))
      .catch((err: unknown) => {
        setState({
          phase: 'error',
          message: 'Preview Error: ' + (err instanceof Error ? err.message : String(err)),
        });
      });
  }, []);

  if (state.phase === 'loading') {
    return (
      <div id="preview-loading" className="empty-state" style={centerStyle}>
        <p>⏳ Loading live preview from CMS...</p>
      </div>
    );
  }

  if (state.phase === 'missing-params') {
    return (
      <div id="preview-error" className="empty-state" style={errorStyle}>
        <p id="preview-error-message">
          Missing required preview parameters (collection, id, token).
        </p>
      </div>
    );
  }

  if (state.phase === 'error') {
    return (
      <div id="preview-error" className="empty-state" style={errorStyle}>
        <p id="preview-error-message">{state.message}</p>
      </div>
    );
  }

  const { entry, id } = state;
  const puckData = sanitizePuckData(entry.content);
  const hasPuckContent =
    puckData.content.length > 0 || Object.keys(puckData.root).length > 0;

  let content: ReactElement;
  if (hasPuckContent) {
    content = <Render config={puckConfig} data={puckData} />;
  } else if (entry.content_html) {
    content = <div dangerouslySetInnerHTML={{ __html: entry.content_html }} />;
  } else if (entry.body_markdown || typeof entry.content === 'string') {
    content = (
      <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit' }}>
        {entry.body_markdown || (entry.content as string)}
      </pre>
    );
  } else if (entry.summary) {
    content = <p>{entry.summary}</p>;
  } else {
    content = (
      <p>
        <em>No content available in this draft.</em>
      </p>
    );
  }

  return (
    <article id="preview-container" className="post-detail">
      <div style={bannerStyle}>
        <strong>⚠️ Live Draft Preview:</strong> You are viewing unsaved or draft content
        retrieved via secure preview token.
      </div>

      <header className="post-header">
        <h1 id="preview-title">{entry.title || 'Untitled'}</h1>
        <div
          id="preview-meta"
          className="post-meta"
          style={{ marginTop: '0.5rem', color: 'var(--color-text-secondary)' }}
        >
          Status: {entry.status || 'draft'} • ID: {entry.id || id}
        </div>
      </header>

      <div id="preview-content" className="post-content" style={{ marginTop: '2rem' }}>
        {content}
      </div>
    </article>
  );
}
