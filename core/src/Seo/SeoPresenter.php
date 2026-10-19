<?php

declare(strict_types=1);

namespace BsCms\Seo;

final class SeoPresenter
{
    /**
     * Canonical key set for seo_resolved.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     title: string,
     *     description: string,
     *     canonical: string,
     *     robots: array{
     *         noindex: bool,
     *         nofollow: bool,
     *         nosnippet: bool,
     *         noarchive: bool,
     *         max_snippet: ?int,
     *         max_image_preview: string
     *     },
     *     open_graph: array{
     *         title: string,
     *         description: string,
     *         type: string,
     *         url: string,
     *         image: ?string,
     *         site_name: string
     *     },
     *     twitter: array{
     *         card: string,
     *         title: string,
     *         description: string,
     *         image: ?string
     *     }
     * }
     */
    public static function present(array $data): array
    {
        $robots = (array) ($data['robots'] ?? []);
        $og = (array) ($data['open_graph'] ?? []);
        $twitter = (array) ($data['twitter'] ?? []);

        return [
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'canonical' => (string) ($data['canonical'] ?? ''),
            'robots' => [
                'noindex' => (bool) ($robots['noindex'] ?? false),
                'nofollow' => (bool) ($robots['nofollow'] ?? false),
                'nosnippet' => (bool) ($robots['nosnippet'] ?? false),
                'noarchive' => (bool) ($robots['noarchive'] ?? false),
                'max_snippet' => isset($robots['max_snippet']) ? (int) $robots['max_snippet'] : null,
                'max_image_preview' => (string) ($robots['max_image_preview'] ?? 'large'),
            ],
            'open_graph' => [
                'title' => (string) ($og['title'] ?? $data['title'] ?? ''),
                'description' => (string) ($og['description'] ?? $data['description'] ?? ''),
                'type' => (string) ($og['type'] ?? 'website'),
                'url' => (string) ($og['url'] ?? $data['canonical'] ?? ''),
                'image' => isset($og['image']) ? (string) $og['image'] : null,
                'site_name' => (string) ($og['site_name'] ?? ''),
            ],
            'twitter' => [
                'card' => (string) ($twitter['card'] ?? 'summary_large_image'),
                'title' => (string) ($twitter['title'] ?? $data['title'] ?? ''),
                'description' => (string) ($twitter['description'] ?? $data['description'] ?? ''),
                'image' => isset($twitter['image']) ? (string) $twitter['image'] : null,
            ],
        ];
    }
}
