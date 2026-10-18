<?php

declare(strict_types=1);

namespace BsCms\Migrations;

use BsCms\Database\Migration;

/**
 * Migration 0006: Create taxonomy tables (taxonomies, collection_taxonomies, terms, term_relationships).
 * Adheres strictly to docs/01-data-model.md Section 4.
 */
final class CreateTaxonomyTables extends Migration
{
    public function up(): void
    {
        $taxonomies = $this->connection->prefixTable('taxonomies');
        $collectionTaxonomies = $this->connection->prefixTable('collection_taxonomies');
        $collections = $this->connection->prefixTable('collections');
        $terms = $this->connection->prefixTable('terms');
        $termRelationships = $this->connection->prefixTable('term_relationships');

        $pdo = $this->connection->getPdo();

        // 1. taxonomies
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$taxonomies}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `handle` VARCHAR(64) NOT NULL,
            `label_singular` VARCHAR(120) NOT NULL,
            `label_plural` VARCHAR(120) NOT NULL,
            `description` VARCHAR(500) NULL,
            `hierarchical` TINYINT(1) NOT NULL DEFAULT 0,
            `max_depth` TINYINT NULL,
            `has_public_archive` TINYINT(1) NOT NULL DEFAULT 1,
            `archive_route_pattern` VARCHAR(255) NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `plugin_key` VARCHAR(64) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            UNIQUE KEY `uq_taxonomies_key` (`handle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. collection_taxonomies
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$collectionTaxonomies}` (
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `taxonomy_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `required` TINYINT(1) NOT NULL DEFAULT 0,
            `allow_multiple` TINYINT(1) NOT NULL DEFAULT 1,
            `field_key` VARCHAR(64) NOT NULL,
            `position` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`collection_id`, `taxonomy_id`),
            KEY `ix_coltax_tax` (`taxonomy_id`),
            CONSTRAINT `fk_coltax_col` FOREIGN KEY (`collection_id`) REFERENCES `{$collections}` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coltax_tax` FOREIGN KEY (`taxonomy_id`) REFERENCES `{$taxonomies}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. terms
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$terms}` (
            `id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
            `taxonomy_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `parent_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `name` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL,
            `description` TEXT NULL,
            `path` VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `slug_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `depth` TINYINT NOT NULL DEFAULT 0,
            `position` INT NOT NULL DEFAULT 0,
            `image_media_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL,
            `color` VARCHAR(16) NULL,
            `icon` VARCHAR(64) NULL,
            `data` JSON NULL,
            `seo` JSON NULL,
            `entry_count` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            `parent_key` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            UNIQUE KEY `uq_terms_slug` (`taxonomy_id`, `parent_key`, `slug`),
            KEY `ix_terms_path` (`taxonomy_id`, `path`(191)),
            KEY `ix_terms_parent` (`taxonomy_id`, `parent_id`, `position`),
            CONSTRAINT `fk_terms_tax` FOREIGN KEY (`taxonomy_id`) REFERENCES `{$taxonomies}` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_terms_parent` FOREIGN KEY (`parent_id`) REFERENCES `{$terms}` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. term_relationships
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$termRelationships}` (
            `collection_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `entry_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `term_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `position` INT NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_at` DATETIME(3) NULL,
            PRIMARY KEY (`collection_id`, `entry_id`, `term_id`),
            KEY `ix_termrel_term` (`term_id`, `collection_id`),
            KEY `ix_termrel_sorted` (`term_id`, `collection_id`, `is_published`, `sort_at`, `entry_id`),
            CONSTRAINT `fk_termrel_term` FOREIGN KEY (`term_id`) REFERENCES `{$terms}` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    public function down(): void
    {
        $taxonomies = $this->connection->prefixTable('taxonomies');
        $collectionTaxonomies = $this->connection->prefixTable('collection_taxonomies');
        $terms = $this->connection->prefixTable('terms');
        $termRelationships = $this->connection->prefixTable('term_relationships');

        $pdo = $this->connection->getPdo();

        $pdo->exec("DROP TABLE IF EXISTS `{$termRelationships}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$terms}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$collectionTaxonomies}`;");
        $pdo->exec("DROP TABLE IF EXISTS `{$taxonomies}`;");
    }
}
