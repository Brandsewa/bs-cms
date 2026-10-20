<?php

declare(strict_types=1);

namespace BsCms\Authz;

use BsCms\Auth\Actor;
use BsCms\Auth\SystemRoles;
use BsCms\Database\Connection;
use PDO;

/**
 * Single source of truth for authorization across API, Admin, and MCP.
 * Implements deny-overrides-allow, default deny, conditions, AI safety mode,
 * owner protection, and field filtering.
 */
final class Authorizer
{
    private FieldFilter $fieldFilter;

    public function __construct(
        private readonly Connection $connection,
        private readonly PermissionCompiler $compiler,
        ?FieldFilter $fieldFilter = null
    ) {
        $this->fieldFilter = $fieldFilter ?? new FieldFilter();
    }

    /**
     * Evaluate whether the given Actor is permitted to perform the specified Action on the Subject.
     *
     * @param array<string, mixed>|null $entity Optional target entity for condition checks (e.g. owner=self, status=draft)
     */
    public function can(Actor $actor, string $action, Subject $subject, ?array $entity = null): Decision
    {
        // 1. System actor always has full unrestricted access
        if ($actor->isSystem()) {
            return Decision::allow('System actor has unrestricted access.');
        }

        // 2. Token Scope restrictions (if actor is an API token or has explicit scopes)
        if ($actor->type === Actor::TYPE_API_TOKEN) {
            $scopes = $actor->scopes;
            if (!in_array('*', $scopes, true)) {
                $scopeAllowed = false;
                foreach ($scopes as $scope) {
                    if ($this->matchesScope($scope, $action, $subject)) {
                        $scopeAllowed = true;
                        break;
                    }
                }
                if (!$scopeAllowed) {
                    return Decision::deny("Token scope does not permit action '{$action}' on '{$subject->type}'.");
                }
            }
        }

        // 3. AI Mode & OAuth security restrictions:
        if ($actor->aiMode === Actor::AI_MODE_DRAFT_ONLY) {
            $destructiveActions = ['publish', 'unpublish', 'delete', 'export', 'manage', 'schedule'];
            $sensitiveSubjects = ['users', 'roles', 'settings', 'schema', 'system', 'redirect', 'deploy', 'crawler_policy', 'environment', 'term', 'terms', 'taxonomy', 'taxonomies'];

            if (in_array($action, $destructiveActions, true)) {
                return Decision::deny("Action '{$action}' is forbidden in AI draft_only mode.");
            }

            if (in_array($subject->type, $sensitiveSubjects, true) && $action !== 'read') {
                return Decision::deny("Modifying '{$subject->type}' is forbidden in AI draft_only mode.");
            }
        }

        // Security invariant: AI actors and OAuth tokens can NEVER modify settings (including tool allowlist), roles, or users
        if ($actor->isAi() || $actor->type === Actor::TYPE_OAUTH || $actor->aiMode !== null) {
            if (in_array($subject->type, ['settings', 'roles', 'users'], true) && $action !== 'read') {
                return Decision::deny("AI actors and OAuth tokens are forbidden from modifying '{$subject->type}'.");
            }
        }

        // 4. Role evaluation
        if ($actor->grantRoleId !== null) {
            // OAuth grant-bound permission intersection:
            // 1) The grant's role must allow the action
            $grantPermissions = $this->compiler->compile([$actor->grantRoleId]);
            $grantDecision = $this->evaluatePermissionRules($grantPermissions, $actor, $action, $subject, $entity);
            if (!$grantDecision->allowed) {
                return Decision::deny("OAuth grant role does not permit action '{$action}' on '{$subject->type}'.");
            }

            // 2) The user's current roles must ALSO allow the action
            if ($actor->userRoleIds === []) {
                return Decision::deny("User has no assigned roles (default deny).");
            }

            $userPermissions = $this->compiler->compile($actor->userRoleIds);
            $userActor = Actor::user((string) $actor->onBehalfOf, $actor->label, $actor->userRoleIds);
            $userDecision = $this->evaluatePermissionRules($userPermissions, $userActor, $action, $subject, $entity);
            if (!$userDecision->allowed) {
                return Decision::deny("User does not have permission to perform action '{$action}' on '{$subject->type}'.");
            }

            return Decision::allow("Permitted by intersection of OAuth grant role and user permissions.");
        }

        // Standard role evaluation for human users / API tokens
        if ($actor->roleIds === []) {
            return Decision::deny('Actor has no assigned roles (default deny).');
        }

        $permissions = $this->compiler->compile($actor->roleIds);
        return $this->evaluatePermissionRules($permissions, $actor, $action, $subject, $entity);
    }

    /**
     * @param list<Permission> $permissions
     * @param array<string, mixed>|null $entity
     */
    private function evaluatePermissionRules(array $permissions, Actor $actor, string $action, Subject $subject, ?array $entity = null): Decision
    {
        if ($permissions === []) {
            return Decision::deny('No permissions assigned to roles (default deny).');
        }

        $hasExplicitAllow = false;
        $allowReason = 'Allowed by role policy.';

        foreach ($permissions as $rule) {
            if (!$this->matchesSubject($rule, $subject)) {
                continue;
            }

            if (!$this->matchesAction($rule, $action)) {
                continue;
            }

            if (!$this->matchesConditions($rule, $actor, $entity)) {
                continue;
            }

            // Deny beats allow: explicit deny immediately terminates evaluation
            if ($rule->effect === 'deny') {
                return Decision::deny("Explicitly denied on {$rule->subjectType}:{$rule->subjectKey} for action '{$action}'.");
            }

            if ($rule->effect === 'allow') {
                $hasExplicitAllow = true;
                $allowReason = "Allowed by rule on {$rule->subjectType}:{$rule->subjectKey} for action '{$action}'.";
            }
        }

        if ($hasExplicitAllow) {
            return Decision::allow($allowReason);
        }

        return Decision::deny("No matching permission rule allows action '{$action}' on {$subject->type}:{$subject->key} (default deny).");
    }

