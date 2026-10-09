<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\LogEvent;

final readonly class TrendAnalyzer
{
    public function __construct(private Fingerprinter $fingerprinter = new Fingerprinter())
    {
    }

    /**
     * Compare equal consecutive windows. Counts describe only captured events in the sample.
     *
     * @param iterable<LogEvent> $events
     * @return array{current:int,previous:int,delta:int,groups:list<array{fingerprint:string,current:int,previous:int,delta:int,example:string}>}
     */
    public function compare(iterable $events, \DateTimeImmutable $now, int $hours = 24): array
    {
        if ($hours < 1 || $hours > 720) {
            throw new \InvalidArgumentException('Window must be 1 to 720 hours.');
        }

        $start = $now->modify(sprintf('-%d hours', $hours));
        $previous = $start->modify(sprintf('-%d hours', $hours));
        $groups = [];
        $counts = ['current' => 0, 'previous' => 0];

        foreach ($events as $event) {
            if ($event->occurredAt > $now || $event->occurredAt < $previous) {
                continue;
            }

            $window = $event->occurredAt >= $start ? 'current' : 'previous';
            ++$counts[$window];
            $key = $this->fingerprinter->fingerprint($event->channel, $event->message, $event->level);
            $groups[$key] ??= ['fingerprint' => $key, 'current' => 0, 'previous' => 0, 'delta' => 0, 'example' => $event->message];
            ++$groups[$key][$window];
        }

        foreach ($groups as &$group) {
            $group['delta'] = $group['current'] - $group['previous'];
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => ($b['current'] <=> $a['current']) ?: strcmp($a['fingerprint'], $b['fingerprint']));

        return ['current' => $counts['current'], 'previous' => $counts['previous'], 'delta' => $counts['current'] - $counts['previous'], 'groups' => $groups];
    }
}
