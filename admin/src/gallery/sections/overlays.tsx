import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogTitle,
  AlertDialogTrigger,
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
  Button,
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogTitle,
  DialogTrigger,
  Drawer,
  DrawerContent,
  DrawerTitle,
  DrawerTrigger,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
  Popover,
  PopoverContent,
  PopoverTrigger,
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
  ScrollArea,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Tooltip,
} from '@bs/ui';
import { Trash2 } from 'lucide-react';
import type { GallerySection } from '../registry';

function MenusShowcase() {
  return (
    <div className="flex flex-wrap items-center gap-3">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline">Dropdown</Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuLabel>Actions</DropdownMenuLabel>
          <DropdownMenuItem>Edit</DropdownMenuItem>
          <DropdownMenuItem>Duplicate</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem destructive>
            <Trash2 /> Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <ContextMenu>
        <ContextMenuTrigger className="rounded-md border border-dashed border-border px-4 py-3 text-13">
          Right-click me
        </ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem>Copy URL</ContextMenuItem>
          <ContextMenuItem>Download</ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Tooltip content="Publishes immediately">
        <Button>Publish (?tooltip)</Button>
      </Tooltip>

      <HoverCard>
        <HoverCardTrigger asChild>
          <Button variant="link">@author</Button>
        </HoverCardTrigger>
        <HoverCardContent aria-label="Author card">
          <p className="text-13 font-medium">Ada Lovelace</p>
          <p className="text-xs text-muted-foreground">Managing editor · 42 posts</p>
        </HoverCardContent>
      </HoverCard>

      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline">Popover</Button>
        </PopoverTrigger>
        <PopoverContent aria-label="Example popover" className="w-56">
          <p className="text-13">Filters, pickers and small forms live in popovers.</p>
        </PopoverContent>
      </Popover>
    </div>
  );
}

function DialogsShowcase() {
  return (
    <div className="flex flex-wrap items-center gap-3">
      <Dialog>
        <DialogTrigger asChild>
          <Button>Dialog</Button>
        </DialogTrigger>
        <DialogContent>
          <DialogTitle>Edit entry</DialogTitle>
          <DialogDescription>Escape or overlay click closes; focus returns.</DialogDescription>
          <DialogFooter>
            <Button variant="outline">Cancel</Button>
            <Button>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="destructive">Alert dialog</Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogTitle>Delete user?</AlertDialogTitle>
          <AlertDialogDescription>The user loses access immediately.</AlertDialogDescription>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <Sheet>
        <SheetTrigger asChild>
          <Button variant="outline">Sheet (right)</Button>
        </SheetTrigger>
        <SheetContent>
          <SheetHeader>
            <SheetTitle>Quick edit</SheetTitle>
            <SheetDescription>Title, slug and status without leaving the list.</SheetDescription>
          </SheetHeader>
        </SheetContent>
      </Sheet>

      <Drawer>
        <DrawerTrigger asChild>
          <Button variant="outline">Drawer (bottom)</Button>
        </DrawerTrigger>
        <DrawerContent>
          <DrawerTitle>Mobile navigation</DrawerTitle>
        </DrawerContent>
      </Drawer>
    </div>
  );
}

function TabsAccordionShowcase() {
  return (
    <div className="max-w-xl space-y-6">
      <Tabs defaultValue="content">
        <TabsList aria-label="Editor sections">
          <TabsTrigger value="content">Content</TabsTrigger>
          <TabsTrigger value="seo">SEO</TabsTrigger>
          <TabsTrigger value="revisions" disabled>
            Revisions
          </TabsTrigger>
        </TabsList>
        <TabsContent value="content">Fields, taxonomies, featured image.</TabsContent>
        <TabsContent value="seo">SERP preview and checks.</TabsContent>
        <TabsContent value="revisions">Version history.</TabsContent>
      </Tabs>
      <Accordion type="single" collapsible>
        <AccordionItem value="seo">
          <AccordionTrigger>Advanced SEO</AccordionTrigger>
          <AccordionContent>Canonical, noindex, structured data.</AccordionContent>
        </AccordionItem>
        <AccordionItem value="slug">
          <AccordionTrigger>Slug options</AccordionTrigger>
          <AccordionContent>Auto-from-title, lock, redirect on change.</AccordionContent>
        </AccordionItem>
      </Accordion>
    </div>
  );
}

