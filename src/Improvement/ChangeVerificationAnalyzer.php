<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Improvement;

use Novora\KaizenBundle\Analysis\Fingerprinter;
use Novora\KaizenBundle\Model\LogEvent;

/**
 * Compares equal intervals around a documented correction.
 *
 * No change in frequency is ever interpreted as proof of a fix: a bounded
 * rotating log tail cannot establish application availability or traffic.
 */
final readonly class ChangeVerificationAnalyzer
{
    public function __construct(private Fingerprinter $fingerprinter = new Fingerprinter())
    {
    }

    /**
     * @param iterable<LogEvent> $events
     * @return array{
     *   status:string, before:int, after:int, since:int,
     *   beforeEvents:int, afterEvents:int, firstSeen:?\DateTimeImmutable,
     *   lastSeen:?\DateTimeImmutable, sampleStart:?\DateTimeImmutable,
     *   sampleEnd:?\DateTimeImmutable, beforeStart:\DateTimeImmutable,
     *   appliedAt:\DateTimeImmutable, afterEnd:\DateTimeImmutable,
     *   completeBefore:bool, sampledAfter:bool, hours:float,
     *   postWindowLimited:bool
     * }
     */
    public function analyze(
        iterable $events,
        string $fingerprint,
        \DateTimeImmutable $appliedAt,
        \DateTimeImmutable $now,
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('An exact fingerprint is required for verification.');
        }

        $elapsed = $now->getTimestamp() - $appliedAt->getTimestamp();
        if ($elapsed <= 0) {
            throw new \InvalidArgumentException('Correction time must precede the analysis time.');
        }

        // Compare at most the first 24 hours after the change. Even if the
        // after-window is historical, separately check ALL later recurrences.
        $duration = min(86_400, $elapsed);
        $beforeStart = $appliedAt->modify(sprintf('-%d seconds', $duration));
        $afterEnd = $appliedAt->modify(sprintf('+%d seconds', $duration));

        $before = 0;
        $after = 0;
        $since = 0;
        $beforeEvents = 0;
        $afterEvents = 0;
        $first = null;
        $last = null;
        $sampleStart = null;
        $sampleEnd = null;

        foreach ($events as $event) {
            if ($event->occurredAt > $now) {
                continue; // Ignore timestamps in the future.
            }

            $at = $event->occurredAt;
            if ($sampleStart === null || $at < $sampleStart) {
                $sampleStart = $at;
            }
            if ($sampleEnd === null || $at > $sampleEnd) {
                $sampleEnd = $at;
            }

            $match = $this->fingerprinter->fingerprint($event->channel, $event->message, $event->level) === $fingerprint;

            if ($match) {
                if ($first === null || $at < $first) {
                    $first = $at;
                }
                if ($last === null || $at > $last) {
                    $last = $at;
                }
                if ($at >= $appliedAt) {
                    ++$since;
                }
            }

            if ($at >= $beforeStart && $at < $appliedAt) {
                ++$beforeEvents;
                if ($match) {
                    ++$before;
                }
            } elseif ($at >= $appliedAt && $at <= $afterEnd) {
                ++$afterEvents;
                if ($match) {
                    ++$after;
                }
            }
        }

        $completeBefore = $sampleStart !== null && $sampleStart <= $beforeStart;
        $sampledAfter = $afterEvents > 0;

        $status = 'insufficient_evidence';
        if ($since > 0) {
            $status = 'recurrence_observed';
        } elseif ($before > 0 && $sampledAfter && $completeBefore) {
            // Only a candidate: the system NEVER automatically resolves cases.
            $status = 'no_recurrence_observed';
        }

        return [
            'status' => $status, 'before' => $before, 'after' => $after, 'since' => $since,
            'beforeEvents' => $beforeEvents, 'afterEvents' => $afterEvents,
            'firstSeen' => $first, 'lastSeen' => $last,
            'sampleStart' => $sampleStart, 'sampleEnd' => $sampleEnd,
            'beforeStart' => $beforeStart, 'appliedAt' => $appliedAt,
            'afterEnd' => $afterEnd, 'completeBefore' => $completeBefore,
            'sampledAfter' => $sampledAfter,
            'hours' => round($duration / 3600, 2),
            'postWindowLimited' => $elapsed > 86_400,
        ];
    }
}
