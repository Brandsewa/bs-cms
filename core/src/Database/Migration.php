<?php

declare(strict_types=1);

namespace BsCms\Database;

/**
 * Migration base class.
 */
abstract class Migration
{
    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Run migration forward.
     */
    abstract public function up(): void;

    /**
     * Revert migration.
     */
    abstract public function down(): void;
}
