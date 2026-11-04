import { FC } from 'react';
import {
  Card,
  CardContent,
  CardHeader,
  PageContainer,
  Skeleton,
} from '@bs/ui';

/**
 * Skeleton loader for the Collections Index view (/admin/entries).
 * Maps the 12-col / 3-col responsive grid of collection cards with
 * matching icons, titles, descriptions, and count pills.
 */
export const CollectionsGridSkeleton: FC = () => {
  return (
    <div
      role="status"
      aria-label="Loading collections"
      className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
      data-testid="collections-grid-skeleton"
    >
      <span className="sr-only">Loading collections…</span>
      {Array.from({ length: 6 }).map((_, index) => (
        <Card key={index} className="border-border bg-card shadow-xs">
          <CardContent className="flex items-start justify-between gap-3 pt-5">
            <div className="flex items-start gap-3 min-w-0 flex-1">
              {/* Collection Icon Frame */}
              <Skeleton className="h-10 w-10 shrink-0 rounded-md bg-surface-200" />
              <div className="min-w-0 flex-1 space-y-2">
                <div className="flex items-center gap-2">
                  <Skeleton className="h-4 w-28 rounded bg-surface-200" />
                  {index < 3 && (
                    <Skeleton className="h-3.5 w-12 rounded bg-surface-200" />
                  )}
                </div>
                <Skeleton className="h-3 w-44 rounded bg-surface-200" />
                <Skeleton className="h-3 w-32 rounded bg-surface-200" />
              </div>
            </div>
            {/* Entry Count Pill */}
            <Skeleton className="h-5 w-8 shrink-0 rounded-full bg-surface-200" />
          </CardContent>
        </Card>
      ))}
    </div>
  );
};

/**
 * Skeleton loader for the Pages Tree View mode (/admin/entries/pages).
 * Maps the nested hierarchy cards with indent levels, icons, slugs, and status pills.
 */
