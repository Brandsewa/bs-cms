import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor, userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '../src/components/accordion';
import { ScrollArea } from '../src/components/scroll-area';
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '../src/components/resizable';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '../src/components/command';

describe('Accordion', () => {
  it('expands and collapses content, exposing aria-expanded', async () => {
    const user = userEvent.setup();
    renderUI(
      <Accordion type="single" collapsible>
        <AccordionItem value="seo">
          <AccordionTrigger>SEO panel</AccordionTrigger>
          <AccordionContent>Meta title and description.</AccordionContent>
        </AccordionItem>
      </Accordion>
    );
    const trigger = screen.getByRole('button', { name: 'SEO panel' });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await user.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Meta title and description.')).toBeInTheDocument();
    await user.click(trigger);
    await waitFor(() =>
      expect(trigger).toHaveAttribute('aria-expanded', 'false')
    );
  });

  it('toggles with Enter from the keyboard', async () => {
    const user = userEvent.setup();
    renderUI(
      <Accordion type="single" collapsible>
        <AccordionItem value="advanced">
          <AccordionTrigger>Advanced settings</AccordionTrigger>
          <AccordionContent>Slug options.</AccordionContent>
        </AccordionItem>
      </Accordion>
    );
    const trigger = screen.getByRole('button', { name: 'Advanced settings' });
    trigger.focus();
    await user.keyboard('{Enter}');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByText('Slug options.')).toBeInTheDocument();
  });

  it('single mode keeps one section open', async () => {
    const user = userEvent.setup();
    renderUI(
      <Accordion type="single" collapsible>
        <AccordionItem value="a">
          <AccordionTrigger>Section A</AccordionTrigger>
          <AccordionContent>Content A</AccordionContent>
        </AccordionItem>
        <AccordionItem value="b">
          <AccordionTrigger>Section B</AccordionTrigger>
          <AccordionContent>Content B</AccordionContent>
        </AccordionItem>
      </Accordion>
    );
    await user.click(screen.getByRole('button', { name: 'Section A' }));
    await user.click(screen.getByRole('button', { name: 'Section B' }));
    expect(screen.queryByText('Content A')).not.toBeInTheDocument();
    expect(screen.getByText('Content B')).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <Accordion type="single" collapsible>
        <AccordionItem value="x">
          <AccordionTrigger>Header</AccordionTrigger>
          <AccordionContent>Body</AccordionContent>
        </AccordionItem>
      </Accordion>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('ScrollArea', () => {
  it('renders its children inside the viewport', () => {
    renderUI(
      <ScrollArea className="h-32 w-64" aria-label="Activity">
        <div>Item 1</div>
        <div>Item 2</div>
      </ScrollArea>
    );
    expect(screen.getByText('Item 1')).toBeInTheDocument();
    expect(screen.getByText('Item 2')).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <ScrollArea className="h-32" aria-label="Scroll region">
        <p>Content</p>
      </ScrollArea>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('ResizablePanelGroup', () => {
  it('renders panels with percentage sizes and a labelled separator', () => {
    renderUI(
      <ResizablePanelGroup direction="horizontal" aria-label="Layout">
        <ResizablePanel defaultSize={70}>
          <div>Main</div>
        </ResizablePanel>
        <ResizableHandle aria-label="Resize layout" />
        <ResizablePanel defaultSize={30} minSize={15}>
          <div>Sidebar</div>
        </ResizablePanel>
      </ResizablePanelGroup>
    );
    const separator = screen.getByRole('separator', { name: 'Resize layout' });
    expect(separator).toHaveAttribute('aria-valuenow', '70');
    expect(separator).toHaveAttribute('aria-valuemin', '10');
    expect(separator).toHaveAttribute('aria-valuemax', '85');
    expect(screen.getByText('Main')).toBeInTheDocument();
  });

  it('rejects malformed child structures', () => {
    expect(() =>
      renderUI(
        <ResizablePanelGroup direction="horizontal">
          <ResizableHandle />
          <ResizablePanel defaultSize={100} />
        </ResizablePanelGroup>
      )
    ).toThrow(/alternating/);
  });

  it('resizes with keyboard and honours the minimum', async () => {
    const user = userEvent.setup();
    renderUI(
      <ResizablePanelGroup direction="horizontal">
        <ResizablePanel defaultSize={20} minSize={18}>
          <div>Left</div>
        </ResizablePanel>
        <ResizableHandle aria-label="Split" />
        <ResizablePanel defaultSize={80}>
          <div>Right</div>
        </ResizablePanel>
      </ResizablePanelGroup>
    );
    const separator = screen.getByRole('separator', { name: 'Split' });
    separator.focus();
    await user.keyboard('{ArrowRight}');
    expect(separator).toHaveAttribute('aria-valuenow', '22');
    await user.keyboard('{ArrowLeft}');
    await user.keyboard('{ArrowLeft}');
    await user.keyboard('{ArrowLeft}');
    await user.keyboard('{ArrowLeft}');
    await user.keyboard('{ArrowLeft}');
    // Clamped at minSize=18.
    expect(separator).toHaveAttribute('aria-valuenow', '18');
    await user.keyboard('{End}');
    expect(separator).toHaveAttribute('aria-valuenow', '90');
    await user.keyboard('{Home}');
    expect(separator).toHaveAttribute('aria-valuenow', '18');
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <ResizablePanelGroup direction="horizontal" aria-label="Split view">
        <ResizablePanel defaultSize={50}>
          <div>Editor</div>
        </ResizablePanel>
        <ResizableHandle aria-label="Split" />
        <ResizablePanel defaultSize={50}>
          <div>Preview</div>
        </ResizablePanel>
      </ResizablePanelGroup>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Command', () => {
  it('filters items by input and selects with Enter', async () => {
    const onGoToPosts = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Command aria-label="Command menu">
        <CommandInput placeholder="Type a command or search…" />
        <CommandList>
          <CommandEmpty>No results found.</CommandEmpty>
          <CommandGroup heading="Navigation">
            <CommandItem onSelect={onGoToPosts}>Go to Posts</CommandItem>
            <CommandItem onSelect={vi.fn()}>Go to Media</CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
    );
    const input = screen.getByPlaceholderText('Type a command or search…');
    await user.type(input, 'posts');
    expect(screen.getByText('Go to Posts')).toBeInTheDocument();
    expect(screen.queryByText('Go to Media')).not.toBeInTheDocument();
    await user.keyboard('{Enter}');
    expect(onGoToPosts).toHaveBeenCalledOnce();
  });

  it('shows the empty state when nothing matches', async () => {
    const user = userEvent.setup();
    renderUI(
      <Command aria-label="Command menu">
        <CommandInput placeholder="Search" />
        <CommandList>
          <CommandEmpty>No results found.</CommandEmpty>
          <CommandItem onSelect={vi.fn()}>Go to Posts</CommandItem>
        </CommandList>
      </Command>
    );
    await user.type(screen.getByPlaceholderText('Search'), 'zzzz');
    expect(screen.getByText('No results found.')).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <Command aria-label="Command menu">
        <CommandInput placeholder="Search" />
        <CommandList>
          <CommandEmpty>Nothing.</CommandEmpty>
          <CommandGroup heading="Actions">
            <CommandItem onSelect={vi.fn()}>Publish</CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
