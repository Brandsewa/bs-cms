import type { SidebarGroup, SidebarItem } from '@bs/ui';
import {
  Activity,
  ArrowLeftRight,
  Code,
  FileText,
  FolderTree,
  Globe,
  Image,
  ScrollText,
  Settings,
  Sparkles,
  Users,
} from 'lucide-react';

export interface AdminSidebarItem extends SidebarItem {
  to: string;
}

export interface AdminSidebarGroup extends SidebarGroup {
  items: AdminSidebarItem[];
}

/**
 * Sidebar navigation groups aligned with backend subject permission models:
 * - entries: subject 'collection'
 * - taxonomies: subject 'taxonomy'
 * - media: subject 'media'
 * - navigation: subject 'site'
 * - ai: subject 'ai'
 * - users: subject 'users'
 * - developer: subject 'settings' (or 'schema')
 * - settings: subject 'settings'
 */
export const ADMIN_SIDEBAR_GROUPS: AdminSidebarGroup[] = [
  {
    id: 'content',
    label: 'Content',
    items: [
      {
        id: 'entries',
        label: 'Entries',
        permission: 'collection:read',
        icon: FileText,
        to: '/admin/entries',
      },
      {
        id: 'taxonomies',
        label: 'Taxonomies',
        permission: 'taxonomy:read',
        icon: FolderTree,
        to: '/admin/taxonomies',
      },
      {
        id: 'media',
        label: 'Media',
        permission: 'media:read',
        icon: Image,
        to: '/admin/media',
      },
    ],
  },
  {
    id: 'site',
    label: 'Site',
    items: [
      {
        id: 'navigation',
        label: 'Navigation',
        permission: 'site:read',
        icon: Globe,
        to: '/admin/menus',
      },
      // SPRINT-A BEGIN
      {
        id: 'redirects',
        label: 'Redirects',
        permission: 'site:read',
        icon: ArrowLeftRight,
        to: '/admin/redirects',
      },
      // SPRINT-A END
      {
        id: 'ai',
        label: 'AI Studio',
        permission: 'ai:read',
        icon: Sparkles,
        to: '/admin/ai',
      },
    ],
  },
  {
    id: 'system',
    label: 'System',
    items: [
      {
        id: 'users',
        label: 'Users',
        permission: 'users:read',
        icon: Users,
        to: '/admin/users',
      },
      {
        id: 'audit',
        label: 'Audit Log',
        permission: 'settings:read',
        icon: ScrollText,
        to: '/admin/system/audit',
      },
      {
        id: 'health',
        label: 'Site Health',
        permission: 'settings:read',
        icon: Activity,
        to: '/admin/system/health',
      },
      {
        id: 'developer',
        label: 'Developer',
        permission: 'settings:read',
        icon: Code,
        to: '/admin/developer',
      },
      {
        id: 'settings',
        label: 'Settings',
        permission: 'settings:read',
        icon: Settings,
        to: '/admin/settings',
      },
    ],
  },
];
