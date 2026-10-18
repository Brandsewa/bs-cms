<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Operations\Exceptions\ForbiddenException;

/**
 * Standard contract for CMS capabilities.
 * REST controllers, admin operations, and MCP tools share this interface.
 */
interface Operation
{
    /**
     * Unique identifier for the operation, e.g. 'entries.create' or 'schema.plan'.
     */
    public static function name(): string;

    /**
     * Metadata describing input schema, capabilities, and enforcement flags.
     */
    public static function describe(): OperationMeta;

    /**
     * Authorize whether the context actor may perform this operation on the given input.
     *
     * @param array<string, mixed> $input
     * @throws ForbiddenException If the actor lacks required permissions
     */
    public function authorize(Context $ctx, array $input): void;

    /**
     * Execute the operation and return the structured result.
     *
     * @param array<string, mixed> $input
     */
    public function handle(Context $ctx, array $input): Result;
}
