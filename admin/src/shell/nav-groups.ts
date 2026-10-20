import type { SidebarGroup, SidebarItem } from '@bs/ui';
import {
  Code,
  FileText,
  FolderTree,
  Globe,
  Image,
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

export const ADMIN_SIDEBAR_GROUPS: AdminSidebarGroup[] = [
  {
    id: 'content',
    label: 'Content',
    items: [
      {
        id: 'entries',
        label: 'Entries',
        permission: 'entries.read',
        icon: FileText,
        to: '/admin/entries',
      },
      {
        id: 'taxonomies',
        label: 'Taxonomies',
        permission: 'taxonomies.read',
        icon: FolderTree,
        to: '/admin/taxonomies',
      },
      {
        id: 'media',
        label: 'Media',
        permission: 'media.read',
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
        permission: 'menus.manage',
        icon: Globe,
        to: '/admin/menus',
      },
      {
        id: 'ai',
        label: 'AI Studio',
        permission: 'ai.manage',
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
        permission: 'users.manage',
        icon: Users,
        to: '/admin/users',
      },
      {
        id: 'developer',
        label: 'Developer',
        permission: 'settings.manage',
        icon: Code,
        to: '/admin/developer',
      },
      {
        id: 'settings',
        label: 'Settings',
        permission: 'settings.manage',
        icon: Settings,
        to: '/admin/settings',
      },
    ],
  },
];