function LayoutShowcase() {
  return (
    <div className="space-y-6">
      <div className="h-40 w-full max-w-xl">
        <ResizablePanelGroup direction="horizontal" aria-label="Split view">
          <ResizablePanel defaultSize={60}>
            <div className="flex h-full items-center justify-center rounded-md border border-border bg-surface-100 text-13">
              Editor
            </div>
          </ResizablePanel>
          <ResizableHandle aria-label="Resize split" />
          <ResizablePanel defaultSize={40} minSize={20}>
            <div className="flex h-full items-center justify-center rounded-md border border-border bg-surface-100 text-13">
              Preview
            </div>
          </ResizablePanel>
        </ResizablePanelGroup>
      </div>
      <ScrollArea className="h-24 w-full max-w-md rounded-md border border-border" aria-label="Log">
        <div className="p-3 text-13">
          {Array.from({ length: 12 }, (_, index) => (
            <p key={index}>Log line {index + 1}</p>
          ))}
        </div>
      </ScrollArea>
      <Command aria-label="Command menu example" className="max-w-md rounded-md border border-border">
        <CommandInput placeholder="Type a command or search…" />
        <CommandList>
          <CommandEmpty>No results found.</CommandEmpty>
          <CommandGroup heading="Navigation">
            <CommandItem>Go to Posts</CommandItem>
            <CommandItem>Go to Media</CommandItem>
          </CommandGroup>
          <CommandGroup heading="Actions">
            <CommandItem>Publish current entry</CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
    </div>
  );
}

export const overlaysSection: GallerySection = {
  id: 'overlays',
  name: 'Overlays',
  entries: [
    {
      id: 'menus',
      name: 'Dropdown, Context menu, Tooltip, Hover card, Popover',
      description: 'Menus support checkbox/radio items and destructive styling; popovers are labelled dialogs.',
      render: () => <MenusShowcase />,
      props: [
        { name: 'side / align', type: "'top'|'right'|'bottom'|'left' / 'start'|'center'|'end'", defaultValue: "bottom / center", description: 'Positioning of floating content.' },
        { name: 'content (Tooltip)', type: 'ReactNode', description: 'Shown on hover and keyboard focus.' },
      ],
    },
    {
      id: 'dialogs',
      name: 'Dialog, Alert dialog, Sheet, Drawer',
      description: 'Focus trap, return focus, Escape and overlay click; sheet slides from the right, drawer from the bottom.',
      render: () => <DialogsShowcase />,
      props: [
        { name: 'side (Sheet)', type: "'right' | 'left' | 'top' | 'bottom'", defaultValue: "'right'", description: 'Edge the panel attaches to.' },
        { name: 'showClose', type: 'boolean', defaultValue: 'true', description: 'Renders the X close button.' },
      ],
    },
    {
      id: 'tabs-accordion',
      name: 'Tabs, Accordion',
      description: 'Tabs with arrow-key activation and disabled triggers; single-collapsible accordion.',
      render: () => <TabsAccordionShowcase />,
      props: [
        { name: 'defaultValue (Tabs)', type: 'string', description: 'Active tab when uncontrolled.' },
        { name: 'type (Accordion)', type: "'single' | 'multiple'", description: "Use with collapsible for single-mode close." },
      ],
    },
    {
      id: 'layout',
      name: 'Resizable, Scroll area, Command',
      description: 'Pointer + keyboard resizable split; styled scroll region; cmdk command list.',
      render: () => <LayoutShowcase />,
      props: [
        { name: 'direction (ResizablePanelGroup)', type: "'horizontal' | 'vertical'", description: 'Split axis; handles expose aria-valuenow.' },
        { name: 'minSize (ResizablePanel)', type: 'number (percent)', defaultValue: '10', description: 'Clamp for drag and keyboard resize.' },
      ],
    },
  ],
};
