import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react';
import defaultMessages from './en.json';

/**
 * All user-facing strings in @bs/ui live in this message catalog
 * (docs/02-ui-spec.md: English only in v1, but no hard-coded strings so
 * languages can be added later without code changes).
 */
export type Messages = typeof defaultMessages;

type Primitive = string | number;
type Leaf = Primitive | ((vars: never) => string);

type PathsOf<T> = T extends Primitive
  ? never
  : { [K in keyof T & string]: T[K] extends Leaf ? K : `${K}.${PathsOf<T[K]>}` }[keyof T & string];

export type MessageKey = PathsOf<Messages>;

type MessageVars = Record<string, Primitive>;

function resolvePath(messages: unknown, key: string): unknown {
  let node: unknown = messages;
  for (const segment of key.split('.')) {
    if (typeof node !== 'object' || node === null || !(segment in node)) return undefined;
    node = (node as Record<string, unknown>)[segment];
  }
  return node;
}

function interpolate(template: string, vars: MessageVars | undefined): string {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in vars ? String(vars[name]) : match
  );
}

export interface I18n {
  /** Translate a catalog key, interpolating `{var}` placeholders. */
  t: (key: MessageKey, vars?: MessageVars) => string;
}

const I18nContext = createContext<I18n>({ t: () => '' });

function deepMerge(base: unknown, override: unknown): unknown {
  if (typeof base !== 'object' || base === null || Array.isArray(base)) return override ?? base;
  if (typeof override !== 'object' || override === null || Array.isArray(override)) return base;
  const result: Record<string, unknown> = { ...(base as Record<string, unknown>) };
  for (const [key, value] of Object.entries(override)) {
    result[key] = deepMerge(result[key], value);
  }
  return result;
}

export function I18nProvider({
  children,
  overrides,
}: {
  children: ReactNode;
  /** Partial catalog overriding any subset of the default English messages. */
  overrides?: unknown;
}) {
  const messages = useMemo(
    () => (overrides ? deepMerge(defaultMessages, overrides) : defaultMessages),
    [overrides]
  );

  const t = useCallback(
    (key: MessageKey, vars?: MessageVars): string => {
      const value = resolvePath(messages, key);
      if (typeof value !== 'string') {
        throw new Error(`Missing message in catalog: ${key}`);
      }
      return interpolate(value, vars);
    },
    [messages]
  );

  const context = useMemo(() => ({ t }), [t]);
  return <I18nContext.Provider value={context}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18n {
  const context = useContext(I18nContext);
  if (!context.t) {
    throw new Error('useI18n must be used inside <I18nProvider>');
  }
  return context;
}
