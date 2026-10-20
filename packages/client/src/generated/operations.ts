/**
 * Generated operation types from docs/api/openapi-v1.json — DO NOT EDIT BY HAND.
 * Regenerate: npm run generate-client -w @bs/client
 *
 * Each key is "<METHOD> <pathTemplate>". Values describe the typed request
 * and success response. Use with ApiClient.request<...>(). Schema types are
 * namespaced under S (imported from ./schema).
 */

import type * as S from './schema';

/** Log in with credentials */
export interface Op_postAuthLogin {
  method: 'POST';
  path: "/auth/login";
  params: Record<string, string>;
  body: {
  "email": string;
  "password": string;
};
  bodyKind: 'json';
  response: S.AuthSuccessResponse
| S.Auth2faChallengeRequiredResponse;
}

/** Log out current session */
export interface Op_postAuthLogout {
  method: 'POST';
  path: "/auth/logout";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Get current authenticated session information */
export interface Op_getAuthSession {
  method: 'GET';
  path: "/auth/session";
  params: Record<string, string>;
  response: S.SessionResponse;
}

/** Complete 2FA challenge using TOTP or recovery code */
export interface Op_postAuth2faChallenge {
  method: 'POST';
  path: "/auth/2fa/challenge";
  params: Record<string, string>;
  body: {
  "challenge_token": string;
  "code": string;
};
  bodyKind: 'json';
  response: S.AuthSuccessResponse;
}

/** Get current authenticated user profile */
export interface Op_getMe {
  method: 'GET';
  path: "/me";
  params: Record<string, string>;
  response: S.User;
}

/** Update user profile */
export interface Op_putMe {
  method: 'PUT';
  path: "/me";
  params: Record<string, string>;
  response: S.User;
}

/** Get current authenticated user UI preferences */
export interface Op_getMePreferences {
  method: 'GET';
  path: "/me/preferences";
  params: Record<string, string>;
  response: S.UserPreferences;
}

/** Update current authenticated user UI preferences */
export interface Op_patchMePreferences {
  method: 'PATCH';
  path: "/me/preferences";
  params: Record<string, string>;
  body: S.UserPreferences;
  bodyKind: 'json';
  response: S.UserPreferences;
}

/** List all users */
export interface Op_getUsers {
  method: 'GET';
  path: "/users";
  params: Record<string, string>;
  response: S.UserListResponse;
}

/** List all roles */
export interface Op_getRoles {
  method: 'GET';
  path: "/roles";
  params: Record<string, string>;
  response: S.RoleListResponse;
}

/** List active API tokens */
export interface Op_getTokens {
  method: 'GET';
  path: "/tokens";
  params: Record<string, string>;
  response: S.TokenListResponse;
}

/** Create a new API token */
export interface Op_postTokens {
  method: 'POST';
  path: "/tokens";
  params: Record<string, string>;
  response: S.TokenCreatedResponse;
}

/** Revoke API token */
export interface Op_deleteTokensById {
  method: 'DELETE';
  path: "/tokens/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** List immutable audit log entries */
export interface Op_getAudit {
  method: 'GET';
  path: "/audit";
  params: Record<string, string>;
  response: S.AuditListResponse;
}

/** Get single audit log entry */
export interface Op_getAuditById {
  method: 'GET';
  path: "/audit/{id}";
  params: Record<string, string>;
  response: S.AuditEntry;
}

/** List registered collections */
export interface Op_getCollections {
  method: 'GET';
  path: "/collections";
  params: Record<string, string>;
  response: Array<Record<string, unknown>>;
}

/** Create a new collection schema */
export interface Op_postCollections {
  method: 'POST';
  path: "/collections";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Get collection schema definition */
export interface Op_getCollectionsByHandle {
  method: 'GET';
  path: "/collections/{handle}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Alter collection schema definition */
export interface Op_putCollectionsByHandle {
  method: 'PUT';
  path: "/collections/{handle}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Delete collection (drop table and remove from registry) */
export interface Op_deleteCollectionsByHandle {
  method: 'DELETE';
  path: "/collections/{handle}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Plan collection changes and produce migration proposal */
export interface Op_postSchemaPlan {
  method: 'POST';
  path: "/schema/plan";
  params: Record<string, string>;
  response: {
  "change_id": string;
  "destructive"?: boolean;
  "operations"?: Array<Record<string, unknown>>;
};
}

/** Apply proposed schema change */
export interface Op_postSchemaApplyByChangeId {
  method: 'POST';
  path: "/schema/apply/{change_id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** List schema change history */
export interface Op_getSchemaChanges {
  method: 'GET';
  path: "/schema/changes";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** List saved views for current user */
export interface Op_getSavedViews {
  method: 'GET';
  path: "/saved-views";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** Create a saved view */
export interface Op_postSavedViews {
  method: 'POST';
  path: "/saved-views";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Get saved view details */
export interface Op_getSavedViewsById {
  method: 'GET';
  path: "/saved-views/{id}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Update saved view */
export interface Op_putSavedViewsById {
  method: 'PUT';
  path: "/saved-views/{id}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Delete saved view */
export interface Op_deleteSavedViewsById {
  method: 'DELETE';
  path: "/saved-views/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Check background job progress and status */
export interface Op_getJobsById {
  method: 'GET';
  path: "/jobs/{id}";
  params: Record<string, string>;
  response: {
  "id": string;
  "type"?: string;
  "status": "pending" | "running" | "completed" | "failed";
  "progress"?: number;
  "result"?: Record<string, unknown> | null;
  "error"?: string | null;
};
}

/** List configured webhooks */
export interface Op_getWebhooks {
  method: 'GET';
  path: "/webhooks";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** Create a webhook */
export interface Op_postWebhooks {
  method: 'POST';
  path: "/webhooks";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Get webhook details */
export interface Op_getWebhooksById {
  method: 'GET';
  path: "/webhooks/{id}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Update webhook details */
export interface Op_patchWebhooksById {
  method: 'PATCH';
  path: "/webhooks/{id}";
  params: Record<string, string>;
  response: Record<string, unknown>;
}

/** Delete webhook */
export interface Op_deleteWebhooksById {
  method: 'DELETE';
  path: "/webhooks/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Send test ping delivery for webhook */
export interface Op_postWebhooksByIdTest {
  method: 'POST';
  path: "/webhooks/{id}/test";
  params: Record<string, string>;
  response: {
  "status"?: string;
  "http_code"?: number;
  "response_body"?: string;
};
}

/** List recent deliveries for webhook */
export interface Op_getWebhooksByIdDeliveries {
  method: 'GET';
  path: "/webhooks/{id}/deliveries";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** List taxonomies */
export interface Op_getTaxonomies {
  method: 'GET';
  path: "/taxonomies";
  params: Record<string, string>;
  response: Array<S.Taxonomy>;
}

/** Create a taxonomy */
export interface Op_postTaxonomies {
  method: 'POST';
  path: "/taxonomies";
  params: Record<string, string>;
  body: {
  "handle": string;
  "name": string;
  "description"?: string | null;
  "is_hierarchical"?: boolean;
  "allow_custom_meta"?: boolean;
};
  bodyKind: 'json';
  response: S.Taxonomy;
}

/** Get taxonomy by handle */
export interface Op_getTaxonomiesByHandle {
  method: 'GET';
  path: "/taxonomies/{handle}";
  params: Record<string, string>;
  response: S.Taxonomy;
}

/** Update taxonomy definition */
export interface Op_patchTaxonomiesByHandle {
  method: 'PATCH';
  path: "/taxonomies/{handle}";
  params: Record<string, string>;
  body: {
  "name"?: string;
  "description"?: string | null;
  "is_hierarchical"?: boolean;
  "allow_custom_meta"?: boolean;
  "default_term_id"?: string | null;
};
  bodyKind: 'json';
  response: S.Taxonomy;
}

/** Delete taxonomy definition */
export interface Op_deleteTaxonomiesByHandle {
  method: 'DELETE';
  path: "/taxonomies/{handle}";
  params: Record<string, string>;
  response: {
  "message"?: string;
};
}

/** Attach taxonomy to collection */
export interface Op_postTaxonomiesByHandleAttach {
  method: 'POST';
  path: "/taxonomies/{handle}/attach";
  params: Record<string, string>;
  body: {
  "collection_handle": string;
  "field_key"?: string;
  "required"?: boolean;
  "allow_multiple"?: boolean;
  "position"?: number;
};
  bodyKind: 'json';
  response: {
  "message"?: string;
};
}

/** List terms tree for taxonomy */
export interface Op_getTaxonomiesByHandleTerms {
  method: 'GET';
  path: "/taxonomies/{handle}/terms";
  params: Record<string, string>;
  query: {
    "parent_id"?: string;
  };
  response: Array<S.Term>;
}

/** Create term in taxonomy */
export interface Op_postTaxonomiesByHandleTerms {
  method: 'POST';
  path: "/taxonomies/{handle}/terms";
  params: Record<string, string>;
  body: {
  "name": string;
  "slug"?: string;
  "description"?: string | null;
  "parent_id"?: string | null;
  "meta"?: Record<string, unknown> | null;
};
  bodyKind: 'json';
  response: S.Term;
}

/** Get term by ID */
export interface Op_getTaxonomiesByHandleTermsById {
  method: 'GET';
  path: "/taxonomies/{handle}/terms/{id}";
  params: Record<string, string>;
  response: S.Term;
}

/** Update term */
export interface Op_patchTaxonomiesByHandleTermsById {
  method: 'PATCH';
  path: "/taxonomies/{handle}/terms/{id}";
  params: Record<string, string>;
  body: {
  "name"?: string;
  "slug"?: string;
  "description"?: string | null;
  "meta"?: Record<string, unknown> | null;
};
  bodyKind: 'json';
  response: S.Term;
}

/** Delete term */
export interface Op_deleteTaxonomiesByHandleTermsById {
  method: 'DELETE';
  path: "/taxonomies/{handle}/terms/{id}";
  params: Record<string, string>;
  query: {
    "reassign_to"?: string;
  };
  response: {
  "message"?: string;
};
}

/** Move term in tree */
export interface Op_postTaxonomiesByHandleTermsByIdMove {
  method: 'POST';
  path: "/taxonomies/{handle}/terms/{id}/move";
  params: Record<string, string>;
  body: {
  "parent_id"?: string | null;
  "position"?: number;
};
  bodyKind: 'json';
  response: S.Term;
}

/** Merge term into target term */
export interface Op_postTaxonomiesByHandleTermsByIdMerge {
  method: 'POST';
  path: "/taxonomies/{handle}/terms/{id}/merge";
  params: Record<string, string>;
  body: {
  "target_term_id": string;
};
  bodyKind: 'json';
  response: {
  "message": string;
};
}

/** Reorder terms in taxonomy */
export interface Op_postTaxonomiesByHandleTermsReorder {
  method: 'POST';
  path: "/taxonomies/{handle}/terms/reorder";
  params: Record<string, string>;
  body: {
  "terms"?: Array<{
      "id": string;
      "position": number;
      "parent_id"?: string | null;
    }>;
};
  bodyKind: 'json';
  response: {
  "message": string;
};
}

/** Bulk term operations */
export interface Op_postTaxonomiesByHandleTermsBulk {
  method: 'POST';
  path: "/taxonomies/{handle}/terms/bulk";
  params: Record<string, string>;
  body: {
  "action": "delete";
  "ids": Array<string>;
  "reassign_to"?: string | null;
};
  bodyKind: 'json';
  response: {
  "affected": number;
  "action": string;
};
}

/** Public terms hierarchy listing */
export interface Op_getPublicTaxonomiesByHandleTerms {
  method: 'GET';
  path: "/public/taxonomies/{handle}/terms";
  params: Record<string, string>;
  response: Array<S.Term>;
}

/** List media folders */
export interface Op_getMediaFolders {
  method: 'GET';
  path: "/media/folders";
  params: Record<string, string>;
  query: {
    "parent_id"?: string;
  };
  response: Array<S.MediaFolder>;
}

/** Create media folder */
export interface Op_postMediaFolders {
  method: 'POST';
  path: "/media/folders";
  params: Record<string, string>;
  body: {
  "name": string;
  "parent_id"?: string | null;
};
  bodyKind: 'json';
  response: S.MediaFolder;
}

/** Update media folder */
export interface Op_patchMediaFoldersById {
  method: 'PATCH';
  path: "/media/folders/{id}";
  params: Record<string, string>;
  body: {
  "name"?: string;
  "parent_id"?: string | null;
};
  bodyKind: 'json';
  response: S.MediaFolder;
}

/** Delete media folder */
export interface Op_deleteMediaFoldersById {
  method: 'DELETE';
  path: "/media/folders/{id}";
  params: Record<string, string>;
  response: {
  "message": string;
};
}

/** Move media folder under new parent */
export interface Op_postMediaFoldersByIdMove {
  method: 'POST';
  path: "/media/folders/{id}/move";
  params: Record<string, string>;
  body: {
  "parent_id"?: string | null;
};
  bodyKind: 'json';
  response: S.MediaFolder;
}

/** List media assets */
export interface Op_getMedia {
  method: 'GET';
  path: "/media";
  params: Record<string, string>;
  query: {
    "folder_id"?: string;
    "search"?: string;
    "type"?: "image" | "video" | "audio" | "document";
    "page"?: number;
    "per_page"?: number;
  };
  response: {
  "items": Array<S.Media>;
  "total": number;
  "page": number;
  "per_page": number;
  "last_page": number;
};
}

/** Upload media file */
export interface Op_postMedia {
  method: 'POST';
  path: "/media";
  params: Record<string, string>;
  body: {
  "file": string;
  "folder_id"?: string | null;
  "title"?: string | null;
  "alt_text"?: string | null;
  "caption"?: string | null;
};
  bodyKind: 'multipart';
  response: S.Media;
}

/** Bulk operations on media assets */
export interface Op_postMediaBulk {
  method: 'POST';
  path: "/media/bulk";
  params: Record<string, string>;
  body: {
  "action": "delete" | "move";
  "ids": Array<string>;
  "folder_id"?: string | null;
  "force"?: boolean;
};
  bodyKind: 'json';
  response: {
  "affected": number;
  "action": string;
};
}

/** Initiate chunked upload session */
export interface Op_postMediaChunksInit {
  method: 'POST';
  path: "/media/chunks/init";
  params: Record<string, string>;
  body: {
    /** Original client filename */
  "original_name": string;
    /** Total number of chunks */
  "total_chunks": number;
    /** Total size in bytes */
  "total_size": number;
  "folder_id"?: string | null;
};
  bodyKind: 'json';
  response: {
  "session_id": string;
};
}

/** Get chunked upload progress */
export interface Op_getMediaChunksById {
  method: 'GET';
  path: "/media/chunks/{id}";
  params: Record<string, string>;
  response: {
  "session_id"?: string;
  "received_chunks"?: Array<number>;
  "total_chunks"?: number;
};
}

/** Upload a chunk part */
export interface Op_postMediaChunksById {
  method: 'POST';
  path: "/media/chunks/{id}";
  params: Record<string, string>;
  body: {
    /** Chunk binary payload */
  "chunk": string;
    /** 0-based sequence index */
  "chunk_index": number;
};
  bodyKind: 'multipart';
  response: {
  "status"?: string;
  "chunk_index"?: number;
};
}

/** Assemble uploaded chunks into media asset */
export interface Op_postMediaChunksByIdAssemble {
  method: 'POST';
  path: "/media/chunks/{id}/assemble";
  params: Record<string, string>;
  body: {
  "title"?: string | null;
  "alt_text"?: string | null;
  "caption"?: string | null;
  "folder_id"?: string | null;
};
  bodyKind: 'json';
  response: S.Media;
}

/** Get media asset by ID */
export interface Op_getMediaById {
  method: 'GET';
  path: "/media/{id}";
  params: Record<string, string>;
  response: S.Media;
}

/** Update media asset metadata */
export interface Op_patchMediaById {
  method: 'PATCH';
  path: "/media/{id}";
  params: Record<string, string>;
  body: {
  "title"?: string | null;
  "alt_text"?: string | null;
  "caption"?: string | null;
  "folder_id"?: string | null;
  "focal_x"?: number | null;
  "focal_y"?: number | null;
};
  bodyKind: 'json';
  response: S.Media;
}

/** Delete media asset */
export interface Op_deleteMediaById {
  method: 'DELETE';
  path: "/media/{id}";
  params: Record<string, string>;
  query: {
    "force"?: boolean;
  };
  response: {
  "message": string;
};
}

/** Replace media file keeping ID intact */
export interface Op_postMediaByIdReplace {
  method: 'POST';
  path: "/media/{id}/replace";
  params: Record<string, string>;
  body: {
  "file": string;
};
  bodyKind: 'multipart';
  response: S.Media;
}

/** List content entries referencing media asset */
export interface Op_getMediaByIdUsage {
  method: 'GET';
  path: "/media/{id}/usage";
  params: Record<string, string>;
  response: {
  "usages": Array<{
      "collection_id"?: string;
      "collection_handle"?: string;
      "collection_name"?: string;
      "entry_id"?: string;
      "entry_title"?: string;
      "field_handle"?: string;
    }>;
};
}

/** Stream media asset with authenticated security headers and ETag */
export interface Op_getMediaByIdFile {
  method: 'GET';
  path: "/media/{id}/file";
  params: Record<string, string>;
  query: {
    "variant"?: string;
  };
  response: unknown;
}

/** Publicly stream media asset with CDN bypass security headers and ETag */
export interface Op_getPublicMediaByIdFile {
  method: 'GET';
  path: "/public/media/{id}/file";
  params: Record<string, string>;
  query: {
    "variant"?: string;
  };
  response: unknown;
}

/** List redirect rules with filtering and pagination */
export interface Op_getRedirects {
  method: 'GET';
  path: "/redirects";
  params: Record<string, string>;
  query: {
    "search"?: string;
    "match_type"?: "exact" | "prefix";
    "status_code"?: number;
    "is_auto"?: boolean;
    "page"?: number;
    "per_page"?: number;
  };
  response: {
  "items": Array<S.Redirect>;
  "total": number;
  "page": number;
  "per_page": number;
  "last_page": number;
};
}

/** Create a redirect rule (exact or prefix, loops prevented) */
export interface Op_postRedirects {
  method: 'POST';
  path: "/redirects";
  params: Record<string, string>;
  body: {
  "from_path": string;
  "to_path": string;
  "status_code"?: 301 | 302 | 307 | 308;
  "match_type"?: "exact" | "prefix";
};
  bodyKind: 'json';
  response: S.Redirect;
}

/** Bulk delete redirects */
export interface Op_postRedirectsBulk {
  method: 'POST';
  path: "/redirects/bulk";
  params: Record<string, string>;
  body: {
  "ids": Array<string>;
};
  bodyKind: 'json';
  response: {
  "deleted": number;
  "requested": number;
};
}

/** Import redirect rules from CSV */
export interface Op_postRedirectsImport {
  method: 'POST';
  path: "/redirects/import";
  params: Record<string, string>;
  body: {
  "csv": string;
};
  bodyKind: 'json';
  response: {
  "imported": number;
  "skipped": number;
  "errors": Array<string>;
};
}

/** Export all redirect rules as CSV */
export interface Op_getRedirectsExport {
  method: 'GET';
  path: "/redirects/export";
  params: Record<string, string>;
  response: unknown;
}

/** Get redirect by ID */
export interface Op_getRedirectsById {
  method: 'GET';
  path: "/redirects/{id}";
  params: Record<string, string>;
  response: S.Redirect;
}

/** Update redirect rule */
export interface Op_patchRedirectsById {
  method: 'PATCH';
  path: "/redirects/{id}";
  params: Record<string, string>;
  body: {
  "from_path"?: string;
  "to_path"?: string;
  "status_code"?: 301 | 302 | 307 | 308;
  "match_type"?: "exact" | "prefix";
};
  bodyKind: 'json';
  response: S.Redirect;
}

/** Delete redirect rule */
export interface Op_deleteRedirectsById {
  method: 'DELETE';
  path: "/redirects/{id}";
  params: Record<string, string>;
  response: {
  "deleted": boolean;
  "id": string;
};
}

/** List active redirects for Astro build and .htaccess export */
export interface Op_getPublicRedirects {
  method: 'GET';
  path: "/public/redirects";
  params: Record<string, string>;
  response: {
  "items": Array<{
      "from_path": string;
      "to_path": string;
      "status_code": number;
      "match_type": string;
    }>;
};
}

/** Match path against active redirects and increment hit counter */
export interface Op_getPublicRedirectsMatch {
  method: 'GET';
  path: "/public/redirects/match";
  params: Record<string, string>;
  query: {
    "path": string;
  };
  response: {
  "id": string;
  "destination": string;
  "status_code": number;
  "match_type": "exact" | "prefix";
};
}

/** Resolve path to canonical SEO and Schema.org @graph (CDN-fresh up to 60s) */
export interface Op_getPublicSeoResolve {
  method: 'GET';
  path: "/public/seo/resolve";
  params: Record<string, string>;
  query: {
    "path": string;
  };
  response: {
  "path": string;
  "entity_type": string;
  "entity_id": string;
  "seo_resolved": S.SeoResolved;
  "structured_data": {
    "@context": string;
    "@graph": Array<Record<string, unknown>>;
  };
};
}

/** Scan content and SEO health rules across collections with bounded pagination */
export interface Op_getSiteHealth {
  method: 'GET';
  path: "/site-health";
  params: Record<string, string>;
  response: S.SiteHealthReport;
}

/** Trigger immediate site health scan */
export interface Op_postSiteHealthRun {
  method: 'POST';
  path: "/site-health/run";
  params: Record<string, string>;
  response: S.SiteHealthReport;
}

/** Get role-aware aggregate dashboard summary for administrative shell */
export interface Op_getDashboard {
  method: 'GET';
  path: "/dashboard";
  params: Record<string, string>;
  response: S.DashboardResponse;
}

/** List entries for authors */
export interface Op_getCollectionsAuthorsEntries {
  method: 'GET';
  path: "/collections/authors/entries";
  params: Record<string, string>;
  query: {
    "search"?: string;
    "sort"?: string;
    "page[size]"?: number;
    "page[after]"?: string;
  };
  response: {
  "items": Array<S.AuthorsEntry>;
  "meta"?: {
    "total"?: number;
    "count"?: number;
    "cursor"?: string | null;
  };
};
}

/** Create entry in authors */
export interface Op_postCollectionsAuthorsEntries {
  method: 'POST';
  path: "/collections/authors/entries";
  params: Record<string, string>;
  body: S.AuthorsEntry;
  bodyKind: 'json';
  response: S.AuthorsEntry;
}

/** Bulk operation on entries in authors */
export interface Op_postCollectionsAuthorsEntriesBulk {
  method: 'POST';
  path: "/collections/authors/entries/bulk";
  params: Record<string, string>;
  response: {
  "action": string;
  "processed": number;
  "results": Array<Record<string, unknown>>;
};
}

/** Get single entry from authors */
export interface Op_getCollectionsAuthorsEntriesById {
  method: 'GET';
  path: "/collections/authors/entries/{id}";
  params: Record<string, string>;
  response: S.AuthorsEntry;
}

/** Full replace entry in authors */
export interface Op_putCollectionsAuthorsEntriesById {
  method: 'PUT';
  path: "/collections/authors/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.AuthorsEntry;
}

/** Partial update entry in authors */
export interface Op_patchCollectionsAuthorsEntriesById {
  method: 'PATCH';
  path: "/collections/authors/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.AuthorsEntry;
}

/** Move entry to trash in authors */
export interface Op_deleteCollectionsAuthorsEntriesById {
  method: 'DELETE';
  path: "/collections/authors/entries/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Restore trashed entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdRestore {
  method: 'POST';
  path: "/collections/authors/entries/{id}/restore";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Permanently purge entry in authors */
export interface Op_deleteCollectionsAuthorsEntriesByIdPurge {
  method: 'DELETE';
  path: "/collections/authors/entries/{id}/purge";
  params: Record<string, string>;
  header: {
    "X-Confirm-Token"?: string;
  };
  response: S.StatusResponse;
}

/** Duplicate entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdDuplicate {
  method: 'POST';
  path: "/collections/authors/entries/{id}/duplicate";
  params: Record<string, string>;
  response: S.AuthorsEntry;
}

/** Publish entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdPublish {
  method: 'POST';
  path: "/collections/authors/entries/{id}/publish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Unpublish entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdUnpublish {
  method: 'POST';
  path: "/collections/authors/entries/{id}/unpublish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Archive entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdArchive {
  method: 'POST';
  path: "/collections/authors/entries/{id}/archive";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Schedule entry in authors */
export interface Op_postCollectionsAuthorsEntriesByIdSchedule {
  method: 'POST';
  path: "/collections/authors/entries/{id}/schedule";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Submit entry for review in authors */
export interface Op_postCollectionsAuthorsEntriesByIdSubmitReview {
  method: 'POST';
  path: "/collections/authors/entries/{id}/submit-review";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.AuthorsEntry;
}

/** Compare two versions of entry in authors */
export interface Op_getCollectionsAuthorsEntriesByIdCompare {
  method: 'GET';
  path: "/collections/authors/entries/{id}/compare";
  params: Record<string, string>;
  query: {
    "from": number;
    "to": number;
  };
  response: {
  "from": number;
  "to": number;
  "diff": Record<string, unknown>;
};
}

/** List versions for entry in authors */
export interface Op_getCollectionsAuthorsEntriesByIdVersions {
  method: 'GET';
  path: "/collections/authors/entries/{id}/versions";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** Public read API for published entries in authors */
export interface Op_getPublicCollectionsAuthorsEntries {
  method: 'GET';
  path: "/public/collections/authors/entries";
  params: Record<string, string>;
  query: {
    "sort"?: string;
    "page[size]"?: number;
  };
  response: {
  "items": Array<S.AuthorsEntry>;
  "meta"?: Record<string, unknown>;
};
}

/** Public read entry by slug in authors */
export interface Op_getPublicCollectionsAuthorsEntriesBySlugBySlug {
  method: 'GET';
  path: "/public/collections/authors/entries/by-slug/{slug}";
  params: Record<string, string>;
  response: S.AuthorsEntry;
}

/** Get singleton navigation */
export interface Op_getSingletonsNavigation {
  method: 'GET';
  path: "/singletons/navigation";
  params: Record<string, string>;
  response: S.NavigationEntry;
}

/** Update singleton navigation */
export interface Op_putSingletonsNavigation {
  method: 'PUT';
  path: "/singletons/navigation";
  params: Record<string, string>;
  response: S.NavigationEntry;
}

/** Partially update singleton navigation */
export interface Op_patchSingletonsNavigation {
  method: 'PATCH';
  path: "/singletons/navigation";
  params: Record<string, string>;
  response: S.NavigationEntry;
}

/** List entries for pages */
export interface Op_getCollectionsPagesEntries {
  method: 'GET';
  path: "/collections/pages/entries";
  params: Record<string, string>;
  query: {
    "search"?: string;
    "sort"?: string;
    "page[size]"?: number;
    "page[after]"?: string;
  };
  response: {
  "items": Array<S.PagesEntry>;
  "meta"?: {
    "total"?: number;
    "count"?: number;
    "cursor"?: string | null;
  };
};
}

/** Create entry in pages */
export interface Op_postCollectionsPagesEntries {
  method: 'POST';
  path: "/collections/pages/entries";
  params: Record<string, string>;
  body: S.PagesEntry;
  bodyKind: 'json';
  response: S.PagesEntry;
}

/** Bulk operation on entries in pages */
export interface Op_postCollectionsPagesEntriesBulk {
  method: 'POST';
  path: "/collections/pages/entries/bulk";
  params: Record<string, string>;
  response: {
  "action": string;
  "processed": number;
  "results": Array<Record<string, unknown>>;
};
}

/** Get single entry from pages */
export interface Op_getCollectionsPagesEntriesById {
  method: 'GET';
  path: "/collections/pages/entries/{id}";
  params: Record<string, string>;
  response: S.PagesEntry;
}

/** Full replace entry in pages */
export interface Op_putCollectionsPagesEntriesById {
  method: 'PUT';
  path: "/collections/pages/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.PagesEntry;
}

/** Partial update entry in pages */
export interface Op_patchCollectionsPagesEntriesById {
  method: 'PATCH';
  path: "/collections/pages/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.PagesEntry;
}

/** Move entry to trash in pages */
export interface Op_deleteCollectionsPagesEntriesById {
  method: 'DELETE';
  path: "/collections/pages/entries/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Restore trashed entry in pages */
export interface Op_postCollectionsPagesEntriesByIdRestore {
  method: 'POST';
  path: "/collections/pages/entries/{id}/restore";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Permanently purge entry in pages */
export interface Op_deleteCollectionsPagesEntriesByIdPurge {
  method: 'DELETE';
  path: "/collections/pages/entries/{id}/purge";
  params: Record<string, string>;
  header: {
    "X-Confirm-Token"?: string;
  };
  response: S.StatusResponse;
}

/** Duplicate entry in pages */
export interface Op_postCollectionsPagesEntriesByIdDuplicate {
  method: 'POST';
  path: "/collections/pages/entries/{id}/duplicate";
  params: Record<string, string>;
  response: S.PagesEntry;
}

/** Publish entry in pages */
export interface Op_postCollectionsPagesEntriesByIdPublish {
  method: 'POST';
  path: "/collections/pages/entries/{id}/publish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Unpublish entry in pages */
export interface Op_postCollectionsPagesEntriesByIdUnpublish {
  method: 'POST';
  path: "/collections/pages/entries/{id}/unpublish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Archive entry in pages */
export interface Op_postCollectionsPagesEntriesByIdArchive {
  method: 'POST';
  path: "/collections/pages/entries/{id}/archive";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Schedule entry in pages */
export interface Op_postCollectionsPagesEntriesByIdSchedule {
  method: 'POST';
  path: "/collections/pages/entries/{id}/schedule";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Submit entry for review in pages */
export interface Op_postCollectionsPagesEntriesByIdSubmitReview {
  method: 'POST';
  path: "/collections/pages/entries/{id}/submit-review";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PagesEntry;
}

/** Compare two versions of entry in pages */
export interface Op_getCollectionsPagesEntriesByIdCompare {
  method: 'GET';
  path: "/collections/pages/entries/{id}/compare";
  params: Record<string, string>;
  query: {
    "from": number;
    "to": number;
  };
  response: {
  "from": number;
  "to": number;
  "diff": Record<string, unknown>;
};
}

/** List versions for entry in pages */
export interface Op_getCollectionsPagesEntriesByIdVersions {
  method: 'GET';
  path: "/collections/pages/entries/{id}/versions";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** Public read API for published entries in pages */
export interface Op_getPublicCollectionsPagesEntries {
  method: 'GET';
  path: "/public/collections/pages/entries";
  params: Record<string, string>;
  query: {
    "sort"?: string;
    "page[size]"?: number;
  };
  response: {
  "items": Array<S.PagesEntry>;
  "meta"?: Record<string, unknown>;
};
}

/** Public read entry by slug in pages */
export interface Op_getPublicCollectionsPagesEntriesBySlugBySlug {
  method: 'GET';
  path: "/public/collections/pages/entries/by-slug/{slug}";
  params: Record<string, string>;
  response: S.PagesEntry;
}

/** List entries for posts */
export interface Op_getCollectionsPostsEntries {
  method: 'GET';
  path: "/collections/posts/entries";
  params: Record<string, string>;
  query: {
    "search"?: string;
    "sort"?: string;
    "page[size]"?: number;
    "page[after]"?: string;
  };
  response: {
  "items": Array<S.PostsEntry>;
  "meta"?: {
    "total"?: number;
    "count"?: number;
    "cursor"?: string | null;
  };
};
}

/** Create entry in posts */
export interface Op_postCollectionsPostsEntries {
  method: 'POST';
  path: "/collections/posts/entries";
  params: Record<string, string>;
  body: S.PostsEntry;
  bodyKind: 'json';
  response: S.PostsEntry;
}

/** Bulk operation on entries in posts */
export interface Op_postCollectionsPostsEntriesBulk {
  method: 'POST';
  path: "/collections/posts/entries/bulk";
  params: Record<string, string>;
  response: {
  "action": string;
  "processed": number;
  "results": Array<Record<string, unknown>>;
};
}

/** Get single entry from posts */
export interface Op_getCollectionsPostsEntriesById {
  method: 'GET';
  path: "/collections/posts/entries/{id}";
  params: Record<string, string>;
  response: S.PostsEntry;
}

/** Full replace entry in posts */
export interface Op_putCollectionsPostsEntriesById {
  method: 'PUT';
  path: "/collections/posts/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.PostsEntry;
}

/** Partial update entry in posts */
export interface Op_patchCollectionsPostsEntriesById {
  method: 'PATCH';
  path: "/collections/posts/entries/{id}";
  params: Record<string, string>;
  header: {
    "If-Match": string;
  };
  response: S.PostsEntry;
}

/** Move entry to trash in posts */
export interface Op_deleteCollectionsPostsEntriesById {
  method: 'DELETE';
  path: "/collections/posts/entries/{id}";
  params: Record<string, string>;
  response: S.StatusResponse;
}

/** Restore trashed entry in posts */
export interface Op_postCollectionsPostsEntriesByIdRestore {
  method: 'POST';
  path: "/collections/posts/entries/{id}/restore";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Permanently purge entry in posts */
export interface Op_deleteCollectionsPostsEntriesByIdPurge {
  method: 'DELETE';
  path: "/collections/posts/entries/{id}/purge";
  params: Record<string, string>;
  header: {
    "X-Confirm-Token"?: string;
  };
  response: S.StatusResponse;
}

/** Duplicate entry in posts */
export interface Op_postCollectionsPostsEntriesByIdDuplicate {
  method: 'POST';
  path: "/collections/posts/entries/{id}/duplicate";
  params: Record<string, string>;
  response: S.PostsEntry;
}

/** Publish entry in posts */
export interface Op_postCollectionsPostsEntriesByIdPublish {
  method: 'POST';
  path: "/collections/posts/entries/{id}/publish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Unpublish entry in posts */
export interface Op_postCollectionsPostsEntriesByIdUnpublish {
  method: 'POST';
  path: "/collections/posts/entries/{id}/unpublish";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Archive entry in posts */
export interface Op_postCollectionsPostsEntriesByIdArchive {
  method: 'POST';
  path: "/collections/posts/entries/{id}/archive";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Schedule entry in posts */
export interface Op_postCollectionsPostsEntriesByIdSchedule {
  method: 'POST';
  path: "/collections/posts/entries/{id}/schedule";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Submit entry for review in posts */
export interface Op_postCollectionsPostsEntriesByIdSubmitReview {
  method: 'POST';
  path: "/collections/posts/entries/{id}/submit-review";
  params: Record<string, string>;
  header: {
    "If-Match"?: string;
  };
  response: S.PostsEntry;
}

/** Compare two versions of entry in posts */
export interface Op_getCollectionsPostsEntriesByIdCompare {
  method: 'GET';
  path: "/collections/posts/entries/{id}/compare";
  params: Record<string, string>;
  query: {
    "from": number;
    "to": number;
  };
  response: {
  "from": number;
  "to": number;
  "diff": Record<string, unknown>;
};
}

/** List versions for entry in posts */
export interface Op_getCollectionsPostsEntriesByIdVersions {
  method: 'GET';
  path: "/collections/posts/entries/{id}/versions";
  params: Record<string, string>;
  response: {
  "items": Array<Record<string, unknown>>;
};
}

/** Public read API for published entries in posts */
export interface Op_getPublicCollectionsPostsEntries {
  method: 'GET';
  path: "/public/collections/posts/entries";
  params: Record<string, string>;
  query: {
    "sort"?: string;
    "page[size]"?: number;
  };
  response: {
  "items": Array<S.PostsEntry>;
  "meta"?: Record<string, unknown>;
};
}

/** Public read entry by slug in posts */
export interface Op_getPublicCollectionsPostsEntriesBySlugBySlug {
  method: 'GET';
  path: "/public/collections/posts/entries/by-slug/{slug}";
  params: Record<string, string>;
  response: S.PostsEntry;
}

/** Get singleton site_settings */
export interface Op_getSingletonsSiteSettings {
  method: 'GET';
  path: "/singletons/site_settings";
  params: Record<string, string>;
  response: S.SiteSettingsEntry;
}

/** Update singleton site_settings */
export interface Op_putSingletonsSiteSettings {
  method: 'PUT';
  path: "/singletons/site_settings";
  params: Record<string, string>;
  response: S.SiteSettingsEntry;
}

/** Partially update singleton site_settings */
export interface Op_patchSingletonsSiteSettings {
  method: 'PATCH';
  path: "/singletons/site_settings";
  params: Record<string, string>;
  response: S.SiteSettingsEntry;
}

export interface Operations {
  "POST /auth/login": Op_postAuthLogin;
  "POST /auth/logout": Op_postAuthLogout;
  "GET /auth/session": Op_getAuthSession;
  "POST /auth/2fa/challenge": Op_postAuth2faChallenge;
  "GET /me": Op_getMe;
  "PUT /me": Op_putMe;
  "GET /me/preferences": Op_getMePreferences;
  "PATCH /me/preferences": Op_patchMePreferences;
  "GET /users": Op_getUsers;
  "GET /roles": Op_getRoles;
  "GET /tokens": Op_getTokens;
  "POST /tokens": Op_postTokens;
  "DELETE /tokens/{id}": Op_deleteTokensById;
  "GET /audit": Op_getAudit;
  "GET /audit/{id}": Op_getAuditById;
  "GET /collections": Op_getCollections;
  "POST /collections": Op_postCollections;
  "GET /collections/{handle}": Op_getCollectionsByHandle;
  "PUT /collections/{handle}": Op_putCollectionsByHandle;
  "DELETE /collections/{handle}": Op_deleteCollectionsByHandle;
  "POST /schema/plan": Op_postSchemaPlan;
  "POST /schema/apply/{change_id}": Op_postSchemaApplyByChangeId;
  "GET /schema/changes": Op_getSchemaChanges;
  "GET /saved-views": Op_getSavedViews;
  "POST /saved-views": Op_postSavedViews;
  "GET /saved-views/{id}": Op_getSavedViewsById;
  "PUT /saved-views/{id}": Op_putSavedViewsById;
  "DELETE /saved-views/{id}": Op_deleteSavedViewsById;
  "GET /jobs/{id}": Op_getJobsById;
  "GET /webhooks": Op_getWebhooks;
  "POST /webhooks": Op_postWebhooks;
  "GET /webhooks/{id}": Op_getWebhooksById;
  "PATCH /webhooks/{id}": Op_patchWebhooksById;
  "DELETE /webhooks/{id}": Op_deleteWebhooksById;
  "POST /webhooks/{id}/test": Op_postWebhooksByIdTest;
  "GET /webhooks/{id}/deliveries": Op_getWebhooksByIdDeliveries;
  "GET /taxonomies": Op_getTaxonomies;
  "POST /taxonomies": Op_postTaxonomies;
  "GET /taxonomies/{handle}": Op_getTaxonomiesByHandle;
  "PATCH /taxonomies/{handle}": Op_patchTaxonomiesByHandle;
  "DELETE /taxonomies/{handle}": Op_deleteTaxonomiesByHandle;
  "POST /taxonomies/{handle}/attach": Op_postTaxonomiesByHandleAttach;
  "GET /taxonomies/{handle}/terms": Op_getTaxonomiesByHandleTerms;
  "POST /taxonomies/{handle}/terms": Op_postTaxonomiesByHandleTerms;
  "GET /taxonomies/{handle}/terms/{id}": Op_getTaxonomiesByHandleTermsById;
  "PATCH /taxonomies/{handle}/terms/{id}": Op_patchTaxonomiesByHandleTermsById;
  "DELETE /taxonomies/{handle}/terms/{id}": Op_deleteTaxonomiesByHandleTermsById;
  "POST /taxonomies/{handle}/terms/{id}/move": Op_postTaxonomiesByHandleTermsByIdMove;
  "POST /taxonomies/{handle}/terms/{id}/merge": Op_postTaxonomiesByHandleTermsByIdMerge;
  "POST /taxonomies/{handle}/terms/reorder": Op_postTaxonomiesByHandleTermsReorder;
  "POST /taxonomies/{handle}/terms/bulk": Op_postTaxonomiesByHandleTermsBulk;
  "GET /public/taxonomies/{handle}/terms": Op_getPublicTaxonomiesByHandleTerms;
  "GET /media/folders": Op_getMediaFolders;
  "POST /media/folders": Op_postMediaFolders;
  "PATCH /media/folders/{id}": Op_patchMediaFoldersById;
  "DELETE /media/folders/{id}": Op_deleteMediaFoldersById;
  "POST /media/folders/{id}/move": Op_postMediaFoldersByIdMove;
  "GET /media": Op_getMedia;
  "POST /media": Op_postMedia;
  "POST /media/bulk": Op_postMediaBulk;
  "POST /media/chunks/init": Op_postMediaChunksInit;
  "GET /media/chunks/{id}": Op_getMediaChunksById;
  "POST /media/chunks/{id}": Op_postMediaChunksById;
  "POST /media/chunks/{id}/assemble": Op_postMediaChunksByIdAssemble;
  "GET /media/{id}": Op_getMediaById;
  "PATCH /media/{id}": Op_patchMediaById;
  "DELETE /media/{id}": Op_deleteMediaById;
  "POST /media/{id}/replace": Op_postMediaByIdReplace;
  "GET /media/{id}/usage": Op_getMediaByIdUsage;
  "GET /media/{id}/file": Op_getMediaByIdFile;
  "GET /public/media/{id}/file": Op_getPublicMediaByIdFile;
  "GET /redirects": Op_getRedirects;
  "POST /redirects": Op_postRedirects;
  "POST /redirects/bulk": Op_postRedirectsBulk;
  "POST /redirects/import": Op_postRedirectsImport;
  "GET /redirects/export": Op_getRedirectsExport;
  "GET /redirects/{id}": Op_getRedirectsById;
  "PATCH /redirects/{id}": Op_patchRedirectsById;
  "DELETE /redirects/{id}": Op_deleteRedirectsById;
  "GET /public/redirects": Op_getPublicRedirects;
  "GET /public/redirects/match": Op_getPublicRedirectsMatch;
  "GET /public/seo/resolve": Op_getPublicSeoResolve;
  "GET /site-health": Op_getSiteHealth;
  "POST /site-health/run": Op_postSiteHealthRun;
  "GET /dashboard": Op_getDashboard;
  "GET /collections/authors/entries": Op_getCollectionsAuthorsEntries;
  "POST /collections/authors/entries": Op_postCollectionsAuthorsEntries;
  "POST /collections/authors/entries/bulk": Op_postCollectionsAuthorsEntriesBulk;
  "GET /collections/authors/entries/{id}": Op_getCollectionsAuthorsEntriesById;
  "PUT /collections/authors/entries/{id}": Op_putCollectionsAuthorsEntriesById;
  "PATCH /collections/authors/entries/{id}": Op_patchCollectionsAuthorsEntriesById;
  "DELETE /collections/authors/entries/{id}": Op_deleteCollectionsAuthorsEntriesById;
  "POST /collections/authors/entries/{id}/restore": Op_postCollectionsAuthorsEntriesByIdRestore;
  "DELETE /collections/authors/entries/{id}/purge": Op_deleteCollectionsAuthorsEntriesByIdPurge;
  "POST /collections/authors/entries/{id}/duplicate": Op_postCollectionsAuthorsEntriesByIdDuplicate;
  "POST /collections/authors/entries/{id}/publish": Op_postCollectionsAuthorsEntriesByIdPublish;
  "POST /collections/authors/entries/{id}/unpublish": Op_postCollectionsAuthorsEntriesByIdUnpublish;
  "POST /collections/authors/entries/{id}/archive": Op_postCollectionsAuthorsEntriesByIdArchive;
  "POST /collections/authors/entries/{id}/schedule": Op_postCollectionsAuthorsEntriesByIdSchedule;
  "POST /collections/authors/entries/{id}/submit-review": Op_postCollectionsAuthorsEntriesByIdSubmitReview;
  "GET /collections/authors/entries/{id}/compare": Op_getCollectionsAuthorsEntriesByIdCompare;
  "GET /collections/authors/entries/{id}/versions": Op_getCollectionsAuthorsEntriesByIdVersions;
  "GET /public/collections/authors/entries": Op_getPublicCollectionsAuthorsEntries;
  "GET /public/collections/authors/entries/by-slug/{slug}": Op_getPublicCollectionsAuthorsEntriesBySlugBySlug;
  "GET /singletons/navigation": Op_getSingletonsNavigation;
  "PUT /singletons/navigation": Op_putSingletonsNavigation;
  "PATCH /singletons/navigation": Op_patchSingletonsNavigation;
  "GET /collections/pages/entries": Op_getCollectionsPagesEntries;
  "POST /collections/pages/entries": Op_postCollectionsPagesEntries;
  "POST /collections/pages/entries/bulk": Op_postCollectionsPagesEntriesBulk;
  "GET /collections/pages/entries/{id}": Op_getCollectionsPagesEntriesById;
  "PUT /collections/pages/entries/{id}": Op_putCollectionsPagesEntriesById;
  "PATCH /collections/pages/entries/{id}": Op_patchCollectionsPagesEntriesById;
  "DELETE /collections/pages/entries/{id}": Op_deleteCollectionsPagesEntriesById;
  "POST /collections/pages/entries/{id}/restore": Op_postCollectionsPagesEntriesByIdRestore;
  "DELETE /collections/pages/entries/{id}/purge": Op_deleteCollectionsPagesEntriesByIdPurge;
  "POST /collections/pages/entries/{id}/duplicate": Op_postCollectionsPagesEntriesByIdDuplicate;
  "POST /collections/pages/entries/{id}/publish": Op_postCollectionsPagesEntriesByIdPublish;
  "POST /collections/pages/entries/{id}/unpublish": Op_postCollectionsPagesEntriesByIdUnpublish;
  "POST /collections/pages/entries/{id}/archive": Op_postCollectionsPagesEntriesByIdArchive;
  "POST /collections/pages/entries/{id}/schedule": Op_postCollectionsPagesEntriesByIdSchedule;
  "POST /collections/pages/entries/{id}/submit-review": Op_postCollectionsPagesEntriesByIdSubmitReview;
  "GET /collections/pages/entries/{id}/compare": Op_getCollectionsPagesEntriesByIdCompare;
  "GET /collections/pages/entries/{id}/versions": Op_getCollectionsPagesEntriesByIdVersions;
  "GET /public/collections/pages/entries": Op_getPublicCollectionsPagesEntries;
  "GET /public/collections/pages/entries/by-slug/{slug}": Op_getPublicCollectionsPagesEntriesBySlugBySlug;
  "GET /collections/posts/entries": Op_getCollectionsPostsEntries;
  "POST /collections/posts/entries": Op_postCollectionsPostsEntries;
  "POST /collections/posts/entries/bulk": Op_postCollectionsPostsEntriesBulk;
  "GET /collections/posts/entries/{id}": Op_getCollectionsPostsEntriesById;
  "PUT /collections/posts/entries/{id}": Op_putCollectionsPostsEntriesById;
  "PATCH /collections/posts/entries/{id}": Op_patchCollectionsPostsEntriesById;
  "DELETE /collections/posts/entries/{id}": Op_deleteCollectionsPostsEntriesById;
  "POST /collections/posts/entries/{id}/restore": Op_postCollectionsPostsEntriesByIdRestore;
  "DELETE /collections/posts/entries/{id}/purge": Op_deleteCollectionsPostsEntriesByIdPurge;
  "POST /collections/posts/entries/{id}/duplicate": Op_postCollectionsPostsEntriesByIdDuplicate;
  "POST /collections/posts/entries/{id}/publish": Op_postCollectionsPostsEntriesByIdPublish;
  "POST /collections/posts/entries/{id}/unpublish": Op_postCollectionsPostsEntriesByIdUnpublish;
  "POST /collections/posts/entries/{id}/archive": Op_postCollectionsPostsEntriesByIdArchive;
  "POST /collections/posts/entries/{id}/schedule": Op_postCollectionsPostsEntriesByIdSchedule;
  "POST /collections/posts/entries/{id}/submit-review": Op_postCollectionsPostsEntriesByIdSubmitReview;
  "GET /collections/posts/entries/{id}/compare": Op_getCollectionsPostsEntriesByIdCompare;
  "GET /collections/posts/entries/{id}/versions": Op_getCollectionsPostsEntriesByIdVersions;
  "GET /public/collections/posts/entries": Op_getPublicCollectionsPostsEntries;
  "GET /public/collections/posts/entries/by-slug/{slug}": Op_getPublicCollectionsPostsEntriesBySlugBySlug;
  "GET /singletons/site_settings": Op_getSingletonsSiteSettings;
  "PUT /singletons/site_settings": Op_putSingletonsSiteSettings;
  "PATCH /singletons/site_settings": Op_patchSingletonsSiteSettings;
}
