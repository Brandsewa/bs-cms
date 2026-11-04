<?php

declare(strict_types=1);

namespace BsCms\Http\Controllers;

use BsCms\Plugins\ContactFormPlugin;
use BsCms\Plugins\PluginManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PublicFormsController extends BaseController
{
    public function __construct(
        private readonly ContactFormPlugin $contactFormPlugin,
        private readonly PluginManager $pluginManager,
        private readonly ?\BsCms\Security\RateLimiter $rateLimiter = null
    ) {
    }

    /**
     * Public surface: answer 404 (not an auth error) when the plugin is not
     * installed or disabled, so uninstalled plugins leak nothing.
     */
    private function requirePlugin(): ?ResponseInterface
    {
        if (!$this->pluginManager->isPluginEnabled(ContactFormPlugin::HANDLE)) {
            return $this->problem(404, 'Not Found', 'Not Found.');
        }
        return null;
    }


    /**
     * Get public form schema for rendering.
     *
     * @param array<string, mixed> $args
     */
    public function get(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $pluginError = $this->requirePlugin();
        if ($pluginError !== null) {
            return $pluginError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $form = $this->contactFormPlugin->getForm($handle);

        if ($form === null || ($form['status'] ?? '') !== 'published') {
            return $this->problem(404, 'Not Found', "Form '{$handle}' is not published or not found.");
        }

        $settings = (array) ($form['settings'] ?? []);

        // Filter out sensitive settings (e.g. notify_emails) from public response
        $publicData = [
            'handle' => $form['handle'],
            'title' => $form['title'],
            'description' => $form['description'],
            'fields' => $form['fields'],
            'submit_label' => (string) ($settings['submit_label'] ?? 'Submit'),
            'success_message' => (string) ($settings['success_message'] ?? 'Thank you for reaching out!'),
        ];

        return $this->json(['data' => $publicData], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
        ]);
    }

    /**
     * Submit a public form inquiry.
     *
     * @param array<string, mixed> $args
     */
    public function submit(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $pluginError = $this->requirePlugin();
        if ($pluginError !== null) {
            return $pluginError;
        }

        $handle = (string) ($args['handle'] ?? $request->getAttribute('handle', ''));
        $body = $this->getJsonBody($request);

        // Capture client IP safely (ignoring spoofed headers unless from trusted proxy) and User-Agent
        $serverParams = $request->getServerParams();
        if ($this->rateLimiter !== null) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }
            $ip = $this->rateLimiter->resolveClientIp($serverParams, $headers);
        } else {
            $ip = (string) ($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
        }
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $res = $this->contactFormPlugin->submitForm($handle, $body, $ip, $userAgent);
            $corsHeaders = [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
            ];

            if (!($res['success'] ?? false)) {
                return $this->json($res, 422, $corsHeaders);
            }

            return $this->json($res, 201, $corsHeaders);
        } catch (\RuntimeException $e) {
            return $this->problem(404, 'Not Found', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logServerError($request, $e, 'public_form_submit_error');
            return $this->problem(500, 'Internal Server Error', 'An error occurred while submitting your message.');
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
