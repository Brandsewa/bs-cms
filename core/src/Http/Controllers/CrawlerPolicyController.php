<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Audit\AuditLogger;
use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\CrawlerPolicy\CanaryChecker;
use BsCms\CrawlerPolicy\CrawlerPolicyService;
use BsCms\CrawlerPolicy\IndexNowService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CrawlerPolicyController extends BaseController
{
    public function __construct(
        private readonly CrawlerPolicyService $policyService,
        private readonly CanaryChecker $canaryChecker,
        private readonly IndexNowService $indexNowService,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    public function getActive(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $active = $this->policyService->getActiveVersion();
        $catalogue = $this->policyService->getBotCatalogue();

        return $this->json([
            'data' => $active,
            'catalogue' => $catalogue,
        ]);
    }

    public function listVersions(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $versions = $this->policyService->listVersions();
        return $this->json(['data' => $versions]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->getActor($request);
        $perm = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $body = (array) $request->getParsedBody();
        $policy = (array) ($body['policy'] ?? $body);
        $note = isset($body['change_note']) ? (string) $body['change_note'] : null;

        try {
            $created = $this->policyService->saveVersion($policy, 'production', $actor->id, $note);

            $this->auditLogger->record(
                actor: $actor,
                action: 'crawler_policy.updated',
                subject: new Subject('settings', 'crawler_policy'),
                summary: 'Updated crawler policy (preset: ' . ($created['preset'] ?? 'balanced') . ')',
                diff: ['after' => $created],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null
            );

            return $this->json(['data' => $created], 201);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Validation Error', $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Invalid crawler policy.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function rollback(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $actor = $this->getActor($request);
        $perm = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $stepUpError = $this->requireStepUp($request);
        if ($stepUpError !== null) {
            return $stepUpError;
        }

        $versionId = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        try {
            $created = $this->policyService->rollbackTo($versionId, $actor->id);

            $this->auditLogger->record(
                actor: $actor,
                action: 'crawler_policy.rollback',
                subject: new Subject('settings', 'crawler_policy'),
                summary: "Rolled back crawler policy to version {$versionId}",
                diff: ['after' => $created],
                requestId: (string) $request->getAttribute('request_id', ''),
                ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null
            );

            return $this->json(['data' => $created]);
        } catch (\Throwable $e) {
            return $this->problem(422, 'Rollback Failed', 'Unable to roll back to that version.');
        }
    }

    public function runCanary(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'read', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $body = (array) $request->getParsedBody();
        $siteUrl = (string) ($body['site_url'] ?? 'https://bskickstarter.com');
        $active = $this->policyService->getActiveVersion();

        $result = $this->canaryChecker->check($siteUrl, (string) ($active['robots_txt'] ?? ''));
        return $this->json(['data' => $result]);
    }

    public function submitIndexNow(ServerRequestInterface $request): ResponseInterface
    {
        $perm = $this->requirePermission($this->authorizer, $request, 'update', new Subject('settings', 'crawler_policy'));
        if ($perm !== null) {
            return $perm;
        }

        $body = (array) $request->getParsedBody();
        $urls = (array) ($body['urls'] ?? []);
        $host = (string) ($body['host'] ?? parse_url((string) ($body['site_url'] ?? 'https://bskickstarter.com'), PHP_URL_HOST));
        $key = (string) ($body['key'] ?? '');

        $cleanUrls = [];
        foreach ($urls as $u) {
            if (is_string($u) && $u !== '') {
                $cleanUrls[] = $u;
            }
        }

        $res = $this->indexNowService->submit($cleanUrls, $host, $key);
        return $this->json(['data' => $res]);
    }
}
