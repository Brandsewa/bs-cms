<?php

declare(strict_types=1);

namespace BsCms\Seo;

final class StructuredDataBuilder
{
    /**
     * Build Schema.org @graph JSON-LD structure.
     *
     * @param array{
     *     site_name?: string,
     *     site_url?: string,
     *     logo_url?: ?string,
     *     organization_name?: string,
     *     same_as?: list<string>
     * } $site
     * @param array{
     *     type?: string,
     *     title: string,
     *     description?: string,
     *     canonical: string,
     *     published_at?: ?string,
     *     updated_at?: ?string,
     *     author_name?: ?string,
     *     author_url?: ?string,
     *     image_url?: ?string,
     *     breadcrumbs?: list<array{name: string, url: string}>,
     *     faq?: list<array{question: string, answer: string}>
     * } $entity
     * @return array{
     *     '@context': string,
     *     '@graph': list<array<string, mixed>>
     * }
     */
    public static function build(array $site, array $entity): array
    {
        $siteUrl = rtrim((string) ($site['site_url'] ?? 'http://localhost'), '/');
        $siteName = (string) ($site['site_name'] ?? 'BS CMS');
        $orgName = (string) ($site['organization_name'] ?? $siteName);
        $canonical = $entity['canonical'];
        $type = (string) ($entity['type'] ?? 'website');

        $graph = [];

        // 1. Organization
        $orgId = "{$siteUrl}/#organization";
        $orgNode = [
            '@type' => 'Organization',
            '@id' => $orgId,
            'name' => $orgName,
            'url' => $siteUrl,
        ];
        if (!empty($site['logo_url'])) {
            $orgNode['logo'] = [
                '@type' => 'ImageObject',
                '@id' => "{$siteUrl}/#logo",
                'url' => $site['logo_url'],
            ];
        }
        if (!empty($site['same_as'])) {
            $orgNode['sameAs'] = $site['same_as'];
        }
        $graph[] = $orgNode;

        // 2. WebSite
        $webSiteId = "{$siteUrl}/#website";
        $graph[] = [
            '@type' => 'WebSite',
            '@id' => $webSiteId,
            'url' => $siteUrl,
            'name' => $siteName,
            'publisher' => ['@id' => $orgId],
        ];

        // 3. Breadcrumbs
        $breadcrumbId = "{$canonical}/#breadcrumb";
        $breadcrumbs = $entity['breadcrumbs'] ?? [];
        if (empty($breadcrumbs)) {
            $breadcrumbs = [
                ['name' => 'Home', 'url' => $siteUrl . '/'],
                ['name' => $entity['title'], 'url' => $canonical],
            ];
        }

        $itemList = [];
        $pos = 1;
        foreach ($breadcrumbs as $b) {
            $itemList[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => (string) $b['name'],
                'item' => (string) $b['url'],
            ];
        }

        $graph[] = [
            '@type' => 'BreadcrumbList',
            '@id' => $breadcrumbId,
            'itemListElement' => $itemList,
        ];

        // 4. WebPage or CollectionPage
        $pageId = "{$canonical}/#webpage";
        $pageType = ($type === 'taxonomy' || $type === 'archive') ? 'CollectionPage' : 'WebPage';
        $pageNode = [
            '@type' => $pageType,
            '@id' => $pageId,
            'url' => $canonical,
            'name' => $entity['title'],
            'isPartOf' => ['@id' => $webSiteId],
            'breadcrumb' => ['@id' => $breadcrumbId],
        ];
        if (!empty($entity['description'])) {
            $pageNode['description'] = $entity['description'];
        }
        $graph[] = $pageNode;

        // 5. BlogPosting / Article for blog posts
        if ($type === 'post' || $type === 'article' || $type === 'blog') {
            $articleNode = [
                '@type' => 'BlogPosting',
                '@id' => "{$canonical}/#article",
                'isPartOf' => ['@id' => $webSiteId],
                'mainEntityOfPage' => ['@id' => $pageId],
                'headline' => $entity['title'],
                'datePublished' => (string) ($entity['published_at'] ?? $entity['updated_at'] ?? ''),
                'dateModified' => (string) ($entity['updated_at'] ?? $entity['published_at'] ?? ''),
                'publisher' => ['@id' => $orgId],
            ];
            if (!empty($entity['description'])) {
                $articleNode['description'] = $entity['description'];
            }
            if (!empty($entity['image_url'])) {
                $articleNode['image'] = [$entity['image_url']];
            }
            if (!empty($entity['author_name'])) {
                $articleNode['author'] = [
                    '@type' => 'Person',
                    'name' => $entity['author_name'],
                ];
                if (!empty($entity['author_url'])) {
                    $articleNode['author']['url'] = $entity['author_url'];
                }
            }
            $graph[] = $articleNode;
        }

        // 6. FAQPage if FAQ items present
        if (!empty($entity['faq'])) {
            $questions = [];
            foreach ($entity['faq'] as $faqItem) {
                $q = trim($faqItem['question']);
                $a = trim($faqItem['answer']);
                if ($q !== '' && $a !== '') {
                    $questions[] = [
                        '@type' => 'Question',
                        'name' => $q,
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $a,
                        ],
                    ];
                }
            }

            if (!empty($questions)) {
                $graph[] = [
                    '@type' => 'FAQPage',
                    '@id' => "{$canonical}/#faq",
                    'mainEntity' => $questions,
                ];
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }
}
