<?php

declare(strict_types=1);

namespace BsCms\Http;

use BsCms\Support\Json;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * RFC 9457 Problem Details for HTTP APIs (application/problem+json).
 */
final class ProblemDetails
{
    private string $type;
    private string $title;
    private int $status;
    private ?string $detail;
    private ?string $instance;
    /** @var array<string, list<string>>|null */
    private ?array $errors;
    /** @var array<string, mixed>|null */
    private ?array $debug = null;

    /**
     * @param array<string, list<string>>|null $errors
     */
    public function __construct(
        int $status,
        string $title,
        ?string $detail = null,
        string $type = 'about:blank',
        ?string $instance = null,
        ?array $errors = null
    ) {
        $this->status = $status;
        $this->title = $title;
        $this->detail = $detail;
        $this->type = $type;
        $this->instance = $instance;
        $this->errors = $errors;
    }

    public static function create(
        int $status,
        string $title,
        ?string $detail = null,
        string $type = 'about:blank',
        ?string $instance = null
    ): self {
        return new self($status, $title, $detail, $type, $instance);
    }

    /**
     * Create a 422 Unprocessable Content problem for validation errors.
     *
     * @param array<string, list<string>> $errors
     */
    public static function validation(
        array $errors,
        ?string $instance = null,
        string $detail = 'The request payload failed validation checks.'
    ): self {
        return new self(
            422,
            'Unprocessable Content',
            $detail,
            'https://errors.bscms.org/validation-failed',
            $instance,
            $errors
        );
    }

    public static function notFound(?string $detail = 'The requested resource was not found.', ?string $instance = null): self
    {
        return new self(404, 'Not Found', $detail, 'https://errors.bscms.org/not-found', $instance);
    }

    public static function forbidden(?string $detail = 'You do not have permission to perform this action.', ?string $instance = null): self
    {
        return new self(403, 'Forbidden', $detail, 'https://errors.bscms.org/forbidden', $instance);
    }

    public static function unauthorized(?string $detail = 'Authentication is required to access this resource.', ?string $instance = null): self
    {
        return new self(401, 'Unauthorized', $detail, 'https://errors.bscms.org/unauthorized', $instance);
    }

    public static function tooManyRequests(int $retryAfter, ?string $instance = null): self
    {
        return new self(
            429,
            'Too Many Requests',
            "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
            'https://errors.bscms.org/rate-limit-exceeded',
            $instance
        );
    }

    /**
     * Build problem details from any Throwable, guaranteeing that in production no stack traces or internals leak.
     */
    public static function fromException(Throwable $e, ?string $instance = null, bool $debug = false): self
    {
        $status = 500;
        $title = 'Internal Server Error';

        if (!$debug) {
            // Production invariant: never expose internal message or traces
            $problem = new self(
                $status,
                $title,
                'An internal error occurred. Please contact the site administrator.',
                'https://errors.bscms.org/internal-server-error',
                $instance
            );
            return $problem;
        }

        // Non-production debug mode allowed
        $problem = new self(
            $status,
            $title,
            $e->getMessage(),
            'https://errors.bscms.org/internal-server-error',
            $instance
        );

        $problem->debug = [
            'class' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ];

        return $problem;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if ($this->detail !== null) {
            $data['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $data['instance'] = $this->instance;
        }

        if ($this->errors !== null && !empty($this->errors)) {
            $data['errors'] = $this->errors;
        }

        if ($this->debug !== null) {
            $data['debug'] = $this->debug;
        }

        return $data;
    }

    public function toResponse(): ResponseInterface
    {
        $json = Json::encode($this->toArray());
        $stream = Stream::create($json);

        $response = new Response($this->status, [], $stream);

        return $response
            ->withHeader('Content-Type', 'application/problem+json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
