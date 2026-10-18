/**
 * @bs/ui — BS CMS design system.
 * Tokens, atoms, fragments, hooks. Every export is part of the public API:
 * nothing here may leak gallery, mock or test code into production bundles.
 */

// Foundations
export { cn, escapeRegExp, isCtrlOrMeta } from './lib/utils';
export { I18nProvider, useI18n } from './i18n';
export type { MessageKey, Messages } from './i18n';
export {
  readStoredTheme,
  resolveTheme,
  systemPrefersDark,
  ThemeProvider,
  useTheme,
} from './theming';
export type { ResolvedTheme, ThemePreference } from './theming';
