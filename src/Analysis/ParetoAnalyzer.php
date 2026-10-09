<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\Issue;
use Novora\KaizenBundle\Model\LogEvent;

final readonly class ParetoAnalyzer
{
    public function __construct(private Fingerprinter $fingerprinter = new Fingerprinter())
    {
    }

    /**
     * @param iterable<LogEvent> $events
     * @return list<Issue>
     */
    public function analyze(iterable $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $fingerprint = $this->fingerprinter->fingerprint($event->channel, $event->message, $event->level);
            if (!isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'level' => $event->level,
                    'channel' => $event->channel,
                    'example' => $event->message,
                    'count' => 0,
                    'first' => $event->occurredAt,
                    'last' => $event->occurredAt,
                ];
            }

            ++$groups[$fingerprint]['count'];
            if ($event->occurredAt < $groups[$fingerprint]['first']) {
                $groups[$fingerprint]['first'] = $event->occurredAt;
            }
            if ($event->occurredAt > $groups[$fingerprint]['last']) {
                $groups[$fingerprint]['last'] = $event->occurredAt;
            }
        }

        $issues = [];
        foreach ($groups as $fingerprint => $group) {
            $issues[] = new Issue(
                $fingerprint, $group['level'], $group['channel'], $group['example'],
                $group['count'], $group['first'], $group['last'],
            );
        }

        usort($issues, static fn (Issue $a, Issue $b): int => ($b->count <=> $a->count) ?: strcmp($a->fingerprint, $b->fingerprint));

        return $issues;
    }
}
