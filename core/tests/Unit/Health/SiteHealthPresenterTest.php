<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Health;

use BsCms\Health\SiteHealthPresenter;
use PHPUnit\Framework\TestCase;

final class SiteHealthPresenterTest extends TestCase
{
    public function testSiteHealthPresenterExactKeySets(): void
    {
        $rawIssue = [
            'rule_id' => 'seo.title.missing',
            'severity' => 'error',
            'category' => 'seo',
            'message' => 'Published post has no title.',
            'entity_type' => 'entry',
            'entity_id' => '01HPOST0000000000000000001',
            'entity_title' => null,
            'fix_hint' => 'Provide a title before publishing.',
        ];

        $presentedIssue = SiteHealthPresenter::presentIssue($rawIssue);

        $expectedIssueKeys = [
            'category',
            'entity_id',
            'entity_title',
            'entity_type',
            'fix_hint',
            'message',
            'rule_id',
            'severity',
        ];
        sort($expectedIssueKeys);
        $actualIssueKeys = array_keys($presentedIssue);
        sort($actualIssueKeys);
        $this->assertSame($expectedIssueKeys, $actualIssueKeys, 'Health issue must match exact canonical 8 keys');

        $rawReport = [
            'issues' => [$rawIssue],
            'rules_checked' => 15,
            'scanned_at' => '2026-10-19 12:00:00',
        ];

        $presentedReport = SiteHealthPresenter::presentReport($rawReport);

        $expectedReportKeys = ['issues', 'rules_checked', 'scanned_at', 'summary'];
        sort($expectedReportKeys);
        $actualReportKeys = array_keys($presentedReport);
        sort($actualReportKeys);
        $this->assertSame($expectedReportKeys, $actualReportKeys, 'Health report must match exact canonical top-level keys');

        $expectedSummaryKeys = ['errors', 'info', 'status', 'total', 'warnings'];
        sort($expectedSummaryKeys);
        $actualSummaryKeys = array_keys($presentedReport['summary']);
        sort($actualSummaryKeys);
        $this->assertSame($expectedSummaryKeys, $actualSummaryKeys, 'Summary must match exact canonical 5 keys');

        $this->assertSame(1, $presentedReport['summary']['errors']);
        $this->assertSame('critical', $presentedReport['summary']['status']);
    }
}
