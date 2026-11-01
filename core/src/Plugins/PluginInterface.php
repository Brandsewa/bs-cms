<?php

declare(strict_types=1);

namespace BsCms\Plugins;

interface PluginInterface
{
    public function getHandle(): string;

    public function getName(): string;

    public function getVersion(): string;

    public function getDescription(): string;

    public function getAuthor(): string;

    public function getCategory(): string;

    public function getIcon(): string;

    /**
     * @return array<string, mixed>
     */
    public function getDefaultConfig(): array;

    public function boot(): void;
}
