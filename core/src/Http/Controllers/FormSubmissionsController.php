<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Plugins\ContactFormPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FormSubmissionsController extends BaseController
{
    public function __construct(
        private readonly ContactFormPlugin $contactFormPlugin,
        private readonly Authorizer $authorizer
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'read');
        if ($permError !== null) {
            return $permError;
        }

        $params = $request->getQueryParams();
        $formId = isset($params['form_id']) ? (string) $params['form_id'] : null;
        $status = isset($params['status']) ? (string) $params['status'] : null;
        $limit = isset($params['limit']) ? (int) $params['limit'] : 100;

        $submissions = $this->contactFormPlugin->getSubmissions($formId, $status, $limit);
        return $this->json(['data' => $submissions]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'read');
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $submission = $this->contactFormPlugin->getSubmission($id);

        if ($submission === null) {
            return $this->problem(404, 'Not Found', "Submission '{$id}' not found.");
        }

        return $this->json(['data' => $submission]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function updateStatus(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'update');
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $body = $this->getJsonBody($request);
        $status = (string) ($body['status'] ?? '');

        try {
            $updated = $this->contactFormPlugin->updateSubmissionStatus($id, $status);
            return $this->json(['data' => $updated, 'message' => "Submission marked as {$status}."]);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'submission_status_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to update submission.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function delete(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'delete');
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));

        try {
            $this->contactFormPlugin->deleteSubmission($id);
            return $this->json(['message' => 'Submission deleted successfully.']);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'submission_delete_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to delete submission.');
        }
    }

    private function checkAccess(ServerRequestInterface $request, string $action): ?ResponseInterface
    {
        $authError = $this->requireAuth($request);
        if ($authError !== null) {
            return $authError;
        }

        $actor = $this->getActor($request);
        $decision = $this->authorizer->can($actor, $action, new Subject('plugins', '*'));
        if ($decision->allowed) {
            return null;
        }

        $fallback = $this->authorizer->can($actor, $action, new Subject('collection', 'forms'));
        if ($fallback->allowed) {
            return null;
        }

        return $this->problem(403, 'Forbidden', 'You do not have permission to view submissions.');
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
