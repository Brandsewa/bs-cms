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
   * Renders the item as a link â€” screens inject their router Link here.
   * Default renders a plain anchor.
   */
  renderLink?: (item: SidebarItem, content: ReactNode, isActive: boolean, className?: string) => ReactNode;
  collapsed?: boolean;
  onCollapsedChange?: (collapsed: boolean) => void;
  /** localStorage key for the collapsed state (default bs-sidebar-collapsed). */
  storageKey?: string;
  className?: string;
  /** Optional top slot for site switcher / branding */
  header?: ReactNode;
  /** Optional bottom slot for user profile / status */
  footer?: ReactNode;
  /** Whether to show the collapse toggle button (default true). Set to false on mobile drawers. */
  showCollapseButton?: boolean;
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
 * state), grouped, permission-filtered â€” items without permission are not
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
  header,
  footer,
  showCollapseButton = true,
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
          <span className="flex h-5 w-5 shrink-0 items-center justify-center">
            <item.icon
              className={cn(
                'h-4 w-4 shrink-0 transition-colors',
                isActive ? 'text-primary' : 'text-foreground-lighter'
              )}
            />
          </span>
        ) : null}
        {!collapsed ? <span className="truncate">{item.label}</span> : null}
        {!collapsed && item.badge != null ? <span className="ml-auto">{item.badge}</span> : null}
      </>
    );
    const itemClassName = cn(
      'group flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-13 text-foreground-light transition-colors',
      'hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      isActive && 'bg-surface-200 text-foreground font-medium',
      collapsed && 'h-9 w-9 justify-center px-0 mx-auto'
    );
    const linkNode =
      renderLink !== undefined ? (
        renderLink(item, content, isActive, itemClassName)
      ) : (
        <a
          href={`#${item.id}`}
          className={itemClassName}
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
        <li key={item.id} className="flex justify-center">
          <Tooltip content={item.label} delayDuration={150} side="right">
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
        'flex h-full flex-col gap-3 overflow-y-auto border-r border-border bg-background py-3 transition-[width] duration-[var(--duration-base)]',
        collapsed ? 'w-14 px-2' : 'w-56 px-3',
        className
      )}
    >
      {header ? (
        header
      ) : (
        <div className={cn('flex items-center gap-2.5 px-1 py-1', collapsed && 'justify-center px-0')}>
          <span
            className={cn(
              'flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-bold text-primary-foreground shadow-xs'
            )}
            aria-hidden="true"
          >
            BS
          </span>
          {!collapsed ? (
            <div className="flex flex-col min-w-0">
              <span className="text-13 font-semibold leading-tight text-foreground truncate">BS CMS</span>
              <span className="text-[11px] text-foreground-muted leading-tight truncate">Studio</span>
            </div>
          ) : null}
        </div>
      )}

      {visibleGroups.map((group) => (
        <div key={group.id} className="space-y-1">
          {group.label && !collapsed ? (
            <p className="px-2.5 text-[11px] font-medium tracking-wider uppercase text-foreground-muted">
              {group.label}
            </p>
          ) : null}
          {group.label && collapsed ? <div className="mx-1 my-1.5 border-t border-border" /> : null}
          <ul className="space-y-0.5">{group.items.map(renderItem)}</ul>
        </div>
      ))}

      <div className="mt-auto space-y-2 pt-2">
        {footer ? footer : null}
        {showCollapseButton ? (
          <>
            <button
          type="button"
          onClick={() => setCollapsed(!collapsed)}
          aria-label={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}
          aria-expanded={!collapsed}
          aria-pressed={collapsed}
          className={cn(
            'flex h-8 w-full items-center gap-2.5 rounded-md px-2 text-13 text-foreground-muted transition-colors',
            'hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            collapsed && 'h-8 w-8 justify-center px-0 mx-auto'
          )}
        >
          {collapsed ? (
            <PanelLeftOpen className="h-4 w-4 shrink-0" aria-hidden="true" />
          ) : (
            <>
              <PanelLeftClose className="h-4 w-4 shrink-0" aria-hidden="true" />
              <span>{t('sidebar.collapse')}</span>
            </>
          )}
        </button>
        <span className="sr-only" aria-live="polite">
          {collapsed ? 'Sidebar collapsed' : 'Sidebar expanded'}
        </span>
          </>
        ) : null}
      </div>
    </nav>
  );
}
