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
export {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipRoot,
  TooltipTrigger,
} from './components/tooltip';
