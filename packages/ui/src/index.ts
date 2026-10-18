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

// Atoms
export { Avatar, AvatarFallback, AvatarImage } from './components/avatar';
export { Badge, badgeVariants } from './components/badge';
export { Button, buttonVariants } from './components/button';
export type { ButtonProps } from './components/button';
export { Checkbox } from './components/checkbox';
export type { CheckboxProps } from './components/checkbox';
export { Input } from './components/input';
export type { InputProps } from './components/input';
export { Kbd } from './components/kbd';
export { Label } from './components/label';
export { Progress } from './components/progress';
export type { ProgressProps } from './components/progress';
export { RadioCard, RadioGroup, RadioGroupItem } from './components/radio-group';
export type { RadioCardProps } from './components/radio-group';
export {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectScrollDownButton,
  SelectScrollUpButton,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from './components/select';
export { Separator } from './components/separator';
export { Skeleton } from './components/skeleton';
export { Slider } from './components/slider';
export type { SliderProps } from './components/slider';
export { Spinner } from './components/spinner';
export { Switch } from './components/switch';
export type { SwitchProps } from './components/switch';
export { Textarea } from './components/textarea';
export type { TextareaProps } from './components/textarea';
export { Toggle, toggleVariants } from './components/toggle';
export { ToggleGroup, ToggleGroupItem } from './components/toggle-group';
