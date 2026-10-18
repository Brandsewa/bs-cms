import { render, type RenderOptions } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { I18nProvider } from '../src/i18n';
import { ThemeProvider } from '../src/theming';

function AllProviders({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider>
      <I18nProvider>{children}</I18nProvider>
    </ThemeProvider>
  );
}

export function renderUI(ui: ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
  return render(ui, { wrapper: AllProviders, ...options });
}

export { render };

export { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
export { default as userEvent } from '@testing-library/user-event';
