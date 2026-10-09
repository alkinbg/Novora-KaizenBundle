<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\ParetoChartBuilder;
use Novora\KaizenBundle\Model\Issue;
use PHPUnit\Framework\TestCase;

final class ParetoChartBuilderTest extends TestCase
{
    public function testExplicitLimitExposesRemainderWithoutPlottingMisleadingOtherBar(): void
    {
        $now = new \DateTimeImmutable('2026-10-09T09:00:00+00:00');
        $issues = [
            new Issue('a', 'info', 'deprecation', 'Deprecated API A', 60, $now, $now),
            new Issue('b', 'error', 'app', 'Failed request B', 25, $now, $now),
            new Issue('c', 'warning', 'app', 'Unexpected status C', 10, $now, $now),
            new Issue('d', 'info', 'app', 'Log D', 5, $now, $now),
        ];

        $chart = (new ParetoChartBuilder())->build($issues, 2);

        self::assertSame(100, $chart['total']);
        self::assertSame(85, $chart['coveredCount']);
        self::assertSame(15, $chart['otherCount']);
        self::assertSame(15.0, $chart['otherPercent']);
        self::assertSame(85.0, $chart['coveredPercent']);
        self::assertSame(1, $chart['thresholdBucket']);
        self::assertSame(['P1', 'P2'], array_column($chart['buckets'], 'label'));
        self::assertSame([60, 25], array_column($chart['buckets'], 'count'));
        self::assertSame([60.0, 85.0], array_column($chart['buckets'], 'cumulative'));
        self::assertSame([60.0, 25.0], array_column($chart['buckets'], 'percent'));
        self::assertCount(2, explode(' ', $chart['points']));
        self::assertEqualsWithDelta(278.0, $chart['buckets'][0]['y'] + $chart['buckets'][0]['height'], 0.02);
    }

    public function testExplicitLimitDoesNotClaimEightyPercentWhenRemainingPatternsAreHidden(): void
    {
        $now = new \DateTimeImmutable();
        $chart = (new ParetoChartBuilder())->build([
            new Issue('a', 'error', 'app', 'Failure A', 6, $now, $now),
            new Issue('b', 'error', 'app', 'Failure B', 4, $now, $now),
        ], 1);

        self::assertNull($chart['thresholdBucket']);
        self::assertSame(60.0, $chart['coveredPercent']);
        self::assertSame(4, $chart['otherCount']);
        self::assertCount(1, $chart['buckets']);
    }

    public function testAutomaticLimitAddsBarsUntilCoverageReachesEightyPercent(): void
    {
        $now = new \DateTimeImmutable();
        $counts = [6, 3, 3, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1];
        $issues = [];
        foreach ($counts as $i => $count) {
            $issues[] = new Issue((string) $i, 'error', 'request', 'Error '.$i, $count, $now, $now);
        }

        $chart = (new ParetoChartBuilder())->build($issues);
        self::assertNotNull($chart['thresholdBucket']);
        self::assertGreaterThanOrEqual(80, $chart['coveredPercent']);
        self::assertSame(count($chart['buckets']) - 1, $chart['thresholdBucket']);
        self::assertGreaterThan(8, count($chart['buckets']));
        self::assertLessThanOrEqual(16, count($chart['buckets']));
        self::assertSame($chart['total'], $chart['coveredCount'] + $chart['otherCount']);

        foreach ($chart['buckets'] as $i => $bucket) {
            if ($i > 0) {
                self::assertGreaterThanOrEqual($bucket['count'], $chart['buckets'][$i - 1]['count']);
                self::assertLessThanOrEqual($bucket['x'], $chart['buckets'][$i - 1]['x'] + $chart['buckets'][$i - 1]['width']);
            }
        }
    }

    public function testAutomaticLimitIsCappedAndDoesNotFakeThreshold(): void
    {
        $now = new \DateTimeImmutable();
        $issues = [];
        for ($i = 0; $i < 100; ++$i) {
            $issues[] = new Issue((string) $i, 'error', 'request', 'Error '.$i, 1, $now, $now);
        }

        $chart = (new ParetoChartBuilder())->build($issues);

        self::assertCount(16, $chart['buckets']);
        self::assertSame(16.0, $chart['coveredPercent']);
        self::assertSame(84, $chart['otherCount']);
        self::assertNull($chart['thresholdBucket']);
    }

    public function testEmptyPopulationDoesNotDivideByZero(): void
    {
        $chart = (new ParetoChartBuilder())->build([]);

        self::assertSame(0, $chart['total']);
        self::assertSame([], $chart['buckets']);
        self::assertSame('', $chart['points']);
        self::assertSame(0, $chart['otherCount']);
        self::assertNull($chart['thresholdBucket']);
    }

    public function testInvalidNumberOfBarsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ParetoChartBuilder())->build([], 0);
    }
}
