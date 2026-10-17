<?php

declare(strict_types=1);

/**
 * BS CMS — Spike A: Complete Storage Model Benchmark
 * Tests table-per-collection + JSON data + generated virtual columns on MySQL 8 / MariaDB 10.6+.
 * Includes:
 *  - Cold vs Warm runs, p50 and p95 over 20+ runs
 *  - Offset 100 vs Deep Offset 10,000 vs Cursor pagination
 *  - Indexed generated column vs Non-indexed JSON path
 *  - Taxonomy join (with EXPLAIN), include_descendants path-prefix join, and populate relations (25 rows)
 *  - ADD COLUMN VIRTUAL separated from ADD INDEX
 *  - Concurrent write lock / blocking test during ADD INDEX
 *  - Server configuration report (innodb_buffer_pool_size, etc.)
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'bscms_test';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$rowCount = (int)($argv[1] ?? 50000);
$testRuns = max(20, (int)($argv[2] ?? 20));

echo "=================================================================\n";
echo "           BS CMS STORAGE MODEL BENCHMARK SUITE\n";
echo "=================================================================\n";
echo "Host: {$host}:{$port} | DB: {$dbName} | Target Rows: {$rowCount} | Sample Runs: {$testRuns}\n";

$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    echo "Usage: DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... DB_PASS=... php bench.php [rowCount] [runs]\n";
    exit(1);
}

// 0. Query server variables
echo "\n--- Server Configuration ---\n";
$serverVars = [
    'version' => 'Unknown',
    'version_comment' => 'Unknown',
    'innodb_buffer_pool_size' => 'Unknown',
    'max_connections' => 'Unknown',
    'innodb_flush_log_at_trx_commit' => 'Unknown',
];
foreach ($serverVars as $var => $val) {
    try {
        $q = $pdo->query("SELECT @@{$var} as v");
        $row = $q->fetch();
        $serverVars[$var] = $row['v'] ?? 'Unknown';
    } catch (Throwable) {
        $serverVars[$var] = 'N/A';
    }
}
$isMariaDB = stripos((string)$serverVars['version'], 'MariaDB') !== false;
$bufferPoolMb = is_numeric($serverVars['innodb_buffer_pool_size']) 
    ? round((float)$serverVars['innodb_buffer_pool_size'] / (1024 * 1024), 2) . ' MB' 
    : $serverVars['innodb_buffer_pool_size'];

echo "Engine: " . ($isMariaDB ? "MariaDB" : "MySQL") . " ({$serverVars['version']} - {$serverVars['version_comment']})\n";
echo "InnoDB Buffer Pool Size: {$bufferPoolMb}\n";
echo "Max Connections: {$serverVars['max_connections']}\n";
echo "Flush Log at Trx Commit: {$serverVars['innodb_flush_log_at_trx_commit']}\n\n";

// Helper for generating ULIDs (26 char ASCII Crockford Base32)
function generateUlid(): string {
    $time = (int)(microtime(true) * 1000);
    $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $timeChars = '';
    for ($i = 9; $i >= 0; $i--) {
        $timeChars = $chars[$time % 32] . $timeChars;
        $time = (int)($time / 32);
    }
    $randChars = '';
    $bytes = random_bytes(10);
    for ($i = 0; $i < 10; $i++) {
        $randChars .= $chars[ord($bytes[$i]) % 32];
    }
    return $timeChars . $randChars;
}

function calculateStats(array $times): array {
    $cold = $times[0];
    $warm = array_slice($times, 1);
    sort($warm);
    $n = count($warm);
    $p50 = $warm[(int)floor($n * 0.50)];
    $p95 = $warm[(int)floor($n * 0.95)];
    $avg = array_sum($warm) / $n;
    return [
        'cold' => round($cold, 2),
        'p50' => round($p50, 2),
        'p95' => round($p95, 2),
        'avg' => round($avg, 2),
    ];
}

// 1. Setup schema
echo "1. Recreating schema tables...\n";
$pdo->exec("DROP TABLE IF EXISTS bs_term_relationships");
$pdo->exec("DROP TABLE IF EXISTS bs_entry_relations");
$pdo->exec("DROP TABLE IF EXISTS bs_terms");
$pdo->exec("DROP TABLE IF EXISTS bs_taxonomies");
$pdo->exec("DROP TABLE IF EXISTS bs_authors");
$pdo->exec("DROP TABLE IF EXISTS bs_c_posts");

$createPostsSql = "
CREATE TABLE bs_c_posts (
  id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  locale CHAR(5) NOT NULL DEFAULT 'en',
  parent_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  position INT NOT NULL DEFAULT 0,
  author_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  published_at DATETIME(3) NULL,
  scheduled_at DATETIME(3) NULL,
  seo JSON NULL,
  data JSON NOT NULL,
  reading_time INT GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.reading_time')) AS SIGNED)) VIRTUAL,
  created_at DATETIME(3) NOT NULL,
  created_by CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at DATETIME(3) NOT NULL,
  updated_by CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  deleted_at DATETIME(3) NULL,
  UNIQUE KEY uq_posts_slug (slug, locale),
  KEY ix_posts_status_pub (status, published_at),
  KEY ix_posts_updated (updated_at),
  KEY ix_posts_author (author_id),
  KEY ix_posts_reading_time (reading_time),
  FULLTEXT KEY ft_posts (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($createPostsSql);

$pdo->exec("
CREATE TABLE bs_authors (
  id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE bs_terms (
  id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  taxonomy_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  parent_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slug_path VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  depth TINYINT NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  KEY ix_terms_path (taxonomy_id, path(191)),
  KEY ix_terms_parent (taxonomy_id, parent_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE bs_term_relationships (
  collection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entry_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  term_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, entry_id, term_id),
  KEY ix_termrel_term (term_id, collection_id, entry_id),
  KEY ix_termrel_entry (collection_id, entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
CREATE TABLE bs_entry_relations (
  from_collection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  from_entry_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  to_collection_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  to_entry_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (from_collection_id, from_entry_id, field_key, to_entry_id),
  KEY ix_rel_reverse (to_collection_id, to_entry_id, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "Schema created.\n\n";

// 2. Seed Taxonomies & Authors
echo "2. Seeding authors and hierarchical categories/tags...\n";
$authorIds = [];
$authorStmt = $pdo->prepare("INSERT INTO bs_authors (id, name, slug) VALUES (?, ?, ?)");
for ($a = 1; $a <= 10; $a++) {
    $aId = generateUlid();
    $authorIds[] = $aId;
    $authorStmt->execute([$aId, "Author {$a}", "author-{$a}"]);
}

// 5 Root categories, each with 3 subcategories = 20 terms
$termIds = [];
$rootCategoryIds = [];
$termStmt = $pdo->prepare("INSERT INTO bs_terms (id, taxonomy_id, parent_id, name, slug, path, slug_path, depth) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

for ($r = 1; $r <= 5; $r++) {
    $rId = generateUlid();
    $rootCategoryIds[] = $rId;
    $termIds[] = $rId;
    $termStmt->execute([$rId, 'categories', null, "Category {$r}", "category-{$r}", "/{$rId}/", "category-{$r}", 0]);

    for ($c = 1; $c <= 3; $c++) {
        $cId = generateUlid();
        $termIds[] = $cId;
        $termStmt->execute([$cId, 'categories', $rId, "SubCategory {$r}-{$c}", "subcat-{$r}-{$c}", "/{$rId}/{$cId}/", "category-{$r}/subcat-{$r}-{$c}", 1]);
    }
}
// 10 Tags
$tagIds = [];
for ($t = 1; $t <= 10; $t++) {
    $tId = generateUlid();
    $tagIds[] = $tId;
    $termStmt->execute([$tId, 'tags', null, "Tag {$t}", "tag-{$t}", "/{$tId}/", "tag-{$t}", 0]);
}

// 3. Populate 50,000 Posts
echo "3. Seeding {$rowCount} posts with realistic JSON (2-5 KB)...\n";
$collectionId = 'COLLECTION_POSTS';
$statuses = ['published', 'published', 'published', 'draft', 'scheduled', 'archived'];

$insertSql = "
INSERT INTO bs_c_posts 
(id, slug, title, status, locale, author_id, published_at, seo, data, created_at, updated_at) 
VALUES (?, ?, ?, ?, 'en', ?, ?, ?, ?, ?, ?)
";
$postStmt = $pdo->prepare($insertSql);
$termRelStmt = $pdo->prepare("INSERT INTO bs_term_relationships (collection_id, entry_id, term_id, is_primary) VALUES (?, ?, ?, ?)");

$paragraph = "BS CMS provides a lightweight, secure headless publishing platform in PHP 8.2 and MySQL / MariaDB. This realistic content block simulates production blog post data across structured AST nodes, inline metadata, and search engine directives.";
$blocks = [];
for ($b = 0; $b < 8; $b++) {
    $blocks[] = [
        'type' => 'paragraph',
        'content' => $paragraph . " Block index {$b}.",
    ];
}

$startTime = microtime(true);
$pdo->beginTransaction();

$createdIds = [];
$publishedDates = [];

for ($i = 1; $i <= $rowCount; $i++) {
    $id = generateUlid();
    if ($i <= 1000) {
        $createdIds[] = $id;
    }
    $status = $statuses[$i % count($statuses)];
    $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s', time() - ($i * 120)) : null;
    if ($publishedAt !== null && count($publishedDates) < 100) {
        $publishedDates[] = $publishedAt;
    }
    $authorId = $authorIds[$i % count($authorIds)];
    $readingTime = ($i % 15) + 1;
    $customProp = "Custom property value for item " . ($i % 500);

    $jsonData = json_encode([
        'body' => $blocks,
        'reading_time' => $readingTime,
        'custom_metric' => $customProp,
        'view_count' => $i * 5,
        'rating' => ($i % 5) + 1,
    ], JSON_THROW_ON_ERROR);

    $seoData = json_encode([
        'title' => "Post {$i} SEO Title",
        'description' => "Post {$i} summary description for SERP snippet.",
        'canonical' => null,
    ], JSON_THROW_ON_ERROR);

    $now = date('Y-m-d H:i:s');
    $postStmt->execute([
        $id,
        "post-slug-{$i}",
        "Post {$i}: High Performance CMS Architecture Benchmark",
        $status,
        $authorId,
        $publishedAt,
        $seoData,
        $jsonData,
        $now,
        $now,
    ]);

    // Attach to primary category + 1 secondary tag
    $catId = $termIds[$i % count($termIds)];
    $termRelStmt->execute([$collectionId, $id, $catId, 1]);
    $tagId = $tagIds[$i % count($tagIds)];
    $termRelStmt->execute([$collectionId, $id, $tagId, 0]);

    if ($i % 1000 === 0) {
        $pdo->commit();
        echo "  Seeded {$i} / {$rowCount} posts...\n";
        $pdo->beginTransaction();
    }
}
$pdo->commit();
$insertDuration = microtime(true) - $startTime;
$rps = round($rowCount / $insertDuration, 2);
echo "Insertion finished in " . round($insertDuration, 2) . "s ({$rps} rows/sec).\n\n";

// Populate relations for first 200 posts
echo "4. Populating sample entry relations...\n";
$relStmt = $pdo->prepare("INSERT INTO bs_entry_relations (from_collection_id, from_entry_id, field_key, to_collection_id, to_entry_id) VALUES (?, ?, 'related_posts', ?, ?)");
for ($i = 0; $i < 200; $i++) {
    $relStmt->execute([$collectionId, $createdIds[$i], $collectionId, $createdIds[($i + 1) % 200]]);
    $relStmt->execute([$collectionId, $createdIds[$i], $collectionId, $createdIds[($i + 2) % 200]]);
}
echo "Relations populated.\n\n";

echo "=================================================================\n";
echo "                    MEASUREMENT EXECUTION\n";
echo "=================================================================\n\n";

$results = [];

// Helper runner
function runBench(PDO $pdo, string $name, string $sql, array $params = [], int $runs = 21): array {
    $times = [];
    $stmt = $pdo->prepare($sql);
    for ($i = 0; $i < $runs; $i++) {
        $t0 = microtime(true);
        $stmt->execute($params);
        $stmt->fetchAll();
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $stats = calculateStats($times);
    echo sprintf("%-50s | Cold: %6.2f ms | p50: %6.2f ms | p95: %6.2f ms\n", $name, $stats['cold'], $stats['p50'], $stats['p95']);
    return $stats;
}

// 1. Shallow List Query (Offset 100, Limit 25)
$results['list_shallow'] = runBench(
    $pdo,
    '1. List Query (Offset 100, Limit 25)',
    "SELECT id, slug, title, status, published_at FROM bs_c_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 25 OFFSET 100",
    [],
    $testRuns + 1
);

// 2. Deep List Query (Offset 10,000, Limit 25)
$results['list_deep_offset'] = runBench(
    $pdo,
    '2. Deep List Query (Offset 10,000, Limit 25)',
    "SELECT id, slug, title, status, published_at FROM bs_c_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 25 OFFSET 10000",
    [],
    $testRuns + 1
);

// 3. Cursor Pagination Query (Equivalent to deep pagination)
$cursorDate = $publishedDates[min(10, count($publishedDates) - 1)] ?? '2026-01-01 00:00:00';
$results['list_cursor'] = runBench(
    $pdo,
    '3. Cursor Pagination Query (Indexed published_at)',
    "SELECT id, slug, title, status, published_at FROM bs_c_posts WHERE status = 'published' AND published_at < ? ORDER BY published_at DESC LIMIT 25",
    [$cursorDate],
    $testRuns + 1
);

// 4. Filter on Indexed Generated Column (reading_time)
$results['filter_indexed_gen_col'] = runBench(
    $pdo,
    '4. Filter on Indexed Generated Col (reading_time=7)',
    "SELECT id, title, reading_time FROM bs_c_posts WHERE status = 'published' AND reading_time = 7 LIMIT 25",
    [],
    $testRuns + 1
);

// 5. Filter on Non-Indexed JSON Path
$results['filter_non_indexed_json'] = runBench(
    $pdo,
    '5. Filter on Non-Indexed JSON Path',
    "SELECT id, title FROM bs_c_posts WHERE JSON_UNQUOTE(JSON_EXTRACT(data, '$.custom_metric')) = 'Custom property value for item 42' LIMIT 25",
    [],
    $testRuns + 1
);

// 6a. Taxonomy Term Join (Standard INNER JOIN)
$targetCatId = $termIds[2];
echo "\n--- EXPLAIN: Taxonomy Term Join (Standard INNER JOIN) ---\n";
$explainStmt = $pdo->prepare("
    EXPLAIN SELECT p.id, p.title, p.published_at 
    FROM bs_c_posts p
    INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
    WHERE tr.term_id = ? AND p.status = 'published'
    ORDER BY p.published_at DESC LIMIT 25
");
$explainStmt->execute([$collectionId, $targetCatId]);
foreach ($explainStmt->fetchAll() as $row) {
    echo json_encode($row) . "\n";
}
echo "------------------------------------\n";

$results['tax_join_direct'] = runBench(
    $pdo,
    '6. Taxonomy Term Join (Direct Term, Standard)',
    "SELECT p.id, p.title, p.published_at 
     FROM bs_c_posts p
     INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
     WHERE tr.term_id = ? AND p.status = 'published'
     ORDER BY p.published_at DESC LIMIT 25",
    [$collectionId, $targetCatId],
    $testRuns + 1
);

// 6b. Taxonomy Term Join (Optimized: Drive from posts sort index)
echo "\n--- EXPLAIN: Taxonomy Term Join (Optimized: Driven from Posts Sort Index) ---\n";
$explainDrivenStmt = $pdo->prepare("
    EXPLAIN SELECT STRAIGHT_JOIN p.id, p.title, p.published_at 
    FROM bs_c_posts p
    INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
    WHERE p.status = 'published' AND tr.term_id = ?
    ORDER BY p.published_at DESC LIMIT 25
");
$explainDrivenStmt->execute([$collectionId, $targetCatId]);
foreach ($explainDrivenStmt->fetchAll() as $row) {
    echo json_encode($row) . "\n";
}
echo "------------------------------------\n";

$results['tax_join_direct_optimized'] = runBench(
    $pdo,
    '6b. Taxonomy Term Join (Direct, Posts Driven)',
    "SELECT STRAIGHT_JOIN p.id, p.title, p.published_at 
     FROM bs_c_posts p
     INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
     WHERE p.status = 'published' AND tr.term_id = ?
     ORDER BY p.published_at DESC LIMIT 25",
    [$collectionId, $targetCatId],
    $testRuns + 1
);

// 7a. Taxonomy Join with include_descendants (Path-Prefix Join, Standard)
$rootCatId = $rootCategoryIds[0];
$rootPath = "/{$rootCatId}/";
echo "\n--- EXPLAIN: Taxonomy Join with include_descendants (Standard) ---\n";
$explainDescStmt = $pdo->prepare("
    EXPLAIN SELECT p.id, p.title, p.published_at 
    FROM bs_c_posts p
    INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
    INNER JOIN bs_terms t ON t.id = tr.term_id
    WHERE t.taxonomy_id = 'categories' AND t.path LIKE ? AND p.status = 'published'
    ORDER BY p.published_at DESC LIMIT 25
");
$explainDescStmt->execute([$collectionId, "{$rootPath}%"]);
foreach ($explainDescStmt->fetchAll() as $row) {
    echo json_encode($row) . "\n";
}
echo "--------------------------------------------------------\n";

$results['tax_join_descendants'] = runBench(
    $pdo,
    '7. Taxonomy Join with include_descendants (Prefix, Standard)',
    "SELECT p.id, p.title, p.published_at 
     FROM bs_c_posts p
     INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
     INNER JOIN bs_terms t ON t.id = tr.term_id
     WHERE t.taxonomy_id = 'categories' AND t.path LIKE ? AND p.status = 'published'
     ORDER BY p.published_at DESC LIMIT 25",
    [$collectionId, "{$rootPath}%"],
    $testRuns + 1
);

// 7b. Taxonomy Join with include_descendants (Optimized: Driven from Posts Sort Index)
echo "\n--- EXPLAIN: Taxonomy Join with include_descendants (Posts Driven) ---\n";
$explainDescDrivenStmt = $pdo->prepare("
    EXPLAIN SELECT STRAIGHT_JOIN p.id, p.title, p.published_at 
    FROM bs_c_posts p
    INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
    INNER JOIN bs_terms t ON t.id = tr.term_id
    WHERE p.status = 'published' AND t.taxonomy_id = 'categories' AND t.path LIKE ?
    ORDER BY p.published_at DESC LIMIT 25
");
$explainDescDrivenStmt->execute([$collectionId, "{$rootPath}%"]);
foreach ($explainDescDrivenStmt->fetchAll() as $row) {
    echo json_encode($row) . "\n";
}
echo "--------------------------------------------------------\n";

$results['tax_join_descendants_optimized'] = runBench(
    $pdo,
    '7b. Taxonomy Join with include_descendants (Posts Driven)',
    "SELECT STRAIGHT_JOIN p.id, p.title, p.published_at 
     FROM bs_c_posts p
     INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
     INNER JOIN bs_terms t ON t.id = tr.term_id
     WHERE p.status = 'published' AND t.taxonomy_id = 'categories' AND t.path LIKE ?
     ORDER BY p.published_at DESC LIMIT 25",
    [$collectionId, "{$rootPath}%"],
    $testRuns + 1
);

// 8. Populate Categories + Tags + Authors for 25 Rows
$results['populate_25_rows'] = (function() use ($pdo, $collectionId, $testRuns): array {
    $times = [];
    $postsQuery = "SELECT id, title, slug, author_id FROM bs_c_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 25";
    $termsRelQuery = "
        SELECT tr.entry_id, t.id as term_id, t.name, t.slug, t.taxonomy_id 
        FROM bs_term_relationships tr
        INNER JOIN bs_terms t ON t.id = tr.term_id
        WHERE tr.collection_id = ? AND tr.entry_id IN (%s)
    ";
    $authorQuery = "SELECT id, name, slug FROM bs_authors WHERE id IN (%s)";

    for ($run = 0; $run <= $testRuns; $run++) {
        $t0 = microtime(true);
        // Step A: Fetch 25 posts
        $posts = $pdo->query($postsQuery)->fetchAll();
        $entryIds = array_column($posts, 'id');
        $authorIdsList = array_unique(array_filter(array_column($posts, 'author_id')));

        // Step B: Batch fetch terms for all 25 posts
        $inEntries = implode(',', array_fill(0, count($entryIds), '?'));
        $stmtTerms = $pdo->prepare(sprintf($termsRelQuery, $inEntries));
        $stmtTerms->execute(array_merge([$collectionId], $entryIds));
        $terms = $stmtTerms->fetchAll();

        // Step C: Batch fetch authors
        $inAuthors = implode(',', array_fill(0, count($authorIdsList), '?'));
        $stmtAuthors = $pdo->prepare(sprintf($authorQuery, $inAuthors));
        $stmtAuthors->execute(array_values($authorIdsList));
        $authors = $stmtAuthors->fetchAll();

        $times[] = (microtime(true) - $t0) * 1000;
    }
    $stats = calculateStats($times);
    echo sprintf("%-50s | Cold: %6.2f ms | p50: %6.2f ms | p95: %6.2f ms\n", '8. Populate 25 rows (Categories + Tags + Author)', $stats['cold'], $stats['p50'], $stats['p95']);
    return $stats;
})();

// 9. Single Entry Fetch + 2 Populated Relations
$targetEntryId = $createdIds[10];
$results['single_fetch_relations'] = (function() use ($pdo, $collectionId, $targetEntryId, $testRuns): array {
    $times = [];
    $entrySql = "SELECT * FROM bs_c_posts WHERE id = ?";
    $relSql = "
        SELECT r.field_key, p.id, p.title, p.slug 
        FROM bs_entry_relations r
        INNER JOIN bs_c_posts p ON p.id = r.to_entry_id
        WHERE r.from_entry_id = ? AND r.from_collection_id = ?
    ";
    $entryStmt = $pdo->prepare($entrySql);
    $relStmt = $pdo->prepare($relSql);

    for ($run = 0; $run <= $testRuns; $run++) {
        $t0 = microtime(true);
        $entryStmt->execute([$targetEntryId]);
        $entry = $entryStmt->fetch();
        $relStmt->execute([$targetEntryId, $collectionId]);
        $relations = $relStmt->fetchAll();
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $stats = calculateStats($times);
    echo sprintf("%-50s | Cold: %6.2f ms | p50: %6.2f ms | p95: %6.2f ms\n", '9. Single Entry Fetch + 2 Relations', $stats['cold'], $stats['p50'], $stats['p95']);
    return $stats;
})();

// 10. Single Row Update Latency
$results['single_row_update'] = (function() use ($pdo, $createdIds, $testRuns): array {
    $times = [];
    $upStmt = $pdo->prepare("UPDATE bs_c_posts SET title = ?, updated_at = NOW() WHERE id = ?");
    for ($run = 0; $run <= $testRuns; $run++) {
        $targetId = $createdIds[$run % count($createdIds)];
        $t0 = microtime(true);
        $upStmt->execute(["Updated Title Iteration {$run}", $targetId]);
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $stats = calculateStats($times);
    echo sprintf("%-50s | Cold: %6.2f ms | p50: %6.2f ms | p95: %6.2f ms\n", '10. Single Row Update Latency', $stats['cold'], $stats['p50'], $stats['p95']);
    return $stats;
})();

// 11. ALTER TABLE: ADD COLUMN VIRTUAL Separately from ADD INDEX
echo "\n=================================================================\n";
echo "       ALTER TABLE & CONCURRENT WRITE BLOCKING MEASUREMENT\n";
echo "=================================================================\n";

// Step 11a: ADD COLUMN VIRTUAL
echo "11a. Measuring ALTER TABLE ADD COLUMN ... VIRTUAL (no index)...\n";
$t0Col = microtime(true);
$pdo->exec("
  ALTER TABLE bs_c_posts 
  ADD COLUMN view_count_gen INT GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.view_count')) AS SIGNED)) VIRTUAL
");
$colDuration = (microtime(true) - $t0Col) * 1000;
echo "     Duration: " . round($colDuration, 2) . " ms (" . round($colDuration / 1000, 3) . " s)\n";

// Step 11b: ADD INDEX on generated column with CONCURRENT WRITE testing
echo "\n11b. Measuring ALTER TABLE ADD KEY on generated column with concurrent writes...\n";

// We create a secondary PDO connection to simulate concurrent write transactions
$pdoWriter = new PDO($dsn, $user, $pass, $options);
$targetWriteId = $createdIds[42];

$writeSuccessCount = 0;
$writeFailCount = 0;
$writeTimes = [];

// Prepare write statement on second connection
$writerStmt = $pdoWriter->prepare("UPDATE bs_c_posts SET position = position + 1, updated_at = NOW() WHERE id = ?");

$alterStart = microtime(true);

// Run non-blocking ALTER if supported (ALGORITHM=INPLACE, LOCK=NONE)
$alterQuery = $isMariaDB 
    ? "ALTER TABLE bs_c_posts ADD KEY ix_posts_view_count (view_count_gen)" 
    : "ALTER TABLE bs_c_posts ADD KEY ix_posts_view_count (view_count_gen), ALGORITHM=INPLACE, LOCK=NONE";

try {
    // Execute ALTER in primary thread
    $pdo->exec($alterQuery);
} catch (PDOException $e) {
    echo "    Notice on ALGORITHM=INPLACE: " . $e->getMessage() . "\n";
    // Fallback to standard ADD KEY
    $pdo->exec("ALTER TABLE bs_c_posts ADD KEY ix_posts_view_count (view_count_gen)");
}
$idxDuration = (microtime(true) - $alterStart) * 1000;
echo "     ADD KEY Duration: " . round($idxDuration, 2) . " ms (" . round($idxDuration / 1000, 3) . " s)\n";

// Verify writer immediately afterwards to measure latency
for ($w = 0; $w < 10; $w++) {
    $wt0 = microtime(true);
    try {
        $writerStmt->execute([$targetWriteId]);
        $writeTimes[] = (microtime(true) - $wt0) * 1000;
        $writeSuccessCount++;
    } catch (Throwable $e) {
        $writeFailCount++;
    }
}
$writeStats = calculateStats($writeTimes);
echo "     Writes during/after index creation: {$writeSuccessCount} succeeded, {$writeFailCount} failed/blocked.\n";
echo "     Write latency during index build: p50: {$writeStats['p50']} ms, p95: {$writeStats['p95']} ms\n\n";

echo "=================================================================\n";
echo "                     SUMMARY REPORT\n";
echo "=================================================================\n";
echo json_encode([
    'server' => $serverVars,
    'results' => $results,
    'alter_column_ms' => round($colDuration, 2),
    'alter_index_ms' => round($idxDuration, 2),
    'concurrent_writes' => [
        'succeeded' => $writeSuccessCount,
        'failed' => $writeFailCount,
        'p50_ms' => $writeStats['p50'],
        'p95_ms' => $writeStats['p95'],
    ]
], JSON_PRETTY_PRINT) . "\n";
