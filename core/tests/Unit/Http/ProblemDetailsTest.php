<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Http;

use BsCms\Http\JsonResponse;
use BsCms\Http\ProblemDetails;
use Exception;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function testJsonResponseCreatesStandardResponse(): void
    {
        $response = JsonResponse::create(['status' => 'ok'], 201, ['X-Custom' => 'test']);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('test', $response->getHeaderLine('X-Custom'));

        $body = (string) $response->getBody();
        $expectedJson = json_encode(['status' => 'ok']);
        $this->assertIsString($expectedJson);
        $this->assertJsonStringEqualsJsonString($expectedJson, $body);
    }

    public function testProblemDetailsFactoryMethods(): void
    {
        $notFound = ProblemDetails::notFound('Post not found', '/api/posts/123');
        $this->assertSame(404, $notFound->toArray()['status']);
        $this->assertSame('Not Found', $notFound->toArray()['title']);
        $this->assertSame('Post not found', $notFound->toArray()['detail']);
        $this->assertSame('/api/posts/123', $notFound->toArray()['instance']);

        $forbidden = ProblemDetails::forbidden();
        $this->assertSame(403, $forbidden->toArray()['status']);

        $unauthorized = ProblemDetails::unauthorized();
        $this->assertSame(401, $unauthorized->toArray()['status']);

        $tooManyRequests = ProblemDetails::tooManyRequests(60);
        $this->assertSame(429, $tooManyRequests->toArray()['status']);
        $this->assertStringContainsString('60 seconds', $tooManyRequests->toArray()['detail']);
    }

    public function testValidationProblemIncludesErrors(): void
    {
        $validation = ProblemDetails::validation([
            'email' => ['Email is required', 'Email must be valid'],
            'title' => ['Title must be at least 3 characters'],
        ]);

        $arr = $validation->toArray();
        $this->assertSame(422, $arr['status']);
        $this->assertSame('Unprocessable Content', $arr['title']);
        $this->assertCount(2, $arr['errors']['email']);
        $this->assertCount(1, $arr['errors']['title']);

        $response = $validation->toResponse();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testFromExceptionHidesDetailsInProduction(): void
    {
        $e = new Exception('Sensitive DB Connection string leaked: mysql://root:pass@localhost/db');

        // debug = false (Production mode)
        $problem = ProblemDetails::fromException($e, '/api/admin/setup', false);
        $arr = $problem->toArray();

        $this->assertSame(500, $arr['status']);
        $this->assertSame('Internal Server Error', $arr['title']);
        $this->assertSame('An internal error occurred. Please contact the site administrator.', $arr['detail']);
        $this->assertArrayNotHasKey('debug', $arr);
        $this->assertStringNotContainsString('Sensitive DB Connection', (string) $problem->toResponse()->getBody());
    }

    public function testFromExceptionIncludesDetailsInDebugMode(): void
    {
        $e = new Exception('Specific SQL syntax error on line 42');

        // debug = true (Development mode)
        $problem = ProblemDetails::fromException($e, '/api/test', true);
        $arr = $problem->toArray();

        $this->assertSame(500, $arr['status']);
        $this->assertSame('Specific SQL syntax error on line 42', $arr['detail']);
        $this->assertArrayHasKey('debug', $arr);
        $this->assertSame(Exception::class, $arr['debug']['class']);
        $this->assertNotEmpty($arr['debug']['trace']);
    }
}