    /**
     * Protect the system from deleting, suspending, or downgrading the last active owner.
     */
    public function canModifyOwner(string $targetUserId, string $operation): Decision
    {
        $disallowedOperations = ['delete', 'suspend', 'downgrade'];
        if (!in_array($operation, $disallowedOperations, true)) {
            return Decision::allow();
        }

        // Check if target user has the owner role
        $userRoles = $this->connection->prefixTable('user_roles');
        $rolesTable = $this->connection->prefixTable('roles');
        $usersTable = $this->connection->prefixTable('users');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT 1 FROM `{$userRoles}` ur
            JOIN `{$rolesTable}` r ON r.`id` = ur.`role_id`
            WHERE ur.`user_id` = :uid AND (r.`id` = :owner_id OR r.`handle` = 'owner')
            LIMIT 1");
        $stmt->execute(['uid' => $targetUserId, 'owner_id' => SystemRoles::ROLE_OWNER]);

        if ($stmt->fetchColumn() === false) {
            // Target is not an owner
            return Decision::allow();
        }

        // Target IS an owner. Count total active owners in system
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT u.`id`) FROM `{$usersTable}` u
            JOIN `{$userRoles}` ur ON ur.`user_id` = u.`id`
            JOIN `{$rolesTable}` r ON r.`id` = ur.`role_id`
            WHERE u.`status` = 'active' AND (r.`id` = :owner_id OR r.`handle` = 'owner')");
        $countStmt->execute(['owner_id' => SystemRoles::ROLE_OWNER]);
        $ownerCount = (int) $countStmt->fetchColumn();

        if ($ownerCount <= 1) {
            return Decision::deny("Cannot {$operation} the last active owner of the system.");
        }

        return Decision::allow();
    }

    /**
     * Filter data array fields according to field permissions for the given operation.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterFields(Actor $actor, Subject $subject, array $data, string $operation = 'read'): array
    {
        if ($actor->isSystem()) {
            return $data;
        }

        $permissions = $this->compiler->compile($actor->roleIds);
        $filtered = $data;

        foreach ($permissions as $rule) {
            if (!$this->matchesSubject($rule, $subject)) {
                continue;
            }

            if ($rule->fields !== null) {
                $filtered = $this->fieldFilter->filter($filtered, $rule->fields, $operation);
            }
        }

        return $filtered;
    }

    /**
     * Returns a summary map of compiled permissions for the frontend UI.
     *
     * @return list<array{subject_type: string, subject_key: string, action: string, effect: string, conditions: array<string, mixed>|null}>
     */
    public function getEffectivePermissions(Actor $actor): array
    {
        if ($actor->isSystem()) {
            return [
                ['subject_type' => '*', 'subject_key' => '*', 'action' => '*', 'effect' => 'allow', 'conditions' => null]
            ];
        }

        $permissions = $this->compiler->compile($actor->roleIds);
        $summary = [];

        foreach ($permissions as $p) {
            $summary[] = [
                'subject_type' => $p->subjectType,
                'subject_key' => $p->subjectKey,
                'action' => $p->action,
                'effect' => $p->effect,
                'conditions' => $p->conditions,
            ];
        }

        return $summary;
    }

    private function matchesSubject(Permission $rule, Subject $subject): bool
    {
        if ($rule->subjectType !== '*' && $rule->subjectType !== $subject->type) {
            return false;
        }

        if ($rule->subjectKey !== '*' && $rule->subjectKey !== $subject->key) {
            return false;
        }

        return true;
    }

    private function matchesAction(Permission $rule, string $action): bool
    {
        if ($rule->action === '*' || $rule->action === $action) {
            return true;
        }

        // 'manage' implies all operations on that subject
        if ($rule->action === 'manage') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $entity
     */
    private function matchesConditions(Permission $rule, Actor $actor, ?array $entity): bool
    {
        if ($rule->conditions === null || empty($rule->conditions)) {
            return true;
        }

        // If conditions are present but no entity was supplied, condition cannot be satisfied
        if ($entity === null) {
            return false;
        }

        // 1. owner=self check
        if (isset($rule->conditions['owner']) && $rule->conditions['owner'] === 'self') {
            $ownerId = $entity['created_by'] ?? ($entity['author_id'] ?? null);
            $userId = $actor->onBehalfOf ?? $actor->id;
            if ($ownerId === null || $ownerId !== $userId) {
                return false;
            }
        }

        // 2. status check
        if (isset($rule->conditions['status'])) {
            $allowedStatuses = (array) $rule->conditions['status'];
            $entityStatus = $entity['status'] ?? null;
            if ($entityStatus === null || !in_array($entityStatus, $allowedStatuses, true)) {
                return false;
            }
        }

        return true;
    }

    private function matchesScope(string $scope, string $action, Subject $subject): bool
    {
        $scope = trim($scope);
        if ($scope === '*' || $scope === '*:*') {
            return true;
        }

        if (str_contains($scope, ':')) {
            [$scopeSubject, $scopeAction] = explode(':', $scope, 2);
            $subjectMatches = ($scopeSubject === '*' || $scopeSubject === $subject->type);
            $actionMatches = ($scopeAction === '*' || $scopeAction === $action);
            return $subjectMatches && $actionMatches;
        }

        if ($scope === $action || $scope === $subject->type) {
            return true;
        }

        return false;
    }
}
