<?php

declare(strict_types=1);

namespace BsCms\Operations;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Operations\Exceptions\ConfirmationRequiredException;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\OperationException;
use BsCms\Operations\Exceptions\StepUpRequiredException;
use BsCms\Operations\Exceptions\ValidationException;
use Closure;
use Throwable;

/**
 * Central Dispatcher through which all CMS mutations and actions flow.
 * Enforces JSON Schema validation -> Authorize -> AI Mode Guard -> Step-up ->
 * Two-Step Confirmation -> Database Transaction -> Handle -> Audit Log -> Events.
 */
final class Dispatcher
{
    /** @var array<string, Operation|Closure(Context): Operation> */
    private array $operations = [];

    /** @var (callable(Context): bool)|null */
    private $stepUpChecker = null;

    /** @var (callable(array<string, mixed>): void)|null */
    private $eventDispatcher = null;

    public function __construct(
        private readonly ConfirmTokenService $confirmTokens,
        private readonly JsonSchemaValidator $validator = new JsonSchemaValidator(),
        private readonly ?AuditLogger $auditLogger = null
    ) {
    }

    /**
     * Register an operation instance or factory.
     *
     * @param Operation|Closure(Context): Operation $operation
     */
    public function register(string $name, Operation|Closure $operation): void
    {
        $this->operations[$name] = $operation;
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    public function getOperation(string $name, ?Context $ctx = null): Operation
    {
        $dummyCtx = $ctx ?? new Context(null, 'describe');
        return $this->resolveOperation($name, $dummyCtx);
    }

    /**
     * @return array<string, Operation>
     */
    public function allOperations(?Context $ctx = null): array
    {
        $dummyCtx = $ctx ?? new Context(null, 'describe');
        $resolved = [];
        foreach (array_keys($this->operations) as $name) {
            $resolved[$name] = $this->resolveOperation($name, $dummyCtx);
        }
        return $resolved;
    }

    public function describe(string $name, ?Context $ctx = null): OperationMeta
    {
        $op = $this->getOperation($name, $ctx);
        return $op::describe();
    }

    /**
     * @return array<string, OperationMeta>
     */
    public function describeAll(?Context $ctx = null): array
    {
        $ops = $this->allOperations($ctx);
        $described = [];
        foreach ($ops as $name => $op) {
            $described[$name] = $op::describe();
        }
        return $described;
    }

    /**
     * @param callable(Context): bool $checker
     */
    public function setStepUpChecker(callable $checker): void
    {
        $this->stepUpChecker = $checker;
    }

    /**
     * @param callable(array<string, mixed>): void $dispatcher
     */
    public function setEventDispatcher(callable $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    /**
     * Execute an operation through the unified pipeline.
     *
     * @param array<string, mixed> $input
     * @throws OperationException
     * @throws ValidationException
     * @throws ForbiddenException
     * @throws StepUpRequiredException
     * @throws ConfirmationRequiredException
     */
    public function run(string $name, array $input, Context $ctx): Result
    {
        $operation = $this->resolveOperation($name, $ctx);
        $meta = $operation::describe();

        // 1. Input validation against JSON Schema
        if (!empty($meta->inputSchema)) {
            $errors = $this->validator->validate($input, $meta->inputSchema);
            if (!empty($errors)) {
                throw new ValidationException('Input validation failed.', $errors);
            }
        }

        // 2. Authorize
        $operation->authorize($ctx, $input);

        // 3. AI-mode enforcement: draft_only tokens blocked from mutating non-draft / destructive ops
        if ($ctx->actor !== null && $ctx->actor->aiMode === Actor::AI_MODE_DRAFT_ONLY) {
            if ($meta->aiPolicy === OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED) {
                throw new ForbiddenException("API token with 'draft_only' mode is not permitted to execute '{$name}'.");
            }
        }

        // 4. Step-up re-authentication check
        if ($meta->requiresStepUp) {
            $stepUpPassed = $this->stepUpChecker !== null ? ($this->stepUpChecker)($ctx) : false;
            if (!$stepUpPassed) {
                throw new StepUpRequiredException("Operation '{$name}' requires step-up re-authentication.");
            }
        }

        // 5. Two-step confirmation for destructive / bulk operations
        $isDestructive = $meta->destructive;
        $isBulk = $meta->bulk && isset($input['ids']) && is_array($input['ids']) && count($input['ids']) > 10;

        if ($isDestructive || $isBulk) {
            $actorId = $ctx->actor !== null ? $ctx->actor->id : 'anonymous';
            if ($ctx->confirmToken === null || trim($ctx->confirmToken) === '') {
                $confirmToken = $this->confirmTokens->generate($actorId, $name, $input);
                throw new ConfirmationRequiredException(
                    message: "Destructive or bulk operation '{$name}' requires two-step confirmation.",
                    confirmToken: $confirmToken,
                    expiresIn: 300
                );
            }

            // Verify and consume the provided confirm token
            $this->confirmTokens->validateAndConsume($ctx->confirmToken, $actorId, $name, $input);
        }

        // 6. Transaction handling
        $connection = $ctx->connection;
        $shouldTransact = $meta->mutates && $connection !== null && !$ctx->dryRun;

        if ($shouldTransact) {
            $pdo = $connection->getPdo();
            $pdo->beginTransaction();
            try {
                $result = $operation->handle($ctx, $input);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } else {
            $result = $operation->handle($ctx, $input);
        }

        // 7. Audit logging
        if ($meta->mutates && $this->auditLogger !== null && $ctx->actor !== null) {
            try {
                $this->auditLogger->record(
                    actor: $ctx->actor,
                    action: $name,
                    subject: 'operation',
                    summary: $meta->summary,
                    diff: null,
                    result: 'success',
                    requestId: $ctx->requestId,
                    ip: $ctx->ip,
                    userAgent: $ctx->userAgent
                );
            } catch (Throwable) {
                // Non-fatal if audit log storage fails after transaction commit
            }
        }

        // 8. Domain events
        if ($this->eventDispatcher !== null && !empty($result->events)) {
            foreach ($result->events as $event) {
                ($this->eventDispatcher)($event);
            }
        }

        return $result;
    }

    private function resolveOperation(string $name, Context $ctx): Operation
    {
        if (!isset($this->operations[$name])) {
            throw new OperationException("Operation '{$name}' is not registered.");
        }

        $op = $this->operations[$name];
        if ($op instanceof Closure) {
            return $op($ctx);
        }

        return $op;
    }
}
