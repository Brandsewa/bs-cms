<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Plugins\ContactFormPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FormsController extends BaseController
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

        $forms = $this->contactFormPlugin->getForms();
        return $this->json(['data' => $forms]);
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
        $form = $this->contactFormPlugin->getForm($id);

        if ($form === null) {
            return $this->problem(404, 'Not Found', "Form '{$id}' not found.");
        }

        return $this->json(['data' => $form]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'create');
        if ($permError !== null) {
            return $permError;
        }

        $body = $this->getJsonBody($request);

        try {
            $form = $this->contactFormPlugin->createForm($body);
            return $this->json(['data' => $form, 'message' => 'Form created successfully.'], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'form_create_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to create form.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function update(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->checkAccess($request, 'update');
        if ($permError !== null) {
            return $permError;
        }

        $id = (string) ($args['id'] ?? $request->getAttribute('id', ''));
        $body = $this->getJsonBody($request);

        try {
            $form = $this->contactFormPlugin->updateForm($id, $body);
            return $this->json(['data' => $form, 'message' => 'Form updated successfully.']);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(400, 'Bad Request', $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'form_update_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to update form.');
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
            $this->contactFormPlugin->deleteForm($id);
            return $this->json(['message' => 'Form deleted successfully.']);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'form_delete_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to delete form.');
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

        // Fallback: check collection permission
        $fallback = $this->authorizer->can($actor, $action, new Subject('collection', 'forms'));
        if ($fallback->allowed) {
            return null;
        }

        return $this->problem(403, 'Forbidden', 'You do not have permission to manage forms.');
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
