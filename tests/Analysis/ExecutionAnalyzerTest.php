<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\ExecutionAnalyzer;
use Novora\KaizenBundle\Model\LogEvent;
use PHPUnit\Framework\TestCase;

final class ExecutionAnalyzerTest extends TestCase
{
    public function testCountsExplicitExecutionsAndNeverGuessesFromUncorrelatedEvents(): void
    {
        $date = new \DateTimeImmutable();
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $events = [
            new LogEvent($date, 'error', 'request', 'Something failed', 'dev.log', $first, 'http'),
            new LogEvent($date, 'critical', 'request', 'Follow-up error', 'dev.log', $first, 'http'),
            new LogEvent($date, 'error', 'request', 'Another failure', 'dev.log', $second, 'messenger'),
            new LogEvent($date, 'error', 'request', 'Legacy failure', 'dev.log'),
            new LogEvent($date, 'info', 'app', 'Informational message', 'dev.log', $first, 'http'),
        ];

        $result = (new ExecutionAnalyzer())->summarize($events);

        self::assertSame(4, $result['observedEntries']);
        self::assertSame(2, $result['observedExecutions']);
        self::assertSame(4, $result['errorEvents']);
        self::assertSame(3, $result['correlatedErrorEvents']);
        self::assertSame(1, $result['uncorrelatedErrorEvents']);
        self::assertSame(2, $result['knownExecutions']);
        self::assertSame(['http' => 1, 'messenger' => 1, 'cli' => 0], $result['byOrigin']);
        self::assertSame(2, $result['executions'][0]['count']);
    }

    public function testMalformedIdentifiersDoNotCreateFakeExecutions(): void
    {
        $now = new \DateTimeImmutable();
        $result = (new ExecutionAnalyzer())->summarize([
            new LogEvent($now, 'error', 'app', 'Invalid ID', 'dev.log', 'not-an-id', 'http'),
            new LogEvent($now, 'error', 'app', 'Unknown origin', 'dev.log', str_repeat('a', 32), 'other'),
        ]);

        self::assertSame(0, $result['observedEntries']);
        self::assertSame(0, $result['observedExecutions']);
        self::assertSame(0, $result['knownExecutions']);
        self::assertSame(2, $result['uncorrelatedErrorEvents']);
    }

    public function testInformationalLogsCanConfirmInstrumentationWithoutErrors(): void
    {
        $now = new \DateTimeImmutable();
        $id = str_repeat('c', 32);
        $result = (new ExecutionAnalyzer())->summarize([
            new LogEvent($now, 'info', 'app', 'HTTP started', 'dev.log', $id, 'http'),
            new LogEvent($now, 'debug', 'app', 'HTTP completed', 'dev.log', $id, 'http'),
            new LogEvent($now, 'notice', 'app', 'Old uncorrelated notice', 'dev.log'),
        ]);

        self::assertSame(2, $result['observedEntries']);
        self::assertSame(1, $result['observedExecutions']);
        self::assertSame(0, $result['knownExecutions']);
        self::assertSame(0, $result['errorEvents']);
        self::assertSame(0, $result['correlatedErrorEvents']);
    }

    public function testMissingIdsAreNotMistakenForOneIncident(): void
    {
        $date = new \DateTimeImmutable();
        $result = (new ExecutionAnalyzer())->summarize([
            new LogEvent($date, 'error', 'app', 'A', 'dev.log'),
            new LogEvent($date, 'error', 'app', 'A', 'dev.log'),
        ]);

        self::assertSame(0, $result['observedEntries']);
        self::assertSame(0, $result['observedExecutions']);
        self::assertSame(0, $result['knownExecutions']);
        self::assertSame(2, $result['uncorrelatedErrorEvents']);
    }
}
