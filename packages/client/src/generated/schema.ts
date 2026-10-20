/**
 * Generated from docs/api/openapi-v1.json — DO NOT EDIT BY HAND.
 * Regenerate: npm run generate-client -w @bs/client
 *
 * Contract: frozen api-v1 (additive changes only).
 */

export type User = {
  "id": string;
  "email": string;
  "name": string;
  "status": "active" | "suspended" | "locked" | "invited";
  "avatar_media_id"?: string | null;
  "locale"?: string;
  "timezone"?: string;
  "two_factor_enabled"?: boolean;
  "roles": Array<string>;
  "created_at": string;
  "updated_at"?: string | null;
  "last_login_at"?: string | null;
};

export type AuthSuccessResponse = {
  "status": "success";
  "user": User;
  "csrf_token": string;
};

export type Auth2faChallengeRequiredResponse = {
  "status": "requires_2fa";
  "user_id": string;
  "challenge_token": string;
};

export type SessionResponse = {
  "status": "authenticated";
  "user": User;
  "csrf_token": string;
};

export type UserPreferences = {
  "theme"?: "light" | "dark" | "system";
  "density"?: "compact" | "comfortable" | "spacious";
  "locale"?: string;
};

export type UserListResponse = {
  "items": Array<User>;
  "next_cursor"?: string | null;
};

export type Role = {
  "id": string;
  "handle": string;
  "name": string;
  "description"?: string | null;
  "is_system"?: boolean;
  "is_ai"?: boolean;
  "permissions": Array<{
      "action": string;
      "subject": string;
      "fields"?: Array<string> | null;
      "conditions"?: Record<string, unknown> | null;
      "inverted"?: boolean;
    }>;
};

export type RoleListResponse = {
  "items": Array<Role>;
};

export type ApiToken = {
  "id": string;
  "name": string;
  "role_id"?: string | null;
  "scopes": Array<string>;
  "ai_mode": "read_only" | "draft_only" | "full_access";
  "expires_at"?: string | null;
  "last_used_at"?: string | null;
  "created_at": string;
};

export type TokenCreatedResponse = {
  "id": string;
  "name": string;
  "plain_text_token": string;
  "role_id"?: string | null;
  "scopes": Array<string>;
  "ai_mode": string;
  "expires_at"?: string | null;
  "created_at": string;
};

export type TokenListResponse = {
  "items": Array<ApiToken>;
};

export type AuditEntry = {
  "id": number;
  "request_id": string;
  "actor_type": string;
  "actor_id": string;
  "actor_label"?: string | null;
  "on_behalf_of"?: string | null;
  "action": string;
  "subject_type": string;
  "subject_id": string;
  "collection_key"?: string | null;
  "summary": string;
  "diff"?: Record<string, unknown> | null;
  "ip"?: string | null;
  "user_agent"?: string | null;
  "result": string;
  "created_at": string;
};

export type AuditListResponse = {
  "items": Array<AuditEntry>;
  "next_cursor"?: number | null;
};

export type StatusResponse = {
  "status": string;
};

export type ProblemDetails = {
  "type": string;
  "title": string;
  "status": number;
  "detail": string;
  "instance"?: string;
  "invalid_params"?: Array<{
      "name"?: string;
      "reason"?: string;
    }>;
};

export type Taxonomy = {
  "id": string;
  "handle": string;
  "name": string;
  "description"?: string | null;
  "is_hierarchical"?: boolean;
  "allow_custom_meta"?: boolean;
  "default_term_id"?: string | null;
  "created_at"?: string;
  "updated_at"?: string;
};

export type Term = {
  "id": string;
  "taxonomy_id": string;
  "parent_id"?: string | null;
  "name": string;
  "slug": string;
  "description"?: string | null;
  "path": string;
  "depth": number;
  "position": number;
  "entry_count": number;
  "is_locked"?: boolean;
  "meta"?: Record<string, unknown> | null;
  "children"?: Array<Term>;
  "created_at"?: string;
  "updated_at"?: string;
};

export type MediaFolder = {
  "id": string;
  "parent_id"?: string | null;
  "name": string;
  "path": string;
  "created_at": string;
};

export type Media = {
  "id": string;
  "folder_id"?: string | null;
  "filename": string;
  "original_name": string;
  "mime": string;
  "extension": string;
  "size_bytes": number;
  "width"?: number | null;
  "height"?: number | null;
  "duration_seconds"?: number | null;
  "content_hash"?: string;
  "storage_driver"?: string;
  "storage_path": string;
  "url": string;
  "alt_text"?: string | null;
  "caption"?: string | null;
  "title"?: string | null;
  "focal_x"?: number | null;
  "focal_y"?: number | null;
  "blurhash"?: string | null;
  "dominant_color"?: string | null;
  "usage_count"?: number;
  "uploaded_by"?: string | null;
  "variants"?: Array<{
      "variant_name"?: string;
      "width"?: number;
      "height"?: number;
      "format"?: string;
      "size_bytes"?: number;
      "storage_path"?: string;
      "url"?: string;
    }>;
  "created_at"?: string;
  "updated_at"?: string;
  "deleted_at"?: string | null;
  "duplicate_of"?: string | null;
};

