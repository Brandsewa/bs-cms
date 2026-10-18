import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from "./test-utils";
import {
  readStoredTheme,
  resolveTheme,
  systemPrefersDark,
  ThemeProvider,
  useTheme,
} from '../src/theming';

type MediaListener = (event: { matches: boolean }) => void;

let darkPreference = false;
const listeners = new Set<MediaListener>();

function stubMatchMedia() {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation((query: string) => ({
      matches: query.includes('dark') && darkPreference,
      media: query,
      onchange: null,
      addEventListener: (_type: string, listener: MediaListener) => listeners.add(listener),
      removeEventListener: (_type: string, listener: MediaListener) => listeners.delete(listener),
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }))
  );
}

function ThemeProbe() {
  const { theme, resolvedTheme, setTheme } = useTheme();
  return (
    <div>
      <span data-testid="theme">{theme}</span>
      <span data-testid="resolved">{resolvedTheme}</span>
      <span data-testid="html-class">{document.documentElement.className}</span>
      <button type="button" onClick={() => setTheme('dark')}>
        dark
      </button>
      <button type="button" onClick={() => setTheme('light')}>
        light
      </button>
      <button type="button" onClick={() => setTheme('system')}>
        system
      </button>
    </div>
  );
}

describe('ThemeProvider', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.className = '';
    darkPreference = false;
    listeners.clear();
    stubMatchMedia();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('defaults to the system preference', () => {
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    expect(screen.getByTestId('theme')).toHaveTextContent('system');
    expect(screen.getByTestId('resolved')).toHaveTextContent('light');
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('resolves system:dark to the dark class', () => {
    darkPreference = true;
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    expect(screen.getByTestId('resolved')).toHaveTextContent('dark');
    expect(document.documentElement.classList.contains('dark')).toBe(true);
    expect(document.documentElement.style.colorScheme).toBe('dark');
  });

  it('persists an explicit choice and applies the class', () => {
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    act(() => {
      screen.getByRole('button', { name: 'dark' }).click();
    });
    expect(screen.getByTestId('theme')).toHaveTextContent('dark');
    expect(window.localStorage.getItem('bs-theme')).toBe('dark');
    expect(document.documentElement.classList.contains('dark')).toBe(true);

    act(() => {
      screen.getByRole('button', { name: 'light' }).click();
    });
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('follows OS scheme changes while in system mode', () => {
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    expect(document.documentElement.classList.contains('dark')).toBe(false);
    act(() => {
      darkPreference = true;
      listeners.forEach((listener) => listener({ matches: true }));
    });
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('follows storage changes from other tabs', () => {
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    act(() => {
      window.localStorage.setItem('bs-theme', 'dark');
      window.dispatchEvent(new StorageEvent('storage', { key: 'bs-theme', newValue: 'dark' }));
    });
    expect(screen.getByTestId('theme')).toHaveTextContent('dark');
  });

  it('ignores invalid stored values', () => {
    window.localStorage.setItem('bs-theme', 'neon');
    expect(readStoredTheme()).toBe('system');
  });

  it('resolveTheme and systemPrefersDark are consistent', () => {
    darkPreference = true;
    expect(systemPrefersDark()).toBe(true);
    expect(resolveTheme('system')).toBe('dark');
    expect(resolveTheme('light')).toBe('light');
  });
});
