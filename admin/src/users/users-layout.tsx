import type { ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  Breadcrumbs,
  PageContainer,
  PageHeader,
  PageNav,
  type PageNavItem,
} from '@bs/ui';

export interface UsersLayoutProps {
  activeTab: 'users' | 'roles';
  children: ReactNode;
}

export function UsersLayout({ activeTab, children }: UsersLayoutProps) {
  const navigate = useNavigate();

  const navItems: PageNavItem[] = [
    {
      id: 'users',
      label: 'Users',
    },
    {
      id: 'roles',
      label: 'Roles & Permissions',
    },
  ];

  const handleTabChange = (tabId: string) => {
    if (tabId === 'users') {
      navigate({ to: '/users' });
    } else if (tabId === 'roles') {
      navigate({ to: '/users/roles' });
    }
  };

  const breadcrumbs = [
    { label: 'Admin', href: '/admin/' },
    { label: 'Users', href: '/admin/users' },
    {
      label: activeTab === 'users' ? 'Team Members' : 'Roles & Permissions',
    },
  ];

  return (
    <PageContainer width="full" className="space-y-6">
      <div className="space-y-1.5">
        <Breadcrumbs items={breadcrumbs} className="text-12" />
        <PageHeader
          title="Users & Roles"
          description="Manage team members, access invitations, system role assignments, and permission boundaries."
        />
      </div>

      <PageNav
        aria-label="User management sections"
        items={navItems}
        value={activeTab}
        onChange={handleTabChange}
      />

      <div className="pt-2">{children}</div>
    </PageContainer>
  );
}
