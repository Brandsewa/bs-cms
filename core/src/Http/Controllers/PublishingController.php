<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Auth\Actor;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Database\Connection;
use BsCms\Publishing\DeploymentPresenter;
use BsCms\Publishing\DeploymentService;
use BsCms\Publishing\PublishingSettingsPresenter;
use BsCms\Publishing\PublishingSettingsRepository;
use BsCms\Security\HttpClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class PublishingController extends BaseController
{
    public function __construct(
        private readonly PublishingSettingsRepository $settingsRepo,
        private readonly DeploymentService $deploymentService,
        private readonly Authorizer $authorizer,
        private readonly HttpClient $httpClient,
        private readonly ?Connection $connection = null
    ) {
    }

    /**
     * GET /api/v1/settings/publishing
     */
    public function getSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $config = $this->settingsRepo->get();
        return $this->json(PublishingSettingsPresenter::present($config));
    }

    /**
     * PUT /api/v1/settings/publishing
     */
    public function updateSettings(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = $this->getBody($request);
        $actor = $this->getActor($request);

        // Validation
        if (isset($body['target_type']) && !in_array($body['target_type'], ['github_actions', 'webhook', 'manual'], true)) {
            return $this->problem(422, 'Unprocessable Content', "Invalid target_type '{$body['target_type']}'. Must be github_actions, webhook, or manual.");
        }

        if (isset($body['webhook_url']) && $body['webhook_url'] !== '') {
            $webhookUrl = trim((string) $body['webhook_url']);
            $parsed = parse_url($webhookUrl);
            if ($parsed === false || empty($parsed['host'])) {
                return $this->problem(422, 'Unprocessable Content', 'Webhook URL must be a valid HTTPS URL.');
            }

            // Require https only
            if (strtolower($parsed['scheme'] ?? '') !== 'https') {
                return $this->problem(422, 'Unprocessable Content', 'Webhook URL must use the HTTPS scheme.');
            }

            // Reject userinfo/credentials
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return $this->problem(422, 'Unprocessable Content', 'Webhook URL must not contain embedded credentials.');
            }

            // Require standard ports: null, 443, or 8443
            $port = $parsed['port'] ?? null;
            if ($port !== null && $port !== 443 && $port !== 8443) {
                return $this->problem(422, 'Unprocessable Content', 'Webhook URL port must be 443 or 8443.');
            }

            $host = strtolower(trim($parsed['host'], '[]'));

            // Reject localhost / loopback / private names and IP literals
            if (
                $host === 'localhost' ||
                str_ends_with($host, '.localhost') ||
                str_ends_with($host, '.local') ||
                str_ends_with($host, '.internal') ||
                str_ends_with($host, '.lan') ||
                $host === '127.0.0.1' ||
                $host === '::1' ||
                $host === '0.0.0.0'
            ) {
                return $this->problem(422, 'Unprocessable Content', 'Webhook URL cannot point to localhost or private domains.');
            }

            // If host is an IP literal, validate public routable IP
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if (!filter_var($host, FILTER_VALIDATE_IP, $flags)) {
                    return $this->problem(422, 'Unprocessable Content', "Webhook URL cannot point to private or reserved IP address '{$host}'.");
                }
                if (str_starts_with($host, '169.254.') || str_starts_with($host, '127.')) {
                    return $this->problem(422, 'Unprocessable Content', "Webhook URL cannot point to link-local or loopback IP '{$host}'.");
                }
            }
        }

        $updated = $this->settingsRepo->update($body, $actor->id);
        return $this->json(PublishingSettingsPresenter::present($updated));
    }

    /**
     * POST /api/v1/settings/publishing/test
     */
    public function testDispatch(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'manage', new Subject('settings', 'publishing'));
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getBody($request);
        $config = $this->settingsRepo->get();
        $target = (string) ($body['target_type'] ?? ($config['target_type'] ?? 'github_actions'));

        if ($target === 'webhook') {
            $webhookUrl = (string) ($body['webhook_url'] ?? ($config['webhook_url'] ?? ''));
            if ($webhookUrl === '') {
                return $this->problem(422, 'Unprocessable Content', 'No webhook URL configured to test.');
            }

            try {
                $testPayload = json_encode([
                    'event' => 'test_dispatch',
                    'timestamp' => date('c'),
                    'message' => 'BS CMS Publishing Test Dispatch',
                ], JSON_THROW_ON_ERROR);

                $resp = $this->httpClient->post($webhookUrl, $testPayload, [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'BS-CMS-Publishing-Test',
                ]);

                $isSuccess = $resp['status'] >= 200 && $resp['status'] < 300;
                $message = $isSuccess
                    ? 'Test webhook delivered successfully.'
                    : "Webhook delivery failed with HTTP {$resp['status']}.";

                return $this->json([
                    'success' => $isSuccess,
                    'status_code' => $resp['status'],
                    'message' => $message,
                ]);
            } catch (Throwable $e) {
                return $this->problem(400, 'Bad Request', "Webhook test failed: {$e->getMessage()}");
            }
        }

        if ($target === 'github_actions') {
            $repo = (string) ($body['github_repository'] ?? ($config['github_repository'] ?? ''));
            $token = !empty($body['github_token']) ? (string) $body['github_token'] : (string) ($config['github_token'] ?? '');

            if ($repo === '' || $token === '') {
                return $this->problem(422, 'Unprocessable Content', 'GitHub repository and token must be configured.');
            }

            try {
                // Ping GitHub repo API to verify token access
                $resp = $this->httpClient->get("https://api.github.com/repos/{$repo}", [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => "Bearer {$token}",
                    'User-Agent' => 'BS-CMS-Publishing-Test',
                ]);

                if ($resp['status'] >= 200 && $resp['status'] < 300) {
                    return $this->json([
                        'success' => true,
                        'status_code' => $resp['status'],
                        'message' => "Successfully connected to GitHub repository '{$repo}'.",
                    ]);
                }

                return $this->problem(400, 'Bad Request', "GitHub returned HTTP status {$resp['status']}.");
            } catch (Throwable $e) {
                return $this->problem(400, 'Bad Request', "GitHub connection test failed: {$e->getMessage()}");
            }
        }

        return $this->json([
            'success' => true,
            'status_code' => 200,
            'message' => 'Manual mode configured.',
        ]);
    }

    /**
     * GET /api/v1/deployments
     */
    public function listDeployments(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('site', 'deploy'));
        if ($permError !== null) {
            return $permError;
        }

        $deploys = $this->deploymentService->listDeployments(20);
        return $this->json(DeploymentPresenter::presentCollection($deploys));
    }

    /**
     * POST /api/v1/deployments/trigger
     */
    public function triggerDeploy(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'manage', new Subject('site', 'deploy'));
        if ($permError !== null) {
            return $permError;
        }

        $actor = $this->getActor($request);
        $body = $this->getBody($request);
        $rawUrls = isset($body['changed_urls']) && is_array($body['changed_urls']) ? $body['changed_urls'] : [];
        /** @var list<string> $urls */
        $urls = array_values(array_filter($rawUrls, static fn ($u): bool => is_string($u)));

        $deployment = $this->deploymentService->createAndDispatchDeployment($urls, 'manual_ui', $actor->id);
        return $this->json(DeploymentPresenter::present($deployment), 201);
    }

    /**
     * POST /api/v1/deployments/{id}/status
     * Callback endpoint for external CI runner or webhook receiver.
     *
     * @param array<string, mixed> $args
     */
    public function updateDeploymentStatus(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $deploymentId = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        // 1. Verify Authorization header and token
        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->problem(401, 'Unauthorized', 'Callback token required in Authorization header.');
        }

        $rawCallbackToken = trim(substr($authHeader, 7));
        if ($rawCallbackToken === '') {
            return $this->problem(401, 'Unauthorized', 'Empty callback token.');
        }

        // 2. Check deployment exists first
        $deployment = $this->deploymentService->getDeployment($deploymentId);
        if ($deploymentId === '' || $deployment === null) {
            // Check if token matches any deployment so unknown token returns 401
            $matchedDeployId = $this->deploymentService->findDeploymentIdByCallbackToken($rawCallbackToken);
            if ($matchedDeployId === null) {
                return $this->problem(401, 'Unauthorized', 'Invalid or expired callback token.');
            }
            return $this->problem(404, 'Not Found', 'Deployment not found.');
        }

        // 3. Verify token matches this specific deployment
        if (!$this->deploymentService->verifyCallbackToken($deploymentId, $rawCallbackToken)) {
            // If deployment is already terminal, token was burned on terminal completion
            $currentStatus = (string) ($deployment['status'] ?? '');
            if (in_array($currentStatus, [
                DeploymentService::STATUS_SUCCEEDED,
                DeploymentService::STATUS_FAILED,
                DeploymentService::STATUS_CANCELLED,
            ], true)) {
                return $this->problem(400, 'Bad Request', "Deployment is already finalized in terminal state '{$currentStatus}'.");
            }

            return $this->problem(401, 'Unauthorized', 'Invalid or expired callback token.');
        }

        // 4. Check if deployment is already in a terminal state
        $currentStatus = (string) ($deployment['status'] ?? '');
        if (in_array($currentStatus, [
            DeploymentService::STATUS_SUCCEEDED,
            DeploymentService::STATUS_FAILED,
            DeploymentService::STATUS_CANCELLED,
        ], true)) {
            return $this->problem(400, 'Bad Request', "Deployment is already in terminal state '{$currentStatus}'.");
        }

        $body = $this->getBody($request);
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, [
            DeploymentService::STATUS_BUILDING,
            DeploymentService::STATUS_SUCCEEDED,
            DeploymentService::STATUS_FAILED,
            DeploymentService::STATUS_CANCELLED,
        ], true)) {
            return $this->problem(422, 'Unprocessable Content', "Invalid deployment status '{$status}'.");
        }

        try {
            $updated = $this->deploymentService->handleStatusCallback($deploymentId, $rawCallbackToken, [
                'status' => $status,
                'build_log_url' => isset($body['build_log_url']) ? (string) $body['build_log_url'] : null,
                'error' => isset($body['error']) ? (string) $body['error'] : null,
            ]);

            return $this->json(DeploymentPresenter::present($updated));
        } catch (Throwable $e) {
            // Never leak raw exception stack traces
            return $this->problem(400, 'Bad Request', $e->getMessage());
        }
    }

    /**
     * Minimal "Connect the Astro frontend" status endpoint for the admin dashboard.
     * GET /api/v1/frontend/status
     */
    public function frontendStatus(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);
        // Authorize: anyone with read access to dashboard or settings can view frontend status
        $decision = $this->authorizer->can($actor, 'read', new Subject('dashboard', '*'));
        if (!$decision->allowed) {
            $decision = $this->authorizer->can($actor, 'read', new Subject('settings', 'publishing'));
            if (!$decision->allowed) {
                return $this->problem(403, 'Forbidden', 'Permission denied to view frontend status.');
            }
        }

        $deployments = $this->deploymentService->listDeployments(1);
        $lastDeployment = !empty($deployments) ? DeploymentPresenter::present($deployments[0]) : null;

        $lastBuildAt = null;
        if ($lastDeployment !== null && !empty($lastDeployment['finished_at'])) {
            $lastBuildAt = $lastDeployment['finished_at'];
        } elseif ($lastDeployment !== null && !empty($lastDeployment['dispatched_at'])) {
            $lastBuildAt = $lastDeployment['dispatched_at'];
        }

        $pending = $this->deploymentService->getPendingChangesCount();
        $config = $this->settingsRepo->get();

        // Resolve canonical site URL from settings, or null if not configured
        $siteUrl = null;
        if (!empty($config['site_url'])) {
            $siteUrl = (string) $config['site_url'];
        } elseif ($this->connection !== null) {
            try {
                $prefix = $this->connection->getPrefix();
                $stmt = $this->connection->execute("SELECT `data` FROM `{$prefix}c_site_settings` LIMIT 1");
                $siteRow = $stmt->fetch();
                if ($siteRow && !empty($siteRow['data'])) {
                    $siteData = is_string($siteRow['data']) ? json_decode($siteRow['data'], true) : $siteRow['data'];
                    if (is_array($siteData)) {
                        $candidate = (string) ($siteData['canonical_base_url'] ?? ($siteData['canonical_url'] ?? ''));
                        if (trim($candidate) !== '') {
                            $siteUrl = trim($candidate);
                        }
                    }
                }
            } catch (\Throwable) {
                // Table might not exist yet; default to null
            }
        }

        return $this->json([
            'connected' => $lastDeployment !== null || !empty($config['target_type']),
            'target_type' => (string) ($config['target_type'] ?? 'github_actions'),
            'last_deployment' => $lastDeployment,
            'last_build_at' => $lastBuildAt,
            'pending_changes_count' => $pending,
            'site_url' => $siteUrl,
        ]);
    }
}
