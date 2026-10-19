import { PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import {
  useCallback,
  useState,
  type ReactNode,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Tooltip } from '../components/tooltip';

export interface SidebarItem {
  id: string;
  label: string;
  /**
   * Required permission key. Items the user has no access to are NOT
   * rendered (docs/02-ui-spec.md 2: hidden, not disabled).
   */
  permission?: string;
  icon?: React.ComponentType<{ className?: string }>;
  /** Small count/status badge (e.g. pending AI changes). */
  badge?: ReactNode;
}

export interface SidebarGroup {
  id: string;
  label?: string;
  items: SidebarItem[];
}

export interface AppSidebarProps {
  groups: SidebarGroup[];
  /** Returns true when the user may see an item carrying `permission`. */
  hasPermission?: (permission: string) => boolean;
  /** Active item id (rendered with aria-current="page"). */
  activeId?: string;
  /**
   * Renders the item as a link — screens inject their router Link here.
   * Default renders a plain anchor.
   */
  renderLink?: (item: SidebarItem, content: ReactNode, isActive: boolean) => ReactNode;
  collapsed?: boolean;
  onCollapsedChange?: (collapsed: boolean) => void;
  /** localStorage key for the collapsed state (default bs-sidebar-collapsed). */
  storageKey?: string;
  className?: string;
}

function readStoredCollapsed(key: string): boolean {
  try {
    return window.localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

/**
 * App sidebar (docs/02-ui-spec.md 2): collapsible to icons (remembers
 * state), grouped, permission-filtered — items without permission are not
 * rendered. Screens provide `renderLink` to wire their router.
 */
export function AppSidebar({
  groups,
  hasPermission,
  activeId,
  renderLink,
  collapsed: controlledCollapsed,
  onCollapsedChange,
  storageKey = 'bs-sidebar-collapsed',
  className,
}: AppSidebarProps) {
  const { t } = useI18n();
  const [uncontrolledCollapsed, setUncontrolledCollapsed] = useState(() =>
    readStoredCollapsed(storageKey)
  );
  const collapsed = controlledCollapsed ?? uncontrolledCollapsed;

  const setCollapsed = useCallback(
    (next: boolean) => {
      if (controlledCollapsed === undefined) {
        setUncontrolledCollapsed(next);
        try {
          window.localStorage.setItem(storageKey, next ? '1' : '0');
        } catch {
          // storage unavailable; keep in-memory state
        }
      }
      onCollapsedChange?.(next);
    },
    [controlledCollapsed, onCollapsedChange, storageKey]
  );

  const visibleGroups = groups
    .map((group) => ({
      ...group,
      items: group.items.filter(
        (item) => !item.permission || hasPermission?.(item.permission) !== false
      ),
    }))
    .filter((group) => group.items.length > 0);

  const renderItem = (item: SidebarItem) => {
    const isActive = item.id === activeId;
    const content = (
      <>
        {item.icon ? (
          <item.icon
            className={cn(
              'h-4 w-4 shrink-0',
              collapsed && 'mx-auto',
              isActive ? 'text-primary' : 'text-muted-foreground'
            )}
          />
        ) : null}
        {!collapsed ? <span className="truncate">{item.label}</span> : null}
        {!collapsed && item.badge != null ? <span className="ml-auto">{item.badge}</span> : null}
      </>
    );
    const className = cn(
      'flex items-center gap-2 rounded-md px-2 py-1.5 text-13 text-foreground transition-colors',
      'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      isActive && 'bg-accent font-medium',
      collapsed && 'justify-center px-0'
    );
    const linkNode =
      renderLink !== undefined ? (
        renderLink(item, content, isActive)
      ) : (
        <a
          href={`#${item.id}`}
          className={className}
          aria-current={isActive ? 'page' : undefined}
          aria-label={collapsed ? item.label : undefined}
        >
          {content}
        </a>
      );
    // Collapsed mode: the accessible name comes from a right-side tooltip;
    // expanded mode renders the plain link inside the list item.
    if (collapsed) {
      // The Tooltip wrapper renders its own trigger + content; nesting
      // explicit Trigger/Content inside it double-slots and throws.
      return (
        <li key={item.id}>
          <Tooltip content={item.label} delayDuration={200} side="right">
            {linkNode}
          </Tooltip>
        </li>
      );
    }
    return <li key={item.id}>{linkNode}</li>;
  };

  return (
    <nav
      aria-label={t('sidebar.groups')}
      className={cn(
        'flex h-full flex-col gap-4 overflow-y-auto border-r border-border bg-card py-3 transition-[width] duration-[var(--duration-base)]',
        collapsed ? 'w-14 px-1.5' : 'w-60 px-3',
        className
      )}
    >
      <div className={cn('flex items-center gap-2', collapsed && 'justify-center')}>
        <span
          className={cn(
            'flex h-7 w-7 items-center justify-center rounded-md bg-primary text-xs font-bold text-primary-foreground'
          )}
          aria-hidden="true"
        >
          BS
        </span>
        {!collapsed ? <span className="text-sm font-semibold text-foreground">BS CMS</span> : null}
      </div>

      {visibleGroups.map((group) => (
        <div key={group.id}>
          {group.label && !collapsed ? (
            <p className="mb-1 px-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {group.label}
            </p>
          ) : null}
          {group.label && collapsed ? <div className="mx-2 my-2 border-t border-border" /> : null}
          <ul className="space-y-0.5">{group.items.map(renderItem)}</ul>
        </div>
      ))}

      <div className="mt-auto pt-2">
        <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          aria-label={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}
          aria-pressed={collapsed}
          className={cn(
            'flex h-8 w-full items-center gap-2 rounded-md px-2 text-13 text-muted-foreground transition-colors',
            'hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            collapsed && 'justify-center px-0'
          )}
        >
          {collapsed ? (
            <PanelLeftOpen className="h-4 w-4" aria-hidden="true" />
          ) : (
            <>
              <PanelLeftClose className="h-4 w-4" aria-hidden="true" />
              {t('sidebar.collapse')}
            </>
          )}
        </button>
      </div>
    </nav>
  );
}
