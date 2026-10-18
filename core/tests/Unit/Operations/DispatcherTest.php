<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Operations;

use BsCms\Audit\AuditLogger;
use BsCms\Auth\Actor;
use BsCms\Database\Connection;
use BsCms\Operations\ConfirmTokenService;
use BsCms\Operations\Context;
use BsCms\Operations\Dispatcher;
use BsCms\Operations\Exceptions\ConfirmationRequiredException;
use BsCms\Operations\Exceptions\ForbiddenException;
use BsCms\Operations\Exceptions\OperationException;
use BsCms\Operations\Exceptions\StepUpRequiredException;
use BsCms\Operations\Exceptions\ValidationException;
use BsCms\Operations\Operation;
use BsCms\Operations\OperationMeta;
use BsCms\Operations\Result;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DispatcherTest extends TestCase
{
    private ConfirmTokenService $confirmTokens;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmTokens = new ConfirmTokenService('base64:test-secret-key-12345678901234567890123456789012');
        $this->dispatcher = new Dispatcher($this->confirmTokens);
    }

    public function testSuccessfulExecution(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'test.simple';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta('Simple operation', ['type' => 'object', 'required' => ['name']]);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok(['greeting' => 'Hello ' . $input['name']]);
            }
        };

        $this->dispatcher->register('test.simple', $op);
        $ctx = new Context(Actor::user('u1', 'User 1'), 'req-123');

        $result = $this->dispatcher->run('test.simple', ['name' => 'Alice'], $ctx);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame(['greeting' => 'Hello Alice'], $result->data);
    }

    public function testValidationFailureThrowsValidationException(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'test.validate';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta('Validation test', [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'minLength' => 5],
                    ],
                ]);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok();
            }
        };

        $this->dispatcher->register('test.validate', $op);
        $ctx = new Context(Actor::user('u1'), 'req-123');

        $this->expectException(ValidationException::class);
        $this->dispatcher->run('test.validate', [], $ctx);
    }

    public function testAuthorizationFailureThrowsForbiddenException(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'test.forbidden';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta('Forbidden test');
            }
            public function authorize(Context $ctx, array $input): void
            {
                throw new ForbiddenException('Not allowed here.');
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok();
            }
        };

        $this->dispatcher->register('test.forbidden', $op);
        $ctx = new Context(Actor::user('u1'), 'req-123');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Not allowed here.');
        $this->dispatcher->run('test.forbidden', [], $ctx);
    }

    public function testAiModeDraftOnlyBlockedFromExecutingBlockedPolicy(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'entries.publish';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(
                    summary: 'Publish entry',
                    mutates: true,
                    aiPolicy: OperationMeta::AI_POLICY_DRAFT_ONLY_BLOCKED
                );
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok();
            }
        };

        $this->dispatcher->register('entries.publish', $op);
        $aiActor = Actor::apiToken('tok-1', 'AI Token', 'user-1', [], Actor::AI_MODE_DRAFT_ONLY);
        $ctx = new Context($aiActor, 'req-123');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage("API token with 'draft_only' mode is not permitted to execute 'entries.publish'.");
        $this->dispatcher->run('entries.publish', [], $ctx);
    }

    public function testAiModeDraftOnlyAllowedForAllowedPolicy(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'entries.create_draft';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(
                    summary: 'Create draft',
                    mutates: true,
                    aiPolicy: OperationMeta::AI_POLICY_ALLOWED
                );
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::created(['id' => '01JDRAFT']);
            }
        };

        $this->dispatcher->register('entries.create_draft', $op);
        $aiActor = Actor::apiToken('tok-1', 'AI Token', 'user-1', [], Actor::AI_MODE_DRAFT_ONLY);
        $ctx = new Context($aiActor, 'req-123');

        $result = $this->dispatcher->run('entries.create_draft', [], $ctx);
        $this->assertSame(201, $result->statusCode);
    }

    public function testStepUpEnforcementThrowsWhenNotVerified(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'schema.apply';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(summary: 'Apply schema', requiresStepUp: true);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok();
            }
        };

        $this->dispatcher->register('schema.apply', $op);
        $this->dispatcher->setStepUpChecker(fn (Context $ctx): bool => false);

        $ctx = new Context(Actor::user('u1'), 'req-123');

        $this->expectException(StepUpRequiredException::class);
        $this->dispatcher->run('schema.apply', [], $ctx);
    }

    public function testStepUpEnforcementSucceedsWhenVerified(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'schema.apply';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(summary: 'Apply schema', requiresStepUp: true);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok(['applied' => true]);
            }
        };

        $this->dispatcher->register('schema.apply', $op);
        $this->dispatcher->setStepUpChecker(fn (Context $ctx): bool => true);

        $ctx = new Context(Actor::user('u1'), 'req-123');

        $result = $this->dispatcher->run('schema.apply', [], $ctx);
        $this->assertSame(['applied' => true], $result->data);
    }

    public function testDestructiveOperationRequiresConfirmation(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'entries.delete_permanent';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(summary: 'Permanent delete', destructive: true);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok(['purged' => true]);
            }
        };

        $this->dispatcher->register('entries.delete_permanent', $op);
        $actor = Actor::user('u1');
        $ctx = new Context($actor, 'req-123');
        $input = ['id' => '01JENTRY'];

        try {
            $this->dispatcher->run('entries.delete_permanent', $input, $ctx);
            $this->fail('Expected ConfirmationRequiredException');
        } catch (ConfirmationRequiredException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertNotEmpty($e->confirmToken);
            $token = $e->confirmToken;
        }

        // Repeat with confirmToken -> succeeds
        $ctxWithToken = $ctx->withConfirmToken($token);
        $res = $this->dispatcher->run('entries.delete_permanent', $input, $ctxWithToken);
        $this->assertSame(['purged' => true], $res->data);
    }

    public function testBulkOperationOverThresholdRequiresConfirmation(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'entries.bulk_trash';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta(summary: 'Bulk trash', bulk: true);
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::ok(['trashed' => count($input['ids'])]);
            }
        };

        $this->dispatcher->register('entries.bulk_trash', $op);
        $actor = Actor::user('u1');
        $ctx = new Context($actor, 'req-123');

        // 10 items -> no confirmation required
        $input10 = ['ids' => range(1, 10)];
        $res10 = $this->dispatcher->run('entries.bulk_trash', $input10, $ctx);
        $this->assertSame(['trashed' => 10], $res10->data);

        // 11 items -> confirmation required
        $input11 = ['ids' => range(1, 11)];
        try {
            $this->dispatcher->run('entries.bulk_trash', $input11, $ctx);
            $this->fail('Expected ConfirmationRequiredException for bulk > 10');
        } catch (ConfirmationRequiredException $e) {
            $this->assertNotEmpty($e->confirmToken);
            $token = $e->confirmToken;
        }

        $res11 = $this->dispatcher->run('entries.bulk_trash', $input11, $ctx->withConfirmToken($token));
        $this->assertSame(['trashed' => 11], $res11->data);
    }

    public function testDomainEventsDispatched(): void
    {
        $op = new class () implements Operation {
            public static function name(): string
            {
                return 'entries.create';
            }
            public static function describe(): OperationMeta
            {
                return new OperationMeta('Create entry');
            }
            public function authorize(Context $ctx, array $input): void
            {
            }
            public function handle(Context $ctx, array $input): Result
            {
                return Result::created(['id' => '01J1'], [], [['event' => 'entry.created', 'id' => '01J1']]);
            }
        };

        $dispatchedEvents = [];
        $this->dispatcher->register('entries.create', $op);
        $this->dispatcher->setEventDispatcher(function (array $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $event;
        });

        $ctx = new Context(Actor::user('u1'), 'req-1');
        $this->dispatcher->run('entries.create', [], $ctx);

        $this->assertCount(1, $dispatchedEvents);
        $this->assertSame('entry.created', $dispatchedEvents[0]['event']);
    }
}
