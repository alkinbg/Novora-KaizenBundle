<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\WasteDetector;
use Novora\KaizenBundle\Model\Issue;
use PHPUnit\Framework\TestCase;

final class WasteDetectorTest extends TestCase
{
    public function testSeparatesCompatibilityWarningsFromLoggingVolume(): void
    {
        $now = new \DateTimeImmutable();
        $issues = [
            new Issue('a', 'info', 'deprecation', 'User Deprecated: Passing string as $order to Doctrine\\ORM\\QueryBuilder::orderBy() is deprecated.', 2091, $now, $now),
            new Issue('b', 'info', 'deprecation', 'User Deprecated: Zone::ramps: ASC direction is deprecated.', 442, $now, $now),
            new Issue('c', 'error', 'request', 'SQLSTATE[HY000] connection refused', 4, $now, $now),
            new Issue('d', 'info', 'app', 'Cache warmed', 60, $now, $now),
            new Issue('e', 'info', 'event', 'Notified event "kernel.request" to listener', 3000, $now, $now),
            new Issue('f', 'debug', 'app', 'Notified event "kernel.request"', 5000, $now, $now),
            new Issue('g', 'error', 'messenger', 'Retrying message after failure', 7, $now, $now),
        ];

        $items = (new WasteDetector())->detect($issues);

        self::assertCount(5, $items);
        self::assertSame(['Defect signal', 'Rework candidate', 'Compatibility debt', 'Compatibility debt', 'Log volume candidate'], array_column($items, 'type'));
        self::assertSame([4, 7, 2091, 442, 60], array_column($items, 'count'));
        self::assertSame('deprecation', $items[2]['channel']);
        self::assertArrayNotHasKey('impactScore', $items[0]);
    }

    public function testRoutineDebugEventsAreNotOpportunitySignals(): void
    {
        $now = new \DateTimeImmutable();
        $issue = new Issue('k', 'debug', 'event', 'Notified event "kernel.request"', 1000, $now, $now);

        self::assertTrue((new WasteDetector())->isRoutineEvent($issue));
        self::assertSame([], (new WasteDetector())->detect([$issue]));
    }

    public function testNonRoutineWarningsAreNotMistakenForWaste(): void
    {
        $now = new \DateTimeImmutable();
        $issue = new Issue('k', 'warning', 'app', 'Queue latency high', 200, $now, $now);

        self::assertSame([], (new WasteDetector())->detect([$issue]));
    }
}