export const PagesTreeSkeleton: FC = () => {
  return (
    <Card
      role="status"
      aria-label="Loading pages tree"
      className="border-border bg-card shadow-xs"
      data-testid="pages-tree-skeleton"
    >
      <span className="sr-only">Loading pages tree…</span>
      <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
        <div className="space-y-1">
          <Skeleton className="h-3.5 w-36 rounded bg-surface-200" />
          <Skeleton className="h-3 w-64 rounded bg-surface-200" />
        </div>
        <div className="flex gap-2">
          <Skeleton className="h-7 w-20 rounded bg-surface-200" />
          <Skeleton className="h-7 w-20 rounded bg-surface-200" />
        </div>
      </CardHeader>
      <CardContent className="p-4 space-y-2">
        {[
          { level: 0, titleW: 'w-44', slugW: 'w-24' },
          { level: 1, titleW: 'w-36', slugW: 'w-20' },
          { level: 1, titleW: 'w-40', slugW: 'w-28' },
          { level: 2, titleW: 'w-32', slugW: 'w-24' },
          { level: 0, titleW: 'w-48', slugW: 'w-20' },
          { level: 0, titleW: 'w-36', slugW: 'w-16' },
        ].map((item, idx) => (
          <div
            key={idx}
            style={{ marginLeft: `${item.level * 24}px` }}
            className="flex items-center gap-2.5 rounded-md border border-border/70 p-2.5 bg-card"
          >
            <Skeleton className="h-3.5 w-3.5 rounded shrink-0 bg-surface-200" />
            <Skeleton className="h-4 w-4 rounded shrink-0 bg-surface-200" />
            <Skeleton className={`h-4 ${item.titleW} rounded bg-surface-200`} />
            <Skeleton className={`h-3 ${item.slugW} rounded bg-surface-200`} />
            <div className="ml-auto flex items-center gap-2">
              <Skeleton className="h-5 w-16 rounded-full bg-surface-200" />
              <Skeleton className="h-6 w-14 rounded bg-surface-200" />
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
};

export interface EntryEditorSkeletonProps {
  collectionHandle?: string;
  isNew?: boolean;
}

/**
 * Structural Skeleton loader for Entry Editor (/admin/entries/:collection/:id).
 * Directly maps the 12-col form layout, top breadcrumb bar, rich text editor or visual builder,
 * SEO, Publish panel, Featured image panel, Author panel, and Taxonomy checklists.
 */
export const EntryEditorSkeleton: FC<EntryEditorSkeletonProps> = ({
  collectionHandle = 'posts',
  isNew: _isNew = false,
}) => {
  const isPage = collectionHandle === 'pages';
  const isAuthor = collectionHandle === 'authors';

  return (
    <PageContainer
      width="full"
      className="space-y-6"
      role="status"
      aria-label={`Loading ${collectionHandle} editor`}
      data-testid="entry-editor-skeleton"
    >
      <span className="sr-only">Loading editor…</span>

      {/* Top Breadcrumb & Navigation Bar */}
      <div className="flex items-center justify-between border-b border-border pb-4">
        <div className="flex items-center gap-3">
          <Skeleton className="h-8 w-8 rounded-md shrink-0 bg-surface-200" />
          <div className="flex items-center gap-2">
            <Skeleton className="h-3.5 w-16 rounded bg-surface-200" />
            <span className="text-xs text-muted-foreground">/</span>
            <Skeleton className="h-4 w-36 rounded bg-surface-200" />
          </div>
        </div>

        {/* Global Save Button in Header */}
        <div className="flex items-center gap-2">
          <Skeleton className="h-8 w-20 rounded-md bg-surface-200" />
        </div>
      </div>

      {/* Main 12-Column Form Layout */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left / Center Column (8 cols): Main Content Fields */}
        <div className="space-y-6 lg:col-span-8">
          <Card className="border-border bg-card shadow-xs">
            <CardContent className="p-6 space-y-5">
              {/* Main Title / Name Field */}
              <div className="space-y-2">
                <Skeleton className="h-3 w-24 rounded bg-surface-200" />
                <Skeleton className="h-11 w-full rounded-md bg-surface-200" />
              </div>

              {/* Pages: URL slug input + Customize Page visual builder button */}
              {isPage ? (
                <div className="space-y-3 pt-2">
                  <Skeleton className="h-3 w-20 rounded bg-surface-200" />
                  <div className="flex items-center gap-2">
                    <Skeleton className="h-4 w-12 rounded bg-surface-200 shrink-0" />
                    <Skeleton className="h-9 flex-1 rounded-md bg-surface-200" />
                  </div>
                  <Skeleton className="h-3 w-64 rounded bg-surface-200" />
                  <div className="pt-1">
                    <Skeleton className="h-8 w-36 rounded-md bg-surface-200" />
                  </div>
                </div>
              ) : isAuthor ? (
                /* Authors: Job Title, Bio, Social Links */
                <div className="space-y-4 pt-1">
                  <div className="space-y-2">
                    <Skeleton className="h-3 w-20 rounded bg-surface-200" />
                    <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
                  </div>
                  <div className="space-y-2">
                    <Skeleton className="h-3 w-24 rounded bg-surface-200" />
                    <Skeleton className="h-28 w-full rounded-md bg-surface-200" />
                  </div>
                  <div className="space-y-2">
                    <Skeleton className="h-3 w-36 rounded bg-surface-200" />
                    <div className="flex items-center gap-2">
                      <Skeleton className="h-9 flex-1 rounded-md bg-surface-200" />
                      <Skeleton className="h-9 w-20 rounded-md bg-surface-200" />
                    </div>
                  </div>
                </div>
              ) : (
                /* Posts & Standard Content: RichText Editor Frame */
                <div className="space-y-2.5 pt-2">
                  <Skeleton className="h-3 w-28 rounded bg-surface-200" />
                  <div className="rounded-md border border-border bg-background overflow-hidden">
                    {/* Toolbar Skeleton */}
                    <div className="flex items-center gap-1.5 border-b border-border bg-surface-100 p-2">
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <div className="h-4 w-px bg-border mx-1" />
                      <Skeleton className="h-6 w-16 rounded bg-surface-200" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <div className="h-4 w-px bg-border mx-1" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                      <Skeleton className="h-6 w-6 rounded bg-surface-200" />
                    </div>
                    {/* Prose Paragraph Lines Skeleton */}
                    <div className="p-4 space-y-3 min-h-[220px]">
                      <Skeleton className="h-4 w-11/12 rounded bg-surface-200" />
                      <Skeleton className="h-4 w-full rounded bg-surface-200" />
                      <Skeleton className="h-4 w-4/5 rounded bg-surface-200" />
                      <Skeleton className="h-4 w-9/12 rounded bg-surface-200" />
                      <Skeleton className="h-4 w-2/5 rounded bg-surface-200" />
                    </div>
                  </div>
                </div>
              )}

              {/* Excerpt / Summary (for non-author collections) */}
              {!isAuthor && (
                <div className="space-y-2 pt-2">
                  <Skeleton className="h-3 w-32 rounded bg-surface-200" />
                  <Skeleton className="h-20 w-full rounded-md bg-surface-200" />
                </div>
              )}

              {/* Featured Switch Card */}
              {!isAuthor && (
                <div className="flex items-center justify-between rounded-md border border-border bg-surface-100 p-3">
                  <div className="space-y-1.5">
                    <Skeleton className="h-3.5 w-24 rounded bg-surface-200" />
                    <Skeleton className="h-3 w-56 rounded bg-surface-200" />
                  </div>
                  <Skeleton className="h-5 w-9 rounded-full bg-surface-200" />
                </div>
              )}
            </CardContent>
          </Card>

          {/* Pages / SEO Card */}
          {isPage && (
            <Card className="border-border bg-card shadow-xs">
              <CardHeader className="p-4 pb-2 border-b border-border">
                <Skeleton className="h-3.5 w-16 rounded bg-surface-200" />
              </CardHeader>
              <CardContent className="p-4 space-y-4">
                <div className="space-y-2">
                  <Skeleton className="h-3 w-20 rounded bg-surface-200" />
                  <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
                </div>
                <div className="space-y-2">
                  <Skeleton className="h-3 w-28 rounded bg-surface-200" />
                  <Skeleton className="h-16 w-full rounded-md bg-surface-200" />
                </div>
              </CardContent>
            </Card>
          )}
        </div>

        {/* Right Column (4 cols): Panels Skeleton */}
        <div className="space-y-6 lg:col-span-4">
          {/* Publish / Status Panel */}
          <Card className="border-border bg-card shadow-xs">
            <CardHeader className="p-4 border-b border-border flex flex-row items-center justify-between">
              <Skeleton className="h-3.5 w-16 rounded bg-surface-200" />
              <Skeleton className="h-4 w-16 rounded-full bg-surface-200" />
            </CardHeader>
            <CardContent className="p-4 space-y-4">
              <div className="space-y-1.5">
                <Skeleton className="h-3 w-12 rounded bg-surface-200" />
                <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
              </div>
              <div className="space-y-1.5">
                <Skeleton className="h-3 w-24 rounded bg-surface-200" />
                <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
              </div>
              <div className="pt-2 flex flex-col gap-2">
                <Skeleton className="h-8 w-full rounded-md bg-surface-200" />
                <div className="grid grid-cols-2 gap-2">
                  <Skeleton className="h-8 rounded-md bg-surface-200" />
                  <Skeleton className="h-8 rounded-md bg-surface-200" />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Featured Image / Avatar Panel */}
          <Card className="border-border bg-card shadow-xs">
            <CardHeader className="p-4 border-b border-border">
              <Skeleton className="h-3.5 w-28 rounded bg-surface-200" />
            </CardHeader>
            <CardContent className="p-4">
              <Skeleton className="h-32 w-full rounded-md border border-dashed border-border bg-surface-100" />
            </CardContent>
          </Card>

          {/* Author Panel (for posts/pages) */}
          {!isAuthor && (
            <Card className="border-border bg-card shadow-xs">
              <CardHeader className="p-4 border-b border-border">
                <Skeleton className="h-3.5 w-16 rounded bg-surface-200" />
              </CardHeader>
              <CardContent className="p-4">
                <Skeleton className="h-9 w-full rounded-md bg-surface-200" />
              </CardContent>
            </Card>
          )}

          {/* Taxonomies Panel (Categories, Tags) */}
          {!isAuthor && (
            <Card className="border-border bg-card shadow-xs">
              <CardHeader className="p-4 border-b border-border">
                <Skeleton className="h-3.5 w-24 rounded bg-surface-200" />
              </CardHeader>
              <CardContent className="p-4 space-y-2.5">
                <div className="flex items-center gap-2">
                  <Skeleton className="h-4 w-4 rounded-sm bg-surface-200" />
                  <Skeleton className="h-3.5 w-28 rounded bg-surface-200" />
                </div>
                <div className="flex items-center gap-2">
                  <Skeleton className="h-4 w-4 rounded-sm bg-surface-200" />
                  <Skeleton className="h-3.5 w-20 rounded bg-surface-200" />
                </div>
                <div className="flex items-center gap-2">
                  <Skeleton className="h-4 w-4 rounded-sm bg-surface-200" />
                  <Skeleton className="h-3.5 w-32 rounded bg-surface-200" />
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </PageContainer>
  );
};

/**
 * Structural Skeleton loader for the Visual Page Builder (/admin/design/pages/:id).
 * Directly maps the 3-pane Puck layout: Left blocks palette, center canvas artboard,
 * right inspector properties panel, and top header bar.
 */
export const PageDesignerSkeleton: FC = () => {
  return (
    <div
      role="status"
      aria-label="Loading page builder"
      className="flex h-screen w-full flex-col overflow-hidden bg-background"
      data-testid="page-designer-skeleton"
    >
      <span className="sr-only">Loading page builder…</span>

      {/* Top Header Bar Skeleton */}
      <header className="flex h-12 shrink-0 items-center justify-between border-b border-border bg-card px-4">
        <div className="flex items-center gap-3">
          <Skeleton className="h-8 w-16 rounded-md bg-surface-200" />
          <div className="h-4 w-px bg-border" />
          <Skeleton className="h-4 w-32 rounded bg-surface-200" />
          <Skeleton className="h-5 w-16 rounded-full bg-surface-200" />
        </div>
        <div className="flex items-center gap-2">
          <Skeleton className="h-8 w-20 rounded-md bg-surface-200" />
          <Skeleton className="h-8 w-24 rounded-md bg-surface-200" />
          <Skeleton className="h-8 w-32 rounded-md bg-surface-200" />
        </div>
      </header>

      {/* 3-Pane Visual Builder Workspace Skeleton */}
      <div className="flex flex-1 overflow-hidden">
        {/* Left Sidebar: Blocks & Outline Palette Skeleton */}
        <aside className="w-64 shrink-0 border-r border-border bg-card p-4 space-y-3 hidden md:block">
          <div className="flex gap-2 border-b border-border pb-3">
            <Skeleton className="h-7 w-20 rounded bg-surface-200" />
            <Skeleton className="h-7 w-20 rounded bg-surface-200" />
          </div>
          <div className="space-y-2 pt-1">
            {['Hero', 'Rich text', 'Callout', 'FAQ', 'CTA banner', 'Features grid', 'Image', 'Contact form'].map((_, i) => (
              <div key={i} className="flex items-center justify-between rounded-md border border-border/80 p-2.5 bg-background">
                <div className="flex items-center gap-2">
                  <Skeleton className="h-4 w-4 rounded bg-surface-200" />
                  <Skeleton className="h-3.5 w-24 rounded bg-surface-200" />
                </div>
                <Skeleton className="h-3 w-3 rounded bg-surface-200" />
              </div>
            ))}
          </div>
        </aside>

        {/* Center Canvas: Artboard / Preview Frame Skeleton */}
        <main className="flex-1 overflow-y-auto bg-section p-6 sm:p-10 flex justify-center">
          <div className="w-full max-w-4xl rounded-lg border border-border bg-card p-8 sm:p-12 shadow-sm space-y-8 min-h-[600px]">
            {/* Hero Block Preview Skeleton */}
            <div className="space-y-4 py-4 text-center max-w-xl mx-auto">
              <Skeleton className="h-8 w-3/4 mx-auto rounded bg-surface-200" />
              <Skeleton className="h-4 w-full rounded bg-surface-200" />
              <Skeleton className="h-4 w-5/6 mx-auto rounded bg-surface-200" />
              <div className="flex justify-center gap-3 pt-2">
                <Skeleton className="h-9 w-28 rounded-md bg-surface-200" />
                <Skeleton className="h-9 w-28 rounded-md bg-surface-200" />
              </div>
            </div>

            {/* Content / Features Grid Preview Skeleton */}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3 pt-6 border-t border-border">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="rounded-md border border-border/60 p-4 space-y-3">
                  <Skeleton className="h-8 w-8 rounded bg-surface-200" />
                  <Skeleton className="h-4 w-24 rounded bg-surface-200" />
                  <Skeleton className="h-3 w-full rounded bg-surface-200" />
                  <Skeleton className="h-3 w-4/5 rounded bg-surface-200" />
                </div>
              ))}
            </div>
          </div>
        </main>

        {/* Right Sidebar: Block Inspector / Properties Skeleton */}
        <aside className="w-72 shrink-0 border-l border-border bg-card p-4 space-y-4 hidden lg:block">
          <Skeleton className="h-4 w-28 rounded bg-surface-200" />
          <div className="space-y-3 pt-2">
            <div className="space-y-1.5">
              <Skeleton className="h-3 w-16 rounded bg-surface-200" />
              <Skeleton className="h-8 w-full rounded-md bg-surface-200" />
            </div>
            <div className="space-y-1.5">
              <Skeleton className="h-3 w-20 rounded bg-surface-200" />
              <Skeleton className="h-8 w-full rounded-md bg-surface-200" />
            </div>
            <div className="space-y-1.5">
              <Skeleton className="h-3 w-24 rounded bg-surface-200" />
              <Skeleton className="h-20 w-full rounded-md bg-surface-200" />
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
};
