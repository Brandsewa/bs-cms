import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRouter, RouterProvider } from '@tanstack/react-router';
import { I18nProvider, ThemeProvider } from '@bs/ui';
import { buildRouteTree } from './route-tree';
import './styles/main.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
  },
});

void buildRouteTree().then((routeTree) => {
  const router = createRouter({
    routeTree,
    basepath: import.meta.env.PROD ? '/admin' : '/',
    context: { queryClient },
  });
  const container = document.getElementById('root');
  if (!container) throw new Error('Missing #root element in admin/index.html');
  createRoot(container).render(
    <StrictMode>
      <ThemeProvider>
        <I18nProvider>
          <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
          </QueryClientProvider>
        </I18nProvider>
      </ThemeProvider>
    </StrictMode>
  );
});
