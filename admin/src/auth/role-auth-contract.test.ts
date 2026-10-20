import { describe, expect, it } from 'vitest';
import fixturesData from '../../tests/fixtures/role-auth-fixtures.json';
import {
  checkPermission,
  type AdminSession,
  type AdminUser,
  type MePermissionsResponse,
  type UserRole,
} from './session-context';
import { ADMIN_SIDEBAR_GROUPS } from '../shell/nav-groups';

interface RoleFixtureItem {
  role: string;
  session: {
    status: 'authenticated';
    user: AdminUser;
    csrf_token: string;
  };
  permissions: MePermissionsResponse;
}

const fixtures = fixturesData as unknown as Record<string, RoleFixtureItem>;

describe('Role & Permission Contract Verification against Live Fixtures', () => {
  const roles = ['owner', 'admin', 'editor', 'author', 'contributor', 'viewer'] as const;

  it('contains captured live responses for all 6 target roles', () => {
    for (const role of roles) {
      expect(fixtures).toHaveProperty(role);
      const data = fixtures[role];
      expect(data).toHaveProperty('session');
      expect(data).toHaveProperty('permissions');
      expect(data.session.status).toBe('authenticated');
      expect(data.session.user).toBeDefined();
      expect(Array.isArray(data.session.user.roles)).toBe(true);
    }
  });

  it('validates user.roles is an array of objects matching UserRole schema', () => {
    for (const role of roles) {
      const data = fixtures[role];
      const userRoles = data.session.user.roles as UserRole[];
      expect(userRoles.length).toBeGreaterThan(0);
      const firstRole = userRoles[0];
      expect(firstRole).toHaveProperty('id');
      expect(firstRole).toHaveProperty('handle');
      expect(firstRole).toHaveProperty('name');
      expect(typeof firstRole.handle).toBe('string');
      expect(firstRole.handle).toBe(role);
    }
  });

  it('validates /me/permissions response contract across all roles', () => {
    for (const role of roles) {
      const data = fixtures[role];
      const perms = data.permissions;
      expect(perms).toHaveProperty('actor_id');
      expect(perms).toHaveProperty('role_ids');
      expect(Array.isArray(perms.role_ids)).toBe(true);
      expect(Array.isArray(perms.permissions)).toBe(true);

      for (const rule of perms.permissions) {
        expect(rule).toHaveProperty('id');
        expect(rule).toHaveProperty('subject_type');
        expect(rule).toHaveProperty('action');
        expect(rule).toHaveProperty('effect');
        expect(['allow', 'deny']).toContain(rule.effect);
      }
    }
  });

  it('evaluates checkPermission correctly per role for sidebar items', () => {
    // Owner has access to everything
    const ownerData = fixtures['owner'];
    const ownerRoles = ownerData.session.user.roles as UserRole[];
    const ownerSession: AdminSession = {
      status: 'authenticated',
      user: ownerData.session.user,
      csrf_token: ownerData.session.csrf_token,
      roleHandles: ownerRoles.map((r) => r.handle),
      permissions: ownerData.permissions.permissions,
    };

    expect(checkPermission(ownerSession, 'collection', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'taxonomy', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'media', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'site', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'ai', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'users', 'read')).toBe(true);
    expect(checkPermission(ownerSession, 'settings', 'read')).toBe(true);

    // Editor has content permissions, but not users or developer/settings
    const editorData = fixtures['editor'];
    const editorRoles = editorData.session.user.roles as UserRole[];
    const editorSession: AdminSession = {
      status: 'authenticated',
      user: editorData.session.user,
      csrf_token: editorData.session.csrf_token,
      roleHandles: editorRoles.map((r) => r.handle),
      permissions: editorData.permissions.permissions,
    };
    expect(checkPermission(editorSession, 'collection', 'read')).toBe(true);
    expect(checkPermission(editorSession, 'taxonomy', 'read')).toBe(true);
    expect(checkPermission(editorSession, 'media', 'read')).toBe(true);
    expect(checkPermission(editorSession, 'site', 'read')).toBe(true);
    expect(checkPermission(editorSession, 'users', 'read')).toBe(false);
    expect(checkPermission(editorSession, 'settings', 'read')).toBe(false);

    // Viewer has read-only access to collection, taxonomy, and media only
    const viewerData = fixtures['viewer'];
    const viewerRoles = viewerData.session.user.roles as UserRole[];
    const viewerSession: AdminSession = {
      status: 'authenticated',
      user: viewerData.session.user,
      csrf_token: viewerData.session.csrf_token,
      roleHandles: viewerRoles.map((r) => r.handle),
      permissions: viewerData.permissions.permissions,
    };
    expect(checkPermission(viewerSession, 'collection', 'read')).toBe(true);
    expect(checkPermission(viewerSession, 'taxonomy', 'read')).toBe(true);
    expect(checkPermission(viewerSession, 'media', 'read')).toBe(true);
    expect(checkPermission(viewerSession, 'site', 'read')).toBe(false);
    expect(checkPermission(viewerSession, 'ai', 'read')).toBe(false);
    expect(checkPermission(viewerSession, 'users', 'read')).toBe(false);
    expect(checkPermission(viewerSession, 'settings', 'read')).toBe(false);
  });

  it('verifies all sidebar nav group items map to valid permission checks', () => {
    for (const group of ADMIN_SIDEBAR_GROUPS) {
      for (const item of group.items) {
        expect(item.permission).toBeDefined();
        const [subject, action] = item.permission!.split(':');
        expect(subject).toBeDefined();
        expect(action).toBeDefined();
      }
    }
  });
});
