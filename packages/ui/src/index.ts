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

// Overlays and navigation
export { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from './components/accordion';
export {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogOverlay,
  AlertDialogPortal,
  AlertDialogTitle,
  AlertDialogTrigger,
} from './components/alert-dialog';
export {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
} from './components/command';
export {
  ContextMenu,
  ContextMenuCheckboxItem,
  ContextMenuContent,
  ContextMenuGroup,
  ContextMenuItem,
  ContextMenuLabel,
  ContextMenuPortal,
  ContextMenuRadioGroup,
  ContextMenuRadioItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from './components/context-menu';
export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
} from './components/dialog';
export {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuPortal,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from './components/dropdown-menu';
export { HoverCard, HoverCardContent, HoverCardTrigger } from './components/hover-card';
export { Popover, PopoverAnchor, PopoverClose, PopoverContent, PopoverTrigger } from './components/popover';
export { ResizableHandle, ResizablePanel, ResizablePanelGroup } from './components/resizable';
export type { ResizableHandleProps, ResizablePanelProps } from './components/resizable';
export { ScrollArea, ScrollBar } from './components/scroll-area';
export {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from './components/sheet';
export { Tabs, TabsContent, TabsList, TabsTrigger } from './components/tabs';

// Date and time
export {
  Calendar,
  DatePicker,
  DateRangePicker,
  DateTimePicker,
  RelativeTime,
  TimeInput,
  timePattern,
} from './date';
export type {
  CalendarProps,
  DatePickerProps,
  DateRangePickerProps,
  DateRangeValue,
  DateTimePickerProps,
  RelativeTimeProps,
  TimeInputProps,
} from './date';

// Form system
export {
  ConditionalField,
  Form,
  FormErrorSummary,
  FormItemLayout,
  KeyValueArrayField,
  SingleValueArrayField,
  collectErrors,
  useDirtyGuard,
  zodResolver,
} from './form';
export type {
  ConditionalFieldProps,
  DirtyGuard,
  DirtyGuardDialogStrings,
  FieldErrorEntry,
  FormErrorSummaryProps,
  FormItemLayoutProps,
  FormProps,
  KeyValueArrayFieldProps,
  KeyValueItem,
  SingleValueArrayFieldProps,
  UseDirtyGuardOptions,
} from './form';

// Feedback and status
export { Alert, alertVariants } from './feedback';
export type { AlertProps, AlertVariant } from './feedback';
export { ConfirmationModal, TypeToConfirmDialog } from './feedback';
export type { ConfirmationModalProps, TypeToConfirmDialogProps } from './feedback';
export { MetricCard, StatusBadge, StatusDot } from './feedback';
export type { EntryStatus, MetricCardProps, StatusDotProps } from './feedback';
export { undoToast, problemToast } from './feedback';
export type { UndoToastOptions, ProblemDetailsLike, ProblemToastOptions } from './feedback';
export {
  Combobox,
  type ComboboxOption,
  type ComboboxProps,
} from './components/combobox';
export { InputOTP } from './components/input-otp';
export type { InputOTPProps } from './components/input-otp';
export { MultiSelect } from './components/multi-select';
export type { MultiSelectProps } from './components/multi-select';
export { TagInput } from './components/tag-input';
export type { TagInputProps } from './components/tag-input';
export { Toaster, errorToast, infoToast, successToast, toast } from './components/toaster';
export { TreeView } from './components/tree-view';
export type { TreeNode, TreeViewProps } from './components/tree-view';
export {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipRoot,
  TooltipTrigger,
} from './components/tooltip';

// Layout
export {
  AppSidebar,
  Banner,
  BannerArea,
  Breadcrumbs,
  PageBreadcrumbs,
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
  EmptyState,
  ErrorState,
  ForbiddenState,
  NotFoundState,
  PageLoadingSkeleton,
  PageContainer,
  PageHeader,
  PageNav,
  PageSection,
  Pagination,
  Stepper,
} from './layout';
export type {
  AppSidebarProps,
  BannerProps,
  BannerVariant,
  BreadcrumbItem,
  BreadcrumbsProps,
  EmptyStateProps,
  ErrorStateProps,
  ForbiddenStateProps,
  NotFoundStateProps,
  PageLoadingSkeletonProps,
  PageContainerProps,
  PageHeaderProps,
  PageNavItem,
  PageNavProps,
  PageSectionProps,
  PaginationProps,
  SidebarGroup,
  SidebarItem,
  StepperProps,
} from './layout';

// DataTable (docs/02-ui-spec.md 3.1)
export { DataTable, describeFilter, applyFilters } from './data-table';
export type { DataTableProps, DataTableState, TableSorting } from './data-table';
export type {
  ActiveFilter,
  BulkActionOutcome,
  BulkFailure,
  CursorInfo,
  DataTableBulkAction,
  DataTableColumn,
  DataTableError,
  DataTableFilter,
  Density,
  FieldType,
  FilterType,
  FilterValue,
  ReferencedItem,
  RowMenuItem,
  SavedView,
  StatusTab,
  StatusValue,
} from './data-table';

// Pickers
export {
  MediaPicker,
  RelationPicker,
  SlugInput,
  TermPicker,
  UserPicker,
  slugify,
} from './pickers';
export type {
  MediaPickerProps,
  PickerFolder,
  PickerMediaItem,
  PickerTermNode,
  PickerUser,
  RelationPickerProps,
  SlugAvailability,
  SlugInputProps,
  TermPickerProps,
  UploadHandlerResult,
  UserPickerProps,
} from './pickers';

// Hooks
export { useHotkeys } from './hooks/use-hotkeys';
export type { HotkeyItem, UseHotkeysOptions } from './hooks/use-hotkeys';

