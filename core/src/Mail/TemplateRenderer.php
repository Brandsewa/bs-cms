<?php

declare(strict_types=1);

namespace BsCms\Mail;

use RuntimeException;

/**
 * Plain PHP email template renderer producing paired responsive HTML and text outputs.
 */
final class TemplateRenderer
{
    private string $templateDir;
    /** @var array<string, mixed> */
    private array $translations = [];

    public function __construct(?string $templateDir = null, ?string $langFile = null)
    {
        $this->templateDir = $templateDir ?? __DIR__ . '/Templates';
        $langPath = $langFile ?? dirname(__DIR__, 2) . '/resources/lang/en.json';

        if (is_file($langPath)) {
            $json = file_get_contents($langPath);
            if ($json !== false) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($json, true) ?? [];
                $this->translations = $decoded;
            }
        }
    }

    /**
     * Render an email template to HTML and plain text.
     *
     * @param array<string, mixed> $data
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $templateName, array $data = []): array
    {
        $file = $this->templateDir . '/' . $templateName . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Email template '{$templateName}' not found in '{$this->templateDir}'.");
        }

        /** @var array<string, mixed> $strings */
        $strings = $this->translations['mail'][$templateName] ?? [];

        /** @var array{subject: string, html: string, text: string} $result */
        $result = require $file;

        return $result;
    }
}
