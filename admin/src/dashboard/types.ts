import type { DashboardResponse } from '@bs/client';

export type DashboardData = DashboardResponse;

export const MOCK_DASHBOARD_DATA: DashboardData = {
  greeting: {
    user_name: 'Administrator',
    environment: 'production',
  },
  counts: {
    drafts: 3,
    scheduled: 1,
    pending_ai_changes: 2,
  },
  recent_edits: [
    {
      id: 'entry_01',
      collection: 'posts',
      title: 'Welcome to Brand Sewa CMS v1',
      status: 'published',
      updated_at: '2026-10-20T08:30:00Z',
      updated_by: 'Anna Admin',
    },
    {
      id: 'entry_02',
      collection: 'posts',
      title: 'Guide to Static Site Generation with Astro',
      status: 'draft',
      updated_at: '2026-10-19T14:15:00Z',
      updated_by: 'Anna Admin',
    },
    {
      id: 'entry_03',
      collection: 'pages',
      title: 'About Our Team and Mission',
      status: 'in_review',
      updated_at: '2026-10-18T11:00:00Z',
      updated_by: 'Bob Contributor',
    },
  ],
  drafts_waiting: [
    {
      id: 'entry_02',
      collection: 'posts',
      title: 'Guide to Static Site Generation with Astro',
      status: 'draft',
      updated_at: '2026-10-19T14:15:00Z',
    },
    {
      id: 'entry_04',
      collection: 'posts',
      title: 'Q4 Product Roadmap & Milestones',
      status: 'draft',
      updated_at: '2026-10-17T09:40:00Z',
    },
  ],
  scheduled_items: [
    {
      id: 'entry_05',
      collection: 'posts',
      title: 'Scheduled Feature Launch Announcement',
      scheduled_at: '2026-10-25T10:00:00Z',
    },
  ],
  pending_ai_changes: [
    {
      id: 'pc_01',
      actor_label: 'Claude (via Anna)',
      action: 'publish_entry',
      target_collection: 'posts',
      target_id: 'entry_01',
      created_at: '2026-10-20T07:15:00Z',
    },
    {
      id: 'pc_02',
      actor_label: 'Cursor (via Dave)',
      action: 'create_term',
      target_collection: 'categories',
      created_at: '2026-10-20T06:45:00Z',
    },
  ],
  site_health: {
    status: 'healthy',
    total_issues: 1,
    errors: 0,
    warnings: 1,
    scanned_at: '2026-10-20T06:00:00Z',
    top_issues: [
      {
        rule_id: 'seo_missing_meta_desc',
        message: '1 published entry is missing a meta description',
        severity: 'warn',
      },
    ],
  },
  recent_activity: [
    {
      id: 'act_01',
      action: 'entry.published',
      actor: 'Anna Admin',
      target: 'Welcome to Brand Sewa CMS v1',
      created_at: '2026-10-20T08:30:00Z',
    },
    {
      id: 'act_02',
      action: 'oauth.granted',
      actor: 'Claude (MCP)',
      target: 'Read-write access granted',
      created_at: '2026-10-20T07:00:00Z',
    },
    {
      id: 'act_03',
      action: 'media.uploaded',
      actor: 'Anna Admin',
      target: 'hero-banner-autumn.webp',
      created_at: '2026-10-19T16:20:00Z',
    },
  ],
  system_status: {
    cron: 'healthy',
    mail: 'healthy',
    disk_usage_percent: 28,
    php_version: '8.3.12',
  },
  onboarding: {
    completed: false,
    dismissed: false,
    steps: {
      create_page: true,
      write_post: true,
      site_details: true,
      configure_email: false,
      connect_astro: false,
      connect_ai: false,
    },
  },
};

export const EMPTY_DASHBOARD_DATA: DashboardData = {
  greeting: {
    user_name: '',
    environment: 'production',
  },
  counts: {
    drafts: 0,
    scheduled: 0,
    pending_ai_changes: 0,
  },
  recent_edits: [],
  drafts_waiting: [],
  scheduled_items: [],
  pending_ai_changes: [],
  site_health: {
    status: 'healthy',
    total_issues: 0,
    errors: 0,
    warnings: 0,
  },
  recent_activity: [],
  system_status: {
    cron: 'healthy',
    mail: 'healthy',
    disk_usage_percent: 0,
    php_version: '',
  },
  onboarding: {
    completed: true,
    steps: {
      create_page: true,
      write_post: true,
      site_details: true,
      configure_email: true,
      connect_astro: true,
      connect_ai: true,
    },
  },
};
