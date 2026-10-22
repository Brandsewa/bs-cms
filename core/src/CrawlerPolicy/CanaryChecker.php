<?php

declare(strict_types=1);

namespace BsCms\CrawlerPolicy;

use RuntimeException;

final class CanaryChecker
{
    /**
     * Compare live robots.txt and verify crawlability with simulated user agents.
     *
     * @param string $siteBaseUrl e.g. "https://example.com"
     * @param string $expectedRobotsTxt
     * @return array{
     *     status: 'pass'|'fail'|'warning',
     *     live_robots_fetched: bool,
     *     differs: bool,
     *     checks: list<array{name: string, status: 'pass'|'fail'|'warn', message: string}>
     * }
     */
    public function check(string $siteBaseUrl, string $expectedRobotsTxt): array
    {
        $siteBaseUrl = rtrim($siteBaseUrl, '/');
        $robotsUrl = "{$siteBaseUrl}/robots.txt";

        $checks = [];
        $liveRobots = null;
        $fetched = false;

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'user_agent' => 'BS-CMS-Canary/1.0',
            ],
        ]);

        $raw = @file_get_contents($robotsUrl, false, $ctx);
        if ($raw !== false && isset($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (str_contains($statusLine, '200')) {
                $liveRobots = $raw;
                $fetched = true;
            }
        }

        if (!$fetched) {
            $checks[] = [
                'name' => 'tech.robots.unreachable',
                'status' => 'warn',
                'message' => "Could not reach live robots.txt at {$robotsUrl}",
            ];
            return [
                'status' => 'warning',
                'live_robots_fetched' => false,
                'differs' => false,
                'checks' => $checks,
            ];
        }

        // Compare normalized content
        $normLive = trim(str_replace(["\r\n", "\r"], "\n", (string) $liveRobots));
        $normExpected = trim(str_replace(["\r\n", "\r"], "\n", $expectedRobotsTxt));

        $differs = $normLive !== $normExpected;
        if ($differs) {
            $checks[] = [
                'name' => 'tech.robots.live_differs',
                'status' => 'warn',
                'message' => 'Live robots.txt on host differs from active CMS policy version.',
            ];
        } else {
            $checks[] = [
                'name' => 'tech.robots.match',
                'status' => 'pass',
                'message' => 'Live robots.txt matches active CMS policy exactly.',
            ];
        }

        // Check if search engines are blocked
        if (preg_match('/User-agent:\s*(Googlebot|Bingbot)\s*\nDisallow:\s*\/\s*(\n|$)/i', $normLive)) {
            $checks[] = [
                'name' => 'tech.robots.blocks_search',
                'status' => 'fail',
                'message' => 'Live robots.txt blocks Googlebot or Bingbot from indexing.',
            ];
        }

        $overallStatus = 'pass';
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                $overallStatus = 'fail';
                break;
            }
            if ($c['status'] === 'warn' && $overallStatus !== 'fail') {
                $overallStatus = 'warning';
            }
        }

        return [
            'status' => $overallStatus,
            'live_robots_fetched' => true,
            'differs' => $differs,
            'checks' => $checks,
        ];
    }
}
