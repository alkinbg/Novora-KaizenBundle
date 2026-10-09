<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Model\LogEvent;
use PHPUnit\Framework\TestCase;

final class ParetoAnalyzerTest extends TestCase
{
    public function testGroupsDynamicIdentifiersButNotDifferentMessages(): void
    {
        $date = new \DateTimeImmutable('2026-10-09T10:00:00+03:00');
        $events = [
            new LogEvent($date, 'error', 'request', 'Failed request 123', 'prod.log'),
            new LogEvent($date->modify('+1 minute'), 'error', 'request', 'Failed request 456', 'prod.log'),
            new LogEvent($date, 'warning', 'request', 'Another warning', 'prod.log'),
        ];

        $issues = (new ParetoAnalyzer())->analyze($events);

        self::assertCount(2, $issues);
        self::assertSame(2, $issues[0]->count);
        self::assertSame('Failed request 123', $issues[0]->example);
    }

    public function testNoEventsProducesNoIssues(): void
    {
        self::assertSame([], (new ParetoAnalyzer())->analyze([]));
    }
}
