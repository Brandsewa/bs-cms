import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import {
  TablePageSkeleton,
  FormPageSkeleton,
  MediaGridSkeleton,
  TreePageSkeleton,
  DashboardSkeleton,
} from './page-skeletons';

describe('Page Skeletons', () => {
  it('renders TablePageSkeleton with accessible status role', () => {
    render(<TablePageSkeleton title="Users & Roles" description="Manage accounts" />);
    const status = screen.getByRole('status', { name: 'Loading Users & Roles table' });
    expect(status).not.toBeNull();
    expect(screen.getByText('Users & Roles')).not.toBeNull();
    expect(screen.getByText('Manage accounts')).not.toBeNull();
  });

  it('renders FormPageSkeleton with accessible status role', () => {
    render(<FormPageSkeleton title="Security Settings" description="Password policies" />);
    const status = screen.getByRole('status', { name: 'Loading Security Settings' });
    expect(status).not.toBeNull();
    expect(screen.getByText('Security Settings')).not.toBeNull();
  });

  it('renders MediaGridSkeleton with accessible status role', () => {
    render(<MediaGridSkeleton count={8} />);
    const status = screen.getByRole('status', { name: 'Loading media library' });
    expect(status).not.toBeNull();
  });

  it('renders TreePageSkeleton with accessible status role', () => {
    render(<TreePageSkeleton title="Site Navigation" />);
    const status = screen.getByRole('status', { name: 'Loading Site Navigation' });
    expect(status).not.toBeNull();
    expect(screen.getByText('Site Navigation')).not.toBeNull();
  });

  it('renders DashboardSkeleton with accessible status role', () => {
    render(<DashboardSkeleton />);
    const status = screen.getByRole('status', { name: 'Loading dashboard' });
    expect(status).not.toBeNull();
  });
});
