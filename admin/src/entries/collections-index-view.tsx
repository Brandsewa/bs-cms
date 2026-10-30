import { FC, useEffect, useState } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  PageContainer,
  PageHeader,
  Spinner,
} from '@bs/ui';
import {
  ArrowRight,
  Blocks,
  BookOpen,
  FileText,
  FolderTree,
  Globe,
  Layers,
  Plus,
  User,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';
import { problemToast } from '../lib/problem-toast';

/** Singletons have dedicated screens — deep-link there instead of the generic entry list. */
const SINGLETON_LINKS: Record<string, string> = {
  navigation: '/admin/menus',
  site_settings: '/admin/settings',
};

interface CollectionCard {
  handle: string;
  label_singular: string;
  label_plural: string;
  description?: string | null;
  icon?: string | null;
  is_system: boolean;
  kind?: string | null;
  route_pattern?: string | null;
  total?: number;
}

const ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  'book-open': BookOpen,
  'file-text': FileText,
  'folder-tree': FolderTree,
  user: User,
  globe: Globe,
  'layout-template': Blocks,
  layers: Layers,
};

function CollectionIcon({ icon }: { icon?: string | null }) {
  const Cmp = (icon && ICONS[icon]) || Layers;
  return <Cmp className="h-5 w-5" />;
}

/**
 * Landing page for /admin/entries: one card per content collection
 * (system + custom), linking to each collection's archive.
 */
export const CollectionsIndexView: FC = () => {
  const { session, hasPermission } = useSession();
  const [collections, setCollections] = useState<CollectionCard[]>([]);
  const [loading, setLoading] = useState(true);
  const isOwner = session?.user?.roles?.some(
    (r) => (typeof r === 'string' ? r === 'owner' : r.handle === 'owner'),
  ) ?? false;
  const canCreate = isOwner || hasPermission('schema', 'create');

  useEffect(() => {
    let mounted = true;
    async function load() {
      try {
        const res = await apiClient.request<Record<string, unknown>[]>('GET', '/collections');
        if (!mounted) return;
        if (!res.ok) {
          problemToast(res.problem, { fallbackMessage: 'Failed to load collections.' });
          setLoading(false);
          return;
        }
        if (!Array.isArray(res.data)) {
          problemToast(null, { fallbackMessage: 'Failed to load collections.' });
          setLoading(false);
          return;
        }
        const cards: CollectionCard[] = res.data.map((c) => ({
          handle: String(c.handle || ''),
          label_singular: String(c.label_singular || c.handle || ''),
          label_plural: String(c.label_plural || `${c.handle}s`),
          description: c.description ? String(c.description) : null,
          icon: c.icon ? String(c.icon) : null,
          is_system: Boolean(c.is_system),
          kind: c.kind ? String(c.kind) : null,
          route_pattern: c.route_pattern ? String(c.route_pattern) : null,
        }));
        setCollections(cards);
        setLoading(false);
        // Best-effort entry counts per collection
        for (const card of cards) {
          apiClient.request<Record<string, number>>('GET', `/collections/${card.handle}/counts`).then((r) => {
            if (!mounted || !r.ok || !r.data) return;
            setCollections((prev) =>
              prev.map((c) => (c.handle === card.handle ? { ...c, total: r.data?.all } : c)),
            );
          }).catch(() => undefined);
        }
      } catch {
        if (mounted) {
          problemToast(null, { fallbackMessage: 'Failed to load collections.' });
          setLoading(false);
        }
      }
    }
    load();
    return () => {
      mounted = false;
    };
  }, []);

  const sorted = [...collections].sort((a, b) => {
    if (a.is_system !== b.is_system) return a.is_system ? -1 : 1;
    return a.label_plural.localeCompare(b.label_plural);
  });

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Entries' },
        ]}
      />
      <PageHeader
        title="Collections"
        description="Every content type on your site — pick one to manage its entries."
        actions={
          canCreate ? (
            <Button size="sm" onClick={() => { window.location.href = '/admin/developer/schema'; }} data-testid="new-collection-link">
              <Plus className="mr-1.5 h-3.5 w-3.5" /> New Collection
            </Button>
          ) : undefined
        }
      />

      {loading ? (
        <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
          <Spinner size={28} />
          <p className="text-13 text-muted-foreground">Loading collections...</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {sorted.map((c) => (
            <Card
              key={c.handle}
              className="group transition-colors hover:border-primary/40 cursor-pointer"
              onClick={() => { window.location.href = SINGLETON_LINKS[c.handle] || `/admin/entries/${c.handle}`; }}
              data-testid={`collection-card-${c.handle}`}
            >
              <CardContent className="flex items-start justify-between gap-3 pt-5">
                <div className="flex items-start gap-3 min-w-0">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                    <CollectionIcon icon={c.icon} />
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="text-14 font-semibold text-foreground truncate">{c.label_plural}</p>
                      {c.is_system ? (
                        <Badge variant="outline" className="text-[10px]">System</Badge>
                      ) : (
                        <Badge variant="secondary" className="text-[10px]">Custom</Badge>
                      )}
                    </div>
                    <p className="text-12 text-muted-foreground line-clamp-2 pt-0.5">
                      {c.description || `Archive of ${c.label_plural.toLowerCase()}.`}
                    </p>
                    <p className="text-11 text-muted-foreground pt-1.5">
                      {typeof c.total === 'number' ? `${c.total} ${c.total === 1 ? c.label_singular.toLowerCase() : c.label_plural.toLowerCase()}` : '\u00A0'}
                    </p>
                  </div>
                </div>
                <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </PageContainer>
  );
};
