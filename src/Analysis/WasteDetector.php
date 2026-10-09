<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\Issue;

final class WasteDetector
{
    /**
     * These are triage categories, not confirmed waste or root causes.
     *
     * @param list<Issue> $issues
     * @return list<array{type:string,reason:string,fingerprint:string,example:string,count:int,level:string,channel:string}>
     */
    public function detect(array $issues): array
    {
        $candidates = [];

        foreach ($issues as $issue) {
            if ($this->isRoutineEvent($issue)) {
                continue;
            }

            $type = null;
            $reason = null;
            $rank = 99;
            $retry = preg_match('/\b(retry|retried|retrying|requeued|requeue|attempt\s+\d+)\b/i', $issue->example) === 1;
            $deprecation = $this->isCompatibilityIssue($issue);

            if ($retry) {
                $type = 'Rework candidate';
                $reason = 'Retries or requeues are mentioned. Inspect the job lifecycle before concluding that work is duplicated.';
                $rank = 1;
            } elseif (in_array($issue->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $type = 'Defect signal';
                $reason = 'Error-level event. Verify its actual user or business impact and recurrence.';
                $rank = 0;
            } elseif ($deprecation) {
                $type = 'Compatibility debt';
                $reason = 'Deprecation warning: locate the call site and check its compatibility with a future dependency version.';
                $rank = 2;
            } elseif (in_array($issue->level, ['info', 'notice'], true) && $issue->count >= 50) {
                $type = 'Log volume candidate';
                $reason = 'This informational pattern appears at least 50 times in the sample. Check whether this logging volume is necessary.';
                $rank = 3;
            }

            if ($type === null) {
                continue;
            }

            $candidates[] = [
                'type' => $type,
                'reason' => $reason,
                'fingerprint' => $issue->fingerprint,
                'example' => $issue->example,
                'count' => $issue->count,
                'level' => $issue->level,
                'channel' => $issue->channel,
                '_rank' => $rank,
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => ($a['_rank'] <=> $b['_rank'])
            ?: ($b['count'] <=> $a['count'])
            ?: strcmp($a['fingerprint'], $b['fingerprint']));

        return array_map(
            static function (array $candidate): array {
                unset($candidate['_rank']);

                return $candidate;
            },
            array_slice($candidates, 0, 9),
        );
    }

    public function isCompatibilityIssue(Issue $issue): bool
    {
        return $issue->channel === 'deprecation'
            || preg_match('/\b(?:user\s+deprecated|deprecated|deprecation)\b/i', $issue->example) === 1;
    }

    public function isRoutineEvent(Issue $issue): bool
    {
        // Debug-level framework traces are observable, but not themselves evidence of waste.
        if ($issue->level === 'debug') {
            return true;
        }

        return in_array($issue->level, ['info', 'notice'], true)
            && $issue->channel === 'event'
            && preg_match('/^Notified event\b/i', $issue->example) === 1;
    }
}
