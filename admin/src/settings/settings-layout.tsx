import type { ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  Breadcrumbs,
  PageContainer,
  PageHeader,
  PageNav,
  type PageNavItem,
} from '@bs/ui';

export type SettingsTabId =
  | 'general'
  | 'email'
  | 'email-log'
  | 'security'
  | 'tokens'
  | 'webhooks'
  | 'publishing'
  | 'ai'
  | 'notifications';

export interface SettingsLayoutProps {
  activeTab: SettingsTabId;
  children: ReactNode;
}

export function SettingsLayout({ activeTab, children }: SettingsLayoutProps) {
  const navigate = useNavigate();

  const navItems: PageNavItem[] = [
    { id: 'general', label: 'General' },
    { id: 'security', label: 'Security' },
    { id: 'tokens', label: 'API Tokens' },
    { id: 'publishing', label: 'Publishing' },
    { id: 'webhooks', label: 'Webhooks' },
    { id: 'email', label: 'Email' },
    { id: 'email-log', label: 'Email Log' },
    { id: 'ai', label: 'AI Connections' },
    { id: 'notifications', label: 'Notifications' },
  ];

  const handleTabChange = (tabId: string) => {
    navigate({ to: `/settings/${tabId}` as any });
  };

  const breadcrumbs = [
    { label: 'Admin', href: '/admin/' },
    { label: 'Settings', href: '/admin/settings/general' },
    {
      label: navItems.find((item) => item.id === activeTab)?.label || 'General',
    },
  ];

  return (
    <PageContainer width="full" className="space-y-6">
      <div className="space-y-1.5">
        <Breadcrumbs items={breadcrumbs} className="text-12" />
        <PageHeader
          title="Workspace Settings"
          description="Configure site metadata, authentication policies, API credentials, webhooks, publishing pipelines, and AI governance."
        />
      </div>

      <PageNav
        aria-label="Settings sections"
        items={navItems}
        value={activeTab}
        onChange={handleTabChange}
      />

      <div className="pt-2 max-w-4xl">{children}</div>
    </PageContainer>
  );
}
