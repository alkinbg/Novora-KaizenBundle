<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\LogEvent;

/**
 * Count correlated executions, never infer missing IDs from timestamps.
 * One execution may emit many errors and appear in multiple issue groups.
 */
final class ExecutionAnalyzer
{
    /**
     * @param iterable<LogEvent> $events
     * @return array{
     *     errorEvents:int,correlatedErrorEvents:int,uncorrelatedErrorEvents:int,
     *     observedEntries:int,observedExecutions:int,
     *     knownExecutions:int,byOrigin:array<string,int>,
     *     executions:list<array{id:string,origin:string,count:int,first:\DateTimeImmutable,last:\DateTimeImmutable}>
     * }
     */
    public function summarize(iterable $events): array
    {
        $known = [];
        $observed = [];
        $observedEntries = 0;
        $errorEvents = 0;
        $uncorrelated = 0;
        $correlated = 0;

        foreach ($events as $event) {
            $validId = $event->executionId !== null
                && preg_match('/^[a-f0-9]{32}$/D', $event->executionId) === 1
                && in_array($event->origin, ['http', 'messenger', 'cli'], true);

            if ($validId) {
                ++$observedEntries;
                $observed[$event->origin.':'.$event->executionId] = true;
            }

            if (!in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                continue;
            }

            ++$errorEvents;
            if (!$validId) {
                ++$uncorrelated;
                continue;
            }

            ++$correlated;
            $key = $event->origin.':'.$event->executionId;
            $known[$key] ??= [
                'id' => $event->executionId,
                'origin' => $event->origin,
                'count' => 0,
                'first' => $event->occurredAt,
                'last' => $event->occurredAt,
            ];
            ++$known[$key]['count'];
            if ($event->occurredAt < $known[$key]['first']) {
                $known[$key]['first'] = $event->occurredAt;
            }
            if ($event->occurredAt > $known[$key]['last']) {
                $known[$key]['last'] = $event->occurredAt;
            }
        }

        $executions = array_values($known);
        usort($executions, static fn (array $a, array $b): int => ($b['count'] <=> $a['count'])
            ?: strcmp($a['origin'].':'.$a['id'], $b['origin'].':'.$b['id']));

        $byOrigin = ['http' => 0, 'messenger' => 0, 'cli' => 0];
        foreach ($executions as $execution) {
            ++$byOrigin[$execution['origin']];
        }

        return [
            'errorEvents' => $errorEvents,
            'observedEntries' => $observedEntries,
            'observedExecutions' => count($observed),
            'correlatedErrorEvents' => $correlated,
            'uncorrelatedErrorEvents' => $uncorrelated,
            'knownExecutions' => count($executions),
            'byOrigin' => $byOrigin,
            'executions' => $executions,
        ];
    }
}
