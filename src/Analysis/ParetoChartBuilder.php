<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\Issue;

/**
 * Frequency Pareto: chart only genuine descending issue bars. Unshown events
 * are reported separately rather than as a giant misleading "Other" bar.
 */
final class ParetoChartBuilder
{
    private const TARGET_PERCENT = 80;
    private const MAX_AUTO_PATTERNS = 16;

    /**
     * Null limit selects enough patterns to reach 80% coverage, capped at 16.
     * An explicit limit always selects exactly that many patterns at most.
     *
     * @param list<Issue> $issues
     * @return array{
     *     total:int,maxCount:int,coveredCount:int,coveredPercent:float,
     *     otherCount:int,otherPercent:float,thresholdBucket:int|null,
     *     points:string,
     *     buckets:list<array{
     *         label:string,example:string,count:int,percent:float,cumulative:float,
     *         fingerprint:string,level:string,
     *         x:float,width:float,center:float,y:float,height:float,lineY:float
     *     }>
     * }
     */
    public function build(array $issues, ?int $limit = null): array
    {
        if ($limit !== null && ($limit < 1 || $limit > 20)) {
            throw new \InvalidArgumentException('Pareto limit must be between 1 and 20.');
        }

        usort($issues, static fn (Issue $a, Issue $b): int => ($b->count <=> $a->count)
            ?: strcmp($a->fingerprint, $b->fingerprint));

        $total = array_sum(array_map(static fn (Issue $issue): int => $issue->count, $issues));

        if ($limit === null) {
            $limit = 0;
            $running = 0;

            foreach ($issues as $issue) {
                if ($limit >= self::MAX_AUTO_PATTERNS) {
                    break;
                }

                ++$limit;
                $running += $issue->count;

                if ($total > 0 && 100 * $running >= self::TARGET_PERCENT * $total) {
                    break;
                }
            }
        }

        $chosen = array_slice($issues, 0, $limit);
        $covered = array_sum(array_map(static fn (Issue $issue): int => $issue->count, $chosen));
        $other = $total - $covered;
        $max = max([1, ...array_map(static fn (Issue $issue): int => $issue->count, $chosen)]);

        $buckets = [];
        $points = [];
        $running = 0;
        $threshold = null;
        $count = count($chosen);
        $width = $count > 0 ? min(60.0, 835 * 0.72 / $count) : 60.0;

        foreach ($chosen as $i => $issue) {
            $running += $issue->count;
            $cumulative = $total > 0 ? 100 * $running / $total : 0.0;
            $percent = $total > 0 ? 100 * $issue->count / $total : 0.0;
            $center = 95 + ($i + 0.5) * 835 / $count;
            $height = 200 * $issue->count / $max;
            $lineY = 278 - 2 * $cumulative;

            $buckets[] = [
                'label' => sprintf('P%d', $i + 1),
                'example' => $issue->example,
                'count' => $issue->count,
                'fingerprint' => $issue->fingerprint,
                'level' => $issue->level,
                'percent' => round($percent, 1),
                'cumulative' => round($cumulative, 1),
                'x' => round($center - $width / 2, 2),
                'width' => round($width, 2),
                'center' => round($center, 2),
                'y' => round(278 - $height, 2),
                'height' => round($height, 2),
                'lineY' => round($lineY, 2),
            ];

            $points[] = sprintf('%.2f,%.2f', $center, $lineY);

            if ($threshold === null && $cumulative >= self::TARGET_PERCENT) {
                $threshold = $i;
            }
        }

        return [
            'total' => $total,
            'maxCount' => $max,
            'coveredCount' => $covered,
            'coveredPercent' => $total > 0 ? round(100 * $covered / $total, 1) : 0.0,
            'otherCount' => $other,
            'otherPercent' => $total > 0 ? round(100 * $other / $total, 1) : 0.0,
            'thresholdBucket' => $threshold,
            'points' => implode(' ', $points),
            'buckets' => $buckets,
        ];
    }
}
