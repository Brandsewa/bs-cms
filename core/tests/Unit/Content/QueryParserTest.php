<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Content;

use BsCms\Content\Query\FilterClause;
use BsCms\Content\Query\QueryParser;
use BsCms\Content\Query\QueryValidationException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class QueryParserTest extends TestCase
{
    private QueryParser $parser;
    /** @var array<string, mixed> */
    private array $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new QueryParser();
        $this->collection = [
            'handle' => 'posts',
            'default_sort' => '-published_at',
            'fields' => [
                'template' => [
                    'handle' => 'template',
                    'type' => 'enum',
                    'is_filterable' => true,
                    'is_sortable' => true,
                ],
                'unindexed_body' => [
                    'handle' => 'unindexed_body',
                    'type' => 'textarea',
                    'is_filterable' => false,
                    'is_sortable' => false,
                ],
                'authors' => [
                    'handle' => 'authors',
                    'type' => 'relation',
                    'config' => [
                        'target_collection' => 'authors',
                    ],
                ],
            ],
        ];
    }

    public function testParsesValidFiltersAndSorting(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'filter' => [
                    'status' => ['eq' => 'published'],
                    'template' => ['in' => 'default,landing'],
                ],
                'sort' => '-published_at,id',
                'fields' => 'title,template',
                'populate' => 'authors',
                'page' => [
                    'number' => '2',
                    'size' => '10',
                ],
            ]);

        $options = $this->parser->parse($request, $this->collection);

        $this->assertCount(2, $options->filters);
        $this->assertSame('status', $options->filters[0]->field);
        $this->assertSame(FilterClause::OP_EQ, $options->filters[0]->operator);
        $this->assertSame('published', $options->filters[0]->value);

        $this->assertSame('template', $options->filters[1]->field);
        $this->assertSame(FilterClause::OP_IN, $options->filters[1]->operator);

        $this->assertCount(2, $options->sort);
        $this->assertSame('published_at', $options->sort[0]['field']);
        $this->assertSame('desc', $options->sort[0]['direction']);
        $this->assertSame('id', $options->sort[1]['field']);
        $this->assertSame('asc', $options->sort[1]['direction']);

        $this->assertSame(['title', 'template'], $options->fields);
        $this->assertSame(['authors'], $options->populate);
        $this->assertSame(2, $options->page);
        $this->assertSame(10, $options->size);
    }

    public function testRejectsUnindexedFieldFilterWithClearMessage(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'filter' => [
                    'unindexed_body' => ['eq' => 'test'],
                ],
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Field 'unindexed_body' is not indexed; add an index before filtering on it.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsSqlInjectionInFilterField(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'filter' => [
                    "id' OR 1=1--" => ['eq' => 'val'],
                ],
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Invalid filter field name 'id' OR 1=1--'.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsUnsupportedOperator(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'filter' => [
                    'status' => ['invalid_op' => 'published'],
                ],
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Operator 'invalid_op' is not supported.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsSqlInjectionInSortField(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'sort' => "status; DROP TABLE bs_users--",
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Invalid sort field name 'status; DROP TABLE bs_users--'.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsNonExistentSortField(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'sort' => "non_existent_column",
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Field 'non_existent_column' does not exist for sorting.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsSqlInjectionInSparseFields(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'fields' => "title, (SELECT password_hash FROM bs_users)",
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Invalid field selection");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsPopulatingNonRelationField(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'populate' => 'template',
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Field 'template' cannot be populated; must be a relation field.");

        $this->parser->parse($request, $this->collection);
    }

    public function testRejectsSqlInjectionInPopulate(): void
    {
        $request = (new ServerRequest('GET', '/api/v1/collections/posts/entries'))
            ->withQueryParams([
                'populate' => 'authors; DELETE FROM bs_collections',
            ]);

        $this->expectException(QueryValidationException::class);
        $this->expectExceptionMessage("Invalid populate relation name");

        $this->parser->parse($request, $this->collection);
    }
}
