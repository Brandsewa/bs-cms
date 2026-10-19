import { describe, expect, it, vi } from 'vitest';

vi.mock('../src/lib/utils', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../src/lib/utils')>()),
  copyToClipboard: vi.fn().mockResolvedValue(true),
}));
import { render, renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  Banner,
  BannerArea,
  Breadcrumbs,
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
  PageContainer,
  PageHeader,
  PageNav,
  PageSection,
  Pagination,
  Stepper,
  AppSidebar,
  type SidebarGroup,
} from '../src/layout';
import { FolderPlus, Home } from 'lucide-react';

describe('PageContainer / PageHeader / PageSection / PageNav', () => {
  it('applies the width tiers', () => {
    const { rerender } = render(<PageContainer width="small" data-testid="c" />);
    expect(screen.getByTestId('c')).toHaveClass('max-w-3xl');
    rerender(<PageContainer width="default" data-testid="c" />);
    expect(screen.getByTestId('c')).toHaveClass('max-w-6xl');
    rerender(<PageContainer width="full" data-testid="c" />);
    expect(screen.getByTestId('c')).toHaveClass('max-w-none');
  });

  it('renders header with description, meta and actions', () => {
    renderUI(
      <PageHeader
        title="Posts"
        description="All blog posts"
        meta={<span>128 entries</span>}
        actions={<button type="button">New post</button>}
      />
    );
    expect(screen.getByRole('heading', { level: 1, name: 'Posts' })).toBeInTheDocument();
    expect(screen.getByText('All blog posts')).toBeInTheDocument();
    expect(screen.getByText('128 entries')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New post' })).toBeInTheDocument();
  });

  it('renders sections with title, description and actions', () => {
    renderUI(
      <PageSection title="Publish box" description="Status and scheduling" actions={<button type="button">Edit</button>}>
        <p>content</p>
      </PageSection>
    );
    expect(screen.getByRole('heading', { name: 'Publish box' })).toBeInTheDocument();
    expect(screen.getByText('Status and scheduling')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
  });

  it('PageNav switches items with counts and aria-selected', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <PageNav
        aria-label="Collection tabs"
        value="all"
        onChange={onChange}
        items={[
          { id: 'all', label: 'All', count: 128 },
          { id: 'drafts', label: 'Drafts', count: 4 },
        ]}
      />
    );
    const all = screen.getByRole('tab', { name: /all/i });
    expect(all).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByText('128')).toBeInTheDocument();
    await user.click(screen.getByRole('tab', { name: /drafts/i }));
    expect(onChange).toHaveBeenCalledWith('drafts');
  });

  it('passes axe for the page scaffolding', async () => {
    const { container } = renderUI(
      <PageContainer width="default">
        <PageHeader title="Posts" actions={<button type="button">New</button>} />
        <PageNav aria-label="Tabs" value="all" onChange={() => {}} items={[{ id: 'all', label: 'All' }]} />
        <PageSection title="Section">
          <p>Body</p>
        </PageSection>
      </PageContainer>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Breadcrumbs', () => {
  it('renders links and marks the last item as the current page', () => {
    renderUI(
      <Breadcrumbs
        items={[
          { label: 'Home', href: '/' },
          { label: 'Content', href: '/content' },
          { label: 'Posts' },
        ]}
      />
    );
    expect(screen.getByText('Posts').closest('[aria-current="page"]')).toBeInTheDocument();
    expect(screen.getByText('Home').closest('a')).toHaveAttribute('href', '/');
  });

  it('supports a custom link renderer', () => {
    renderUI(
      <Breadcrumbs
        items={[{ label: 'Home', href: '/' }, { label: 'Here' }]}
        renderLink={(item, content) => (
          <a href={item.href} data-testid="router-link">
            {content}
          </a>
        )}
      />
    );
    expect(screen.getByTestId('router-link')).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(<Breadcrumbs items={[{ label: 'Home', href: '/' }, { label: 'Here' }]} />);
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Card', () => {
  it('renders header, title, description, content and footer', () => {
    renderUI(
      <Card>
        <CardHeader>
          <CardTitle>Site health</CardTitle>
          <CardDescription>Two issues need attention</CardDescription>
        </CardHeader>
        <CardContent>Body</CardContent>
        <CardFooter>
          <button type="button">Run lint</button>
        </CardFooter>
      </Card>
    );
    expect(screen.getByText('Site health')).toBeInTheDocument();
    expect(screen.getByText('Two issues need attention')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Run lint' })).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <Card>
        <CardHeader>
          <CardTitle>Title</CardTitle>
          <CardDescription>Description</CardDescription>
        </CardHeader>
        <CardContent>Body</CardContent>
      </Card>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Empty / Error / NotFound / Forbidden states', () => {
  it('EmptyState shows icon, title, description and action', () => {
    renderUI(
      <EmptyState
        icon={<FolderPlus />}
        title="No entries yet"
        description="Create your first post."
        action={<button type="button">New post</button>}
      />
    );
    expect(screen.getByText('No entries yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New post' })).toBeInTheDocument();
  });

  it('ErrorState shows message, retry and a copyable request id', async () => {
    const { copyToClipboard } = await import('../src/lib/utils');
    const copyMock = vi.mocked(copyToClipboard);
    copyMock.mockClear();
    const onRetry = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <ErrorState message="Database unavailable" requestId="req_01HX" onRetry={onRetry} />
    );
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText('Database unavailable')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalledOnce();
    await user.click(screen.getByRole('button', { name: 'Copy request id' }));
    expect(copyMock).toHaveBeenCalledWith('req_01HX');
  });

  it('NotFound and Forbidden states offer back and home actions', async () => {
    const onBack = vi.fn();
    const onHome = vi.fn();
    const user = userEvent.setup();
    renderUI(<NotFoundState onBack={onBack} onHome={onHome} />);
    await user.click(screen.getByRole('button', { name: 'Go back' }));
    await user.click(screen.getByRole('button', { name: 'Back to dashboard' }));
    expect(onBack).toHaveBeenCalledOnce();
    expect(onHome).toHaveBeenCalledOnce();

    renderUI(<ForbiddenState />);
    expect(screen.getByText('No access')).toBeInTheDocument();
  });

  it('passes axe for all states', async () => {
    const { container } = renderUI(
      <div>
        <EmptyState title="Empty" />
        <ErrorState requestId="req_1" onRetry={() => {}} />
        <NotFoundState onBack={() => {}} onHome={() => {}} />
        <ForbiddenState onHome={() => {}} />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Banner area', () => {
  it('renders variants with dismiss and action', async () => {
    const onDismiss = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <BannerArea aria-label="System banners">
        <Banner variant="warning" onDismiss={onDismiss}>
          Email is not configured.
        </Banner>
        <Banner variant="info" action={<button type="button">Configure</button>}>
          Cron has not run for 20 minutes.
        </Banner>
      </BannerArea>
    );
    const region = screen.getByRole('region', { name: 'System banners' });
    expect(region).toBeInTheDocument();
    expect(screen.getAllByRole('status').length).toBeGreaterThanOrEqual(2);
    await user.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(onDismiss).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Configure' })).toBeInTheDocument();
  });

  it('passes axe with all variants', async () => {
    const { container } = renderUI(
      <BannerArea>
        <Banner variant="info">Info</Banner>
        <Banner variant="warning">Warning</Banner>
        <Banner variant="error">Error</Banner>
        <Banner variant="success">Success</Banner>
      </BannerArea>
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Stepper', () => {
  const steps = [
    { id: '1', label: 'Database', description: 'Connection details' },
    { id: '2', label: 'Owner account' },
    { id: '3', label: 'Finish' },
  ];

  it('marks complete, current and upcoming steps', () => {
    renderUI(<Stepper steps={steps} current={1} aria-label="Installer steps" />);
    expect(screen.getByRole('list', { name: 'Installer steps' })).toBeInTheDocument();
    expect(screen.getByText('Database')).toBeInTheDocument();
    expect(screen.getByText('Owner account')).toBeInTheDocument();
    expect(screen.getByText('Finish')).toBeInTheDocument();
    expect(
      screen.getByText('Owner account').closest('[aria-current="step"]')
    ).toBeInTheDocument();
  });

  it('allows selecting completed steps when onStepSelect is given', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderUI(<Stepper steps={steps} current={2} onStepSelect={onSelect} aria-label="Steps" />);
    await user.click(screen.getByRole('button', { name: /step 1 of 3/i }));
    expect(onSelect).toHaveBeenCalledWith(0);
  });

  it('passes axe', async () => {
    const { container } = renderUI(<Stepper steps={steps} current={0} aria-label="Steps" />);
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Pagination', () => {
  it('navigates pages, clamps at bounds and supports go-to-page', async () => {
    const onPageChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <Pagination page={5} pageCount={20} onPageChange={onPageChange} />
    );
    await user.click(screen.getByRole('button', { name: 'Next page' }));
    expect(onPageChange).toHaveBeenLastCalledWith(6);
    await user.click(screen.getByRole('button', { name: 'Previous page' }));
    expect(onPageChange).toHaveBeenLastCalledWith(4);

    rerender(<Pagination page={1} pageCount={20} onPageChange={onPageChange} />);
    expect(screen.getByRole('button', { name: 'First page' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Last page' }));
    expect(onPageChange).toHaveBeenLastCalledWith(20);

    rerender(<Pagination page={20} pageCount={20} onPageChange={onPageChange} />);
    expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();

    rerender(<Pagination page={3} pageCount={20} onPageChange={onPageChange} />);
    const input = screen.getByLabelText('Go to page');
    await user.type(input, '12{Enter}');
    expect(onPageChange).toHaveBeenLastCalledWith(12);
  });

  it('reports invalid go-to-page input', async () => {
    const user = userEvent.setup();
    renderUI(<Pagination page={1} pageCount={5} onPageChange={() => {}} />);
    await user.type(screen.getByLabelText('Go to page'), '99{Enter}');
    expect(screen.getByRole('alert')).toHaveTextContent(/between 1 and 5/);
  });

  it('shows a window of numbered pages with the current one marked', () => {
    renderUI(
      <Pagination page={10} pageCount={20} onPageChange={() => {}} siblingCount={1} />
    );
    expect(screen.getByRole('button', { name: /page 10 of 20/i })).toHaveAttribute(
      'aria-current',
      'page'
    );
    expect(screen.getByRole('button', { name: /page 9 of 20/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /page 7 of 20/i })).not.toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <Pagination page={2} pageCount={10} onPageChange={() => {}} />
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('AppSidebar', () => {
  const groups: SidebarGroup[] = [
    {
      id: 'content',
      label: 'Content',
      items: [
        { id: 'pages', label: 'Pages', icon: Home },
        { id: 'posts', label: 'Posts', icon: Home, badge: '3' },
      ],
    },
    {
      id: 'developer',
      label: 'Developer',
      items: [
        { id: 'schema', label: 'Schema builder', permission: 'schema.read', icon: Home },
        { id: 'webhooks', label: 'Webhooks', permission: 'webhooks.read', icon: Home },
      ],
    },
  ];

  it('hides items the user has no permission for', () => {
    renderUI(
      <AppSidebar
        groups={groups}
        hasPermission={(permission) => permission === 'schema.read'}
        activeId="posts"
      />
    );
    expect(screen.getByText('Posts')).toBeInTheDocument();
    expect(screen.getByText('Schema builder')).toBeInTheDocument();
    expect(screen.queryByText('Webhooks')).not.toBeInTheDocument();
    // A group with no visible items disappears entirely:
    expect(screen.queryByText('Settings')).not.toBeInTheDocument();
  });

  it('marks the active item with aria-current', () => {
    renderUI(<AppSidebar groups={groups} activeId="posts" />);
    const active = screen.getByText('Posts').closest('a');
    expect(active).toHaveAttribute('aria-current', 'page');
  });

  it('collapses to icons with tooltips, remembers state and expands again', async () => {
    window.localStorage.removeItem('bs-sidebar-collapsed');
    const user = userEvent.setup();
    const { unmount } = renderUI(<AppSidebar groups={groups} />);

    await user.click(screen.getByRole('button', { name: 'Collapse sidebar' }));
    expect(window.localStorage.getItem('bs-sidebar-collapsed')).toBe('1');
    expect(screen.queryByText('Posts')).not.toBeInTheDocument();
    unmount();

    renderUI(<AppSidebar groups={groups} />);
    expect(screen.queryByText('Posts')).not.toBeInTheDocument(); // remembered
    await user.click(screen.getByRole('button', { name: 'Expand sidebar' }));
    expect(screen.getByText('Posts')).toBeInTheDocument();
    expect(window.localStorage.getItem('bs-sidebar-collapsed')).toBe('0');
  });

  it('supports a custom link renderer with active state', () => {
    renderUI(
      <AppSidebar
        groups={groups}
        activeId="pages"
        renderLink={(item, content, isActive) => (
          <a href={`/${item.id}`} data-active={isActive || undefined}>
            {content}
          </a>
        )}
      />
    );
    const link = screen.getByText('Pages').closest('a');
    expect(link).toHaveAttribute('href', '/pages');
    expect(link).toHaveAttribute('data-active');
  });

  it('passes axe expanded and collapsed', async () => {
    window.localStorage.removeItem('bs-sidebar-collapsed');
    const expanded = renderUI(<AppSidebar groups={groups} hasPermission={() => true} />);
    const expandedResults = await runAxe(expanded.container);
    expect(expandedResults.violations).toEqual([]);
    expanded.unmount();

    const collapsed = renderUI(
      <AppSidebar groups={groups} hasPermission={() => true} collapsed onCollapsedChange={() => {}} />
    );
    const collapsedResults = await runAxe(collapsed.container);
    expect(collapsedResults.violations).toEqual([]);
    collapsed.unmount();
  });
});
