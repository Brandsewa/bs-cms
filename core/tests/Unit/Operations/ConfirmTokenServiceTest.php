<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Operations;

use BsCms\Operations\ConfirmTokenService;
use BsCms\Operations\Exceptions\OperationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for ConfirmTokenService covering Amendment 6 requirements:
 * HMAC-signed with the app key, bound to actor id + operation name + SHA-256 of the canonical input,
 * single-use (store used tokens until expiry), 5-minute expiry; test tamper, other-user, other-input, replay, expiry.
 */
final class ConfirmTokenServiceTest extends TestCase
{
    private string $appKey = 'base64:4e2c8a7f1b9d3e5a7c2e4f6a8b0d2e4f6a8b0d2e4f6a8b0d2e4f6a8b0d2e4f6a';
    private ConfirmTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConfirmTokenService($this->appKey);
    }

    public function testCanonicalJsonIsIndependentOfKeyOrder(): void
    {
        $input1 = ['title' => 'Hello', 'status' => 'draft', 'nested' => ['z' => 1, 'a' => 2]];
        $input2 = ['status' => 'draft', 'nested' => ['a' => 2, 'z' => 1], 'title' => 'Hello'];

        $this->assertSame(
            ConfirmTokenService::canonicalJson($input1),
            ConfirmTokenService::canonicalJson($input2)
        );
    }

    public function testValidTokenCanBeConsumed(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorId, $operation, $input);
        $this->assertNotEmpty($token);

        $consumed = $this->service->validateAndConsume($token, $actorId, $operation, $input);
        $this->assertTrue($consumed);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorId, $operation, $input);
        $tamperedToken = $token . 'tampered';

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token signature mismatch (tampered).');
        $this->service->validateAndConsume($tamperedToken, $actorId, $operation, $input);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorId, $operation, $input);
        [$encoded, $sig] = explode('.', $token);

        // Change one character in payload
        $tamperedPayload = 'A' . substr($encoded, 1);
        $tamperedToken = $tamperedPayload . '.' . $sig;

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token signature mismatch (tampered).');
        $this->service->validateAndConsume($tamperedToken, $actorId, $operation, $input);
    }

    public function testTokenIssuedToDifferentUserIsRejected(): void
    {
        $actorUserA = '01JACTOR00000000000000000A';
        $actorUserB = '01JACTOR00000000000000000B';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorUserA, $operation, $input);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token was issued to a different user.');
        $this->service->validateAndConsume($token, $actorUserB, $operation, $input);
    }

    public function testTokenWithDifferentOperationIsRejected(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $otherOp = 'entries.publish';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorId, $operation, $input);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token does not match operation.');
        $this->service->validateAndConsume($token, $actorId, $otherOp, $input);
    }

    public function testTokenWithDifferentInputIsRejected(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input1 = ['id' => '01JENTRY000000000000000001'];
        $input2 = ['id' => '01JENTRY000000000000000002'];

        $token = $this->service->generate($actorId, $operation, $input1);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token does not match operation input parameters.');
        $this->service->validateAndConsume($token, $actorId, $operation, $input2);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        // Generate token with negative TTL
        $token = $this->service->generate($actorId, $operation, $input, -10);

        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token has expired.');
        $this->service->validateAndConsume($token, $actorId, $operation, $input);
    }

    public function testReplayedTokenIsRejectedOnSecondUse(): void
    {
        $actorId = '01JACTOR000000000000000001';
        $operation = 'entries.delete_permanent';
        $input = ['id' => '01JENTRY000000000000000001'];

        $token = $this->service->generate($actorId, $operation, $input);

        // First use succeeds
        $this->assertTrue($this->service->validateAndConsume($token, $actorId, $operation, $input));

        // Second use (replay) fails
        $this->expectException(OperationException::class);
        $this->expectExceptionMessage('Confirmation token has already been used.');
        $this->service->validateAndConsume($token, $actorId, $operation, $input);
    }
}