export type Redirect = {
  "id": string;
  "from_path": string;
  "match_type": "exact" | "prefix";
  "to_path": string;
  "status_code": 301 | 302 | 307 | 308;
  "is_auto": boolean;
  "hits": number;
  "last_hit_at"?: string | null;
  "created_by"?: string | null;
  "created_at": string;
};

export type SeoResolved = {
  "title": string;
  "description": string;
  "canonical": string;
  "robots": {
    "noindex": boolean;
    "nofollow": boolean;
    "noarchive": boolean;
    "max_snippet": number;
    "max_image_preview": string;
    "max_video_preview": number;
  };
  "open_graph": {
    "title": string;
    "description": string;
    "type": string;
    "url": string;
    "image": string | null;
    "site_name": string;
  };
  "twitter": {
    "card": string;
    "title": string;
    "description": string;
    "image": string | null;
  };
};

export type SiteHealthReport = {
  "summary": {
    "total": number;
    "errors": number;
    "warnings": number;
    "info": number;
    "status": "healthy" | "warning" | "critical";
  };
  "issues": Array<{
      "rule_id": string;
      "severity": "error" | "warn" | "info";
      "category": string;
      "message": string;
      "entity_type": string;
      "entity_id": string;
      "entity_title": string | null;
      "fix_hint": string;
    }>;
  "rules_checked": number;
  "scanned_at": string;
};

export type DashboardResponse = {
  "greeting": {
    "user_name": string;
    "environment": string;
  };
  "counts": {
    "drafts": number;
    "scheduled": number;
    "pending_ai_changes": number;
  };
  "recent_edits": Array<{
      "id": string;
      "collection": string;
      "title": string;
      "status": string;
      "updated_at": string;
      "updated_by"?: string | null;
    }>;
  "drafts_waiting": Array<{
      "id": string;
      "collection": string;
      "title": string;
      "status": string;
      "updated_at": string;
    }>;
  "scheduled_items": Array<{
      "id": string;
      "collection": string;
      "title": string;
      "scheduled_at": string;
    }>;
  "pending_ai_changes": Array<{
      "id": string;
      "actor_label": string;
      "action": string;
      "target_collection"?: string | null;
      "target_id"?: string | null;
      "created_at": string;
    }>;
  "site_health": {
    "status": "healthy" | "warning" | "critical";
    "total_issues": number;
    "errors": number;
    "warnings": number;
    "scanned_at"?: string;
    "top_issues"?: Array<{
        "rule_id": string;
        "message": string;
        "severity": "error" | "warn" | "info";
      }>;
  };
  "recent_activity"?: Array<{
      "id": string;
      "action": string;
      "actor": string;
      "target": string;
      "created_at": string;
    }>;
  "system_status"?: {
    "cron": "healthy" | "warning" | "error" | "disabled";
    "mail": "healthy" | "warning" | "error" | "unconfigured";
    "disk_usage_percent": number;
    "php_version": string;
  };
  "onboarding": {
    "completed": boolean;
    "dismissed"?: boolean;
    "steps": {
      "create_page": boolean;
      "write_post": boolean;
      "site_details": boolean;
      "configure_email": boolean;
      "connect_astro": boolean;
      "connect_ai": boolean;
    };
  };
};

export type AuthorsEntry = {
  "title"?: string;
  "slug"?: string;
  "status"?: "draft" | "in_review" | "scheduled" | "published" | "archived";
  "name"?: string;
  "bio"?: string;
  "job_title"?: string;
  "same_as"?: Array<Record<string, unknown>>;
  "user"?: string;
  "avatar"?: unknown;
};

export type NavigationEntry = {
  "title"?: string;
  "slug"?: string;
  "status"?: "draft" | "in_review" | "scheduled" | "published" | "archived";
  "items"?: Array<Record<string, unknown>>;
};

export type PagesEntry = {
  "title"?: string;
  "slug"?: string;
  "status"?: "draft" | "in_review" | "scheduled" | "published" | "archived";
  "template"?: "default" | "landing" | "full_width";
  "summary"?: string;
  "show_in_nav"?: boolean;
  "nav_label"?: string;
  "blocks"?: unknown;
  "featured_image"?: unknown;
};

export type PostsEntry = {
  "title"?: string;
  "slug"?: string;
  "status"?: "draft" | "in_review" | "scheduled" | "published" | "archived";
  "body"?: unknown;
  "featured_image"?: unknown;
  "categories"?: unknown;
  "tags"?: unknown;
  "excerpt"?: string;
  "summary"?: string;
  "authors"?: Array<string>;
  "related_posts"?: Array<string>;
  "faq"?: Array<{
      "question": string;
      "answer": string;
    }>;
  "key_facts"?: Array<Record<string, unknown>>;
  "reviewed_by"?: string;
  "last_reviewed_at"?: string;
  "reading_time"?: number;
  "is_featured"?: boolean;
};

export type SiteSettingsEntry = {
  "title"?: string;
  "slug"?: string;
  "status"?: "draft" | "in_review" | "scheduled" | "published" | "archived";
  "site_name"?: string;
  "tagline"?: string;
  "default_language"?: "en";
  "canonical_base_url"?: string;
  "environment"?: "production" | "staging";
  "social_links"?: Array<Record<string, unknown>>;
  "contact_details"?: Record<string, unknown>;
  "organization_details"?: Record<string, unknown>;
};

