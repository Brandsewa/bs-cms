<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Authz\Authorizer;
use BsCms\Authz\Subject;
use BsCms\Plugins\PluginManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MarketplaceController extends BaseController
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly Authorizer $authorizer
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'read', new Subject('plugins', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $catalog = $this->pluginManager->getCatalog();
        return $this->json(['data' => $catalog]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function install(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('plugins', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if ($handle === '') {
            return $this->problem(400, 'Bad Request', 'Plugin handle is required.');
        }

        try {
            $plugin = $this->pluginManager->install($handle);
            return $this->json(['data' => $plugin, 'message' => "Plugin '{$handle}' installed successfully."]);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'plugin_install_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to install plugin.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function uninstall(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('plugins', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if ($handle === '') {
            return $this->problem(400, 'Bad Request', 'Plugin handle is required.');
        }

        try {
            $this->pluginManager->uninstall($handle);
            return $this->json(['message' => "Plugin '{$handle}' uninstalled successfully."]);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'plugin_uninstall_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to uninstall plugin.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function toggle(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('plugins', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if ($handle === '') {
            return $this->problem(400, 'Bad Request', 'Plugin handle is required.');
        }

        $body = $this->getJsonBody($request);
        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : null;

        try {
            $plugin = $this->pluginManager->toggle($handle, $enabled);
            return $this->json(['data' => $plugin, 'message' => "Plugin '{$handle}' updated."]);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'plugin_toggle_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to toggle plugin state.');
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function updateConfig(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $permError = $this->requirePermission($this->authorizer, $request, 'update', new Subject('plugins', '*'));
        if ($permError !== null) {
            return $permError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        if ($handle === '') {
            return $this->problem(400, 'Bad Request', 'Plugin handle is required.');
        }

        $body = $this->getJsonBody($request);
        $config = is_array($body['config'] ?? null) ? $body['config'] : $body;

        try {
            $plugin = $this->pluginManager->updateConfig($handle, $config);
            return $this->json(['data' => $plugin, 'message' => "Plugin configuration updated."]);
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'plugin_config_error');
            return $this->problem(500, 'Internal Server Error', 'Failed to update plugin configuration.');
        }
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
