<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\TrendAnalyzer;
use Novora\KaizenBundle\Model\LogEvent;
use PHPUnit\Framework\TestCase;

final class TrendAnalyzerTest extends TestCase
{
    public function testComparesConsecutiveEqualWindows(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T12:00:00+00:00');
        $events = [
            new LogEvent($now->modify('-1 hour'), 'error', 'app', 'Order id=22 failed', 'a'),
            new LogEvent($now->modify('-2 hours'), 'error', 'app', 'Order id=23 failed', 'a'),
            new LogEvent($now->modify('-30 hours'), 'error', 'app', 'Order id=24 failed', 'a'),
            new LogEvent($now->modify('-60 hours'), 'error', 'app', 'Order id=25 failed', 'a'),
        ];
        $result = (new TrendAnalyzer())->compare($events, $now, 24);
        self::assertSame(2, $result['current']);
        self::assertSame(1, $result['previous']);
        self::assertSame(1, $result['delta']);
        self::assertCount(1, $result['groups']);
        self::assertSame(2, $result['groups'][0]['current']);
        self::assertSame(1, $result['groups'][0]['previous']);
    }

    public function testRejectsInvalidWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TrendAnalyzer())->compare([], new \DateTimeImmutable(), 0);
    }
}
