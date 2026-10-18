import { describe, expect, it } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerTitle,
  DrawerTrigger,
} from '../src/components/sheet';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '../src/components/sheet';
import { Button } from '../src/components/button';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '../src/components/tabs';

describe('Sheet', () => {
  it('opens as a side panel, traps focus and closes via close button', async () => {
    const user = userEvent.setup();
    renderUI(
      <Sheet>
        <SheetTrigger>Quick edit</SheetTrigger>
        <SheetContent side="right">
          <SheetHeader>
            <SheetTitle>Quick edit</SheetTitle>
            <SheetDescription>Change title, slug and status.</SheetDescription>
          </SheetHeader>
          <SheetFooter>
            <SheetClose asChild>
              <Button variant="outline">Done</Button>
            </SheetClose>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    );
    await user.click(screen.getByRole('button', { name: 'Quick edit' }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(screen.getByText('Change title, slug and status.')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Done' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('closes on Escape', async () => {
    const user = userEvent.setup();
    renderUI(
      <Sheet>
        <SheetTrigger>Filters</SheetTrigger>
        <SheetContent side="bottom">
          <SheetTitle>Filters</SheetTitle>
        </SheetContent>
      </Sheet>
    );
    await user.click(screen.getByRole('button', { name: 'Filters' }));
    await screen.findByRole('dialog');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <Sheet>
        <SheetTrigger aria-label="Open media sheet">Media</SheetTrigger>
        <SheetContent>
          <SheetTitle>Media library</SheetTitle>
          <SheetDescription>Pick an image.</SheetDescription>
        </SheetContent>
      </Sheet>
    );
    await user.click(screen.getByRole('button', { name: 'Open media sheet' }));
    await screen.findByRole('dialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('Drawer', () => {
  it('renders as a bottom sheet with title and description', async () => {
    const user = userEvent.setup();
    renderUI(
      <Drawer>
        <DrawerTrigger>Open menu</DrawerTrigger>
        <DrawerContent>
          <DrawerTitle>Navigation</DrawerTitle>
          <DrawerDescription>Choose a section.</DrawerDescription>
        </DrawerContent>
      </Drawer>
    );
    await user.click(screen.getByRole('button', { name: 'Open menu' }));
    await screen.findByRole('dialog');
    expect(screen.getByText('Navigation')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });
});

describe('Tabs', () => {
  it('switches content on click and marks the active trigger', async () => {
    const user = userEvent.setup();
    renderUI(
      <Tabs defaultValue="content">
        <TabsList>
          <TabsTrigger value="content">Content</TabsTrigger>
          <TabsTrigger value="seo">SEO</TabsTrigger>
        </TabsList>
        <TabsContent value="content">Body fields</TabsContent>
        <TabsContent value="seo">SEO panel</TabsContent>
      </Tabs>
    );
    expect(screen.getByRole('tab', { name: 'Content' })).toHaveAttribute('data-state', 'active');
    expect(screen.getByText('Body fields')).toBeInTheDocument();
    await user.click(screen.getByRole('tab', { name: 'SEO' }));
    expect(screen.getByText('SEO panel')).toBeInTheDocument();
    expect(screen.queryByText('Body fields')).not.toBeInTheDocument();
  });

  it('activates with arrow keys and renders inactive panels hidden', async () => {
    const user = userEvent.setup();
    renderUI(
      <Tabs defaultValue="a">
        <TabsList aria-label="Editor tabs">
          <TabsTrigger value="a">Fields</TabsTrigger>
          <TabsTrigger value="b">Revisions</TabsTrigger>
        </TabsList>
        <TabsContent value="a">Field list</TabsContent>
        <TabsContent value="b">Revision list</TabsContent>
      </Tabs>
    );
    screen.getByRole('tab', { name: 'Fields' }).focus();
    await user.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Revisions' })).toHaveFocus();
    expect(screen.getByRole('tab', { name: 'Revisions' })).toHaveAttribute('data-state', 'active');
  });

  it('supports disabled tabs', async () => {
    const user = userEvent.setup();
    renderUI(
      <Tabs defaultValue="a">
        <TabsList>
          <TabsTrigger value="a">Open</TabsTrigger>
          <TabsTrigger value="b" disabled>
            Locked
          </TabsTrigger>
        </TabsList>
        <TabsContent value="a">Visible</TabsContent>
        <TabsContent value="b">Hidden</TabsContent>
      </Tabs>
    );
    await user.click(screen.getByRole('tab', { name: 'Locked' }));
    expect(screen.getByText('Visible')).toBeInTheDocument();
  });

  it('passes axe with an accessible label on the tab list', async () => {
    const { container } = renderUI(
      <Tabs defaultValue="one" aria-label="Entry sections">
        <TabsList>
          <TabsTrigger value="one">One</TabsTrigger>
          <TabsTrigger value="two">Two</TabsTrigger>
        </TabsList>
        <TabsContent value="one">First</TabsContent>
        <TabsContent value="two">Second</TabsContent>
      </Tabs>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
