<?php

declare(strict_types=1);

namespace BsCms\Taxonomy\Jobs;

use BsCms\Database\Connection;
use BsCms\Jobs\Job;
use BsCms\Taxonomy\TermRepository;

/**
 * Background / Nightly integrity job to recompute entry_count on terms.
 */
final class RecomputeTaxonomyCountsJob implements Job
{
    public function __construct(
        private readonly Connection $db
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $taxonomyId = isset($payload['taxonomy_id']) && is_string($payload['taxonomy_id']) ? $payload['taxonomy_id'] : null;
        $repo = new TermRepository($this->db);
        $repo->recomputeCounts($taxonomyId);
    }
}
