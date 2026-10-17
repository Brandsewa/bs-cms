<?php

declare(strict_types=1);

/**
 * BS CMS — Spike A: Storage Model Benchmark
 * Tests table-per-collection + JSON data + generated virtual columns on MySQL 8 / MariaDB 10.6+.
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'bscms_test';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$rowCount = (int)($argv[1] ?? 50000);

echo "=== BS CMS Storage Model Benchmark ===\n";
echo "Host: {$host}:{$port}, Database: {$dbName}, Target rows: {$rowCount}\n";

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
    echo "Please set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS environment variables.\n";
    exit(1);
}

$serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
echo "Server version: {$serverVersion}\n";
$isMariaDB = stripos($serverVersion, 'MariaDB') !== false;
echo "Engine detected: " . ($isMariaDB ? "MariaDB" : "MySQL") . "\n\n";

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

echo "1. Creating schema tables (bs_c_posts, bs_taxonomies, bs_terms, bs_term_relationships, bs_entry_relations)...\n";

$pdo->exec("DROP TABLE IF EXISTS bs_term_relationships");
$pdo->exec("DROP TABLE IF EXISTS bs_entry_relations");
$pdo->exec("DROP TABLE IF EXISTS bs_terms");
$pdo->exec("DROP TABLE IF EXISTS bs_taxonomies");
$pdo->exec("DROP TABLE IF EXISTS bs_c_posts");

// Create bs_c_posts with virtual generated column using portable JSON_UNQUOTE(JSON_EXTRACT(...))
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
CREATE TABLE bs_terms (
  id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  taxonomy_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  path VARCHAR(512) NOT NULL
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
  KEY ix_termrel_term (term_id, collection_id)
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

echo "Tables created successfully.\n\n";

// Insert 20 sample categories
echo "2. Seeding 20 taxonomy categories...\n";
$termIds = [];
$termStmt = $pdo->prepare("INSERT INTO bs_terms (id, taxonomy_id, name, slug, path) VALUES (?, 'TAXONOMY_CATEGORIES', ?, ?, ?)");
for ($i = 1; $i <= 20; $i++) {
    $tId = generateUlid();
    $termIds[] = $tId;
    $termStmt->execute([$tId, "Category {$i}", "category-{$i}", "/{$tId}/"]);
}

echo "3. Inserting {$rowCount} realistic posts (batch size: 1000)...\n";
$collectionId = 'COLLECTION_POSTS';
$authorIds = [generateUlid(), generateUlid(), generateUlid(), generateUlid(), generateUlid()];
$statuses = ['published', 'published', 'published', 'draft', 'scheduled', 'archived'];

$insertSql = "
INSERT INTO bs_c_posts 
(id, slug, title, status, locale, author_id, published_at, seo, data, created_at, updated_at) 
VALUES (?, ?, ?, ?, 'en', ?, ?, ?, ?, ?, ?)
";
$stmt = $pdo->prepare($insertSql);
$termRelStmt = $pdo->prepare("INSERT INTO bs_term_relationships (collection_id, entry_id, term_id, is_primary) VALUES (?, ?, ?, 1)");

$paragraph = "This is a realistic paragraph of blog post content designed to occupy storage space in JSON data format. It includes formatting, block structure, and semantic elements like citations, key takeaways, and descriptive sections suitable for search engines and AI crawlers.";
$blocks = [];
for ($b = 0; $b < 8; $b++) {
    $blocks[] = [
        'type' => 'paragraph',
        'content' => $paragraph . " Block iteration {$b}.",
    ];
}

$startTime = microtime(true);
$pdo->beginTransaction();

$createdIds = [];
for ($i = 1; $i <= $rowCount; $i++) {
    $id = generateUlid();
    if ($i <= 1000) {
        $createdIds[] = $id;
    }
    $status = $statuses[$i % count($statuses)];
    $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s', time() - ($i * 60)) : null;
    $authorId = $authorIds[$i % count($authorIds)];
    $readingTime = ($i % 15) + 1;
    $customField = "Custom property value for item " . ($i % 100);

    $jsonData = json_encode([
        'body' => $blocks,
        'reading_time' => $readingTime,
        'custom_metric' => $customField,
        'view_count' => $i * 3,
        'rating' => ($i % 5) + 1,
    ], JSON_THROW_ON_ERROR);

    $seoData = json_encode([
        'title' => "Post {$i} SEO Title",
        'description' => "Post {$i} SEO Meta Description summarizing the blog entry.",
        'canonical' => null,
    ], JSON_THROW_ON_ERROR);

    $now = date('Y-m-d H:i:s');
    $stmt->execute([
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

    // Attach to category
    $termRelStmt->execute([$collectionId, $id, $termIds[$i % 20]]);

    if ($i % 1000 === 0) {
        $pdo->commit();
        echo "  Inserted {$i} / {$rowCount} rows...\n";
        $pdo->beginTransaction();
    }
}
$pdo->commit();
$insertDuration = microtime(true) - $startTime;
$rps = round($rowCount / $insertDuration, 2);
echo "Insertion finished in " . round($insertDuration, 3) . "s ({$rps} rows/sec).\n\n";

// Insert some relations for the first 100 posts
echo "4. Populating sample entry relations...\n";
$relStmt = $pdo->prepare("INSERT INTO bs_entry_relations (from_collection_id, from_entry_id, field_key, to_collection_id, to_entry_id) VALUES (?, ?, 'related_posts', ?, ?)");
for ($i = 0; $i < 100; $i++) {
    $relStmt->execute([$collectionId, $createdIds[$i], $collectionId, $createdIds[($i + 1) % 100]]);
    $relStmt->execute([$collectionId, $createdIds[$i], $collectionId, $createdIds[($i + 2) % 100]]);
}
echo "Sample relations populated.\n\n";

echo "=== BENCHMARK QUERIES ===\n";

// Query 1: List query with status filter + sort by published_at, offset pagination (page size 25)
$q1Start = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $q = $pdo->query("SELECT id, slug, title, status, published_at FROM bs_c_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 25 OFFSET 100");
    $q->fetchAll();
}
$q1Time = ((microtime(true) - $q1Start) / 10) * 1000;
echo "1. List query (offset 100, page 25): " . round($q1Time, 2) . " ms (Target: < 50 ms)\n";

// Query 2: Cursor-based pagination on published_at
$q2Start = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $q = $pdo->query("SELECT id, slug, title, status, published_at FROM bs_c_posts WHERE status = 'published' AND published_at < '2026-01-01 00:00:00' ORDER BY published_at DESC LIMIT 25");
    $q->fetchAll();
}
$q2Time = ((microtime(true) - $q2Start) / 10) * 1000;
echo "2. Cursor list query: " . round($q2Time, 2) . " ms (Target: < 50 ms)\n";

// Query 3: Filter on indexed generated column (reading_time)
$q3Start = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $q = $pdo->query("SELECT id, title, reading_time FROM bs_c_posts WHERE status = 'published' AND reading_time = 7 LIMIT 25");
    $q->fetchAll();
}
$q3Time = ((microtime(true) - $q3Start) / 10) * 1000;
echo "3. Filter on indexed generated virtual column (reading_time = 7): " . round($q3Time, 2) . " ms (Target: < 50 ms)\n";

// Query 4: Filter on non-indexed JSON path
$q4Start = microtime(true);
for ($run = 0; $run < 5; $run++) {
    $q = $pdo->query("SELECT id, title FROM bs_c_posts WHERE JSON_UNQUOTE(JSON_EXTRACT(data, '$.custom_metric')) = 'Custom property value for item 42' LIMIT 25");
    $q->fetchAll();
}
$q4Time = ((microtime(true) - $q4Start) / 5) * 1000;
echo "4. Filter on NON-INDEXED JSON path: " . round($q4Time, 2) . " ms (Note: table scan expected)\n";

// Query 5: Term filter via term_relationships join
$catId = $termIds[5];
$q5Start = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $q = $pdo->prepare("
        SELECT p.id, p.title, p.published_at 
        FROM bs_c_posts p
        INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id AND tr.collection_id = ?
        WHERE tr.term_id = ? AND p.status = 'published'
        ORDER BY p.published_at DESC LIMIT 25
    ");
    $q->execute([$collectionId, $catId]);
    $q->fetchAll();
}
$q5Time = ((microtime(true) - $q5Start) / 10) * 1000;
echo "5. Taxonomy term join filter: " . round($q5Time, 2) . " ms (Target: < 50 ms)\n";

// Query 6: Single entry fetch with 2 relations populated
$targetEntryId = $createdIds[10];
$q6Start = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $entryStmt = $pdo->prepare("SELECT * FROM bs_c_posts WHERE id = ?");
    $entryStmt->execute([$targetEntryId]);
    $entry = $entryStmt->fetch();

    $relStmt = $pdo->prepare("
        SELECT r.field_key, p.id, p.title, p.slug 
        FROM bs_entry_relations r
        INNER JOIN bs_c_posts p ON p.id = r.to_entry_id
        WHERE r.from_entry_id = ? AND r.from_collection_id = ?
    ");
    $relStmt->execute([$targetEntryId, $collectionId]);
    $relations = $relStmt->fetchAll();
}
$q6Time = ((microtime(true) - $q6Start) / 10) * 1000;
echo "6. Single entry fetch + 2 populated relations: " . round($q6Time, 2) . " ms\n";

// Query 7: Single row update latency
$updateStart = microtime(true);
for ($run = 0; $run < 10; $run++) {
    $upStmt = $pdo->prepare("UPDATE bs_c_posts SET title = ?, updated_at = NOW() WHERE id = ?");
    $upStmt->execute(["Updated Title Run {$run}", $createdIds[$run]]);
}
$updateTime = ((microtime(true) - $updateStart) / 10) * 1000;
echo "7. Single row update latency: " . round($updateTime, 2) . " ms\n";

// 8. ALTER TABLE ADD COLUMN ... GENERATED on 50k rows
echo "\n5. Testing ALTER TABLE ADD COLUMN GENERATED on {$rowCount} rows...\n";
$alterStart = microtime(true);
$pdo->exec("ALTER TABLE bs_c_posts ADD COLUMN view_count_gen INT GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.view_count')) AS SIGNED)) VIRTUAL, ADD KEY ix_posts_views (view_count_gen)");
$alterTime = (microtime(true) - $alterStart) * 1000;
echo "ALTER TABLE ADD VIRTUAL GENERATED COLUMN + KEY duration: " . round($alterTime, 2) . " ms (" . round($alterTime / 1000, 3) . " s)\n";

echo "\nBenchmark completed successfully!\n";
