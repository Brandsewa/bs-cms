import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  createMemoryHistory,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { StrictMode } from 'react';
import { describe, expect, it } from 'vitest';
import { I18nProvider, ThemeProvider } from '@bs/ui';
import { buildRouteTree } from '../route-tree';
import { sections } from './registry';

/**
 * Regression test for the M1 review defect: section links resolved to
 * /_gallery/_gallery?section=… and rendered a blank page. Clicks every
 * section link in the sidebar and asserts the section heading renders and
 * the URL never nests _gallery.
 */

async function mountGallery() {
  const routeTree = await buildRouteTree();
  const history = createMemoryHistory({ initialEntries: ['/_gallery'] });
  const router = createRouter({
    routeTree,
    history,
    basepath: '/',
    context: { queryClient: new QueryClient() },
  });
  const container = document.createElement('div');
  document.body.appendChild(container);
  act(() => {
    createRoot(container).render(
      <StrictMode>
        <ThemeProvider>
          <I18nProvider>
            <QueryClientProvider client={new QueryClient()}>
              <RouterProvider router={router} />
            </QueryClientProvider>
          </I18nProvider>
        </ThemeProvider>
      </StrictMode>
    );
  });
  // The gallery route lazy-loads; wait until the sidebar is on screen.
  await act(async () => {
    for (let waited = 0; waited < 2000; waited += 25) {
      if (container.querySelector('nav a')) break;
      await new Promise((resolve) => setTimeout(resolve, 25));
    }
  });
  return { container, router };
}

describe('gallery navigation', () => {
  it('renders every section by clicking its sidebar link without nesting the path', async () => {
    const { container } = await mountGallery();

    for (const section of sections) {
      const link = Array.from(container.querySelectorAll('a')).find(
        (anchor) => anchor.textContent === section.name
      );
      expect(link, `sidebar link for ${section.name}`).toBeTruthy();

      await act(async () => {
        (link as HTMLAnchorElement).click();
        await new Promise((resolve) => setTimeout(resolve, 10));
      });

      expect(
        window.location.pathname.endsWith('/_gallery/_gallery'),
        `path must not nest _gallery for ${section.name}`
      ).toBe(false);

      const heading = await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 10));
        return Array.from(container.querySelectorAll('h2')).some(
          (candidate) => (candidate.textContent?.length ?? 0) > 0
        );
      });
      expect(heading, `a section heading renders for ${section.name}`).toBe(true);
    }
    container.remove();
  });

  it('each section link carries the section search param', async () => {
    const { container } = await mountGallery();
    for (const section of sections) {
      const link = Array.from(container.querySelectorAll('a')).find(
        (anchor) => anchor.textContent === section.name
      )!;
      expect(link.getAttribute('href')).toContain(`section=${section.id}`);
    }
    container.remove();
  });
});
