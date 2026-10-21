import type { ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  Breadcrumbs,
  PageContainer,
  PageHeader,
  PageNav,
  type PageNavItem,
} from '@bs/ui';

export interface SettingsLayoutProps {
  activeTab: 'general' | 'email';
  children: ReactNode;
}

export function SettingsLayout({ activeTab, children }: SettingsLayoutProps) {
  const navigate = useNavigate();

  const navItems: PageNavItem[] = [
    {
      id: 'general',
      label: 'General',
    },
    {
      id: 'email',
      label: 'Email',
    },
  ];

  const handleTabChange = (tabId: string) => {
    if (tabId === 'general') {
      navigate({ to: '/settings/general' });
    } else if (tabId === 'email') {
      navigate({ to: '/settings/email' });
    }
  };

  const breadcrumbs = [
    { label: 'Dashboard', href: '/admin/' },
    { label: 'Settings', href: '/admin/settings/general' },
    {
      label: activeTab === 'general' ? 'General' : 'Email',
    },
  ];

  return (
    <PageContainer width="small" className="space-y-6">
      <Breadcrumbs items={breadcrumbs} />

      <PageHeader
        title="Settings"
        description="Configure site metadata, environment, delivery providers, and preferences."
      />

      <PageNav
        aria-label="Settings sections"
        items={navItems}
        value={activeTab}
        onChange={handleTabChange}
      />

      <div className="pt-2">{children}</div>
    </PageContainer>
  );
}
