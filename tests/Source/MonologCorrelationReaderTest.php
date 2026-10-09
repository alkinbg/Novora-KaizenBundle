<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Source;

use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Novora\KaizenBundle\Analysis\ParetoAnalyzer;
use Novora\KaizenBundle\Source\MonologFileReader;
use PHPUnit\Framework\TestCase;

final class MonologCorrelationReaderTest extends TestCase
{
    public function testParsesMonologLineFormatterWithoutBreakingFingerprinting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaizen_correlation_');
        self::assertNotFalse($file);
        try {
            $record = new LogRecord(new \DateTimeImmutable('2026-10-09T10:00:00+03:00'), 'request', Level::Error, 'Database failure');
            $formatter = new LineFormatter();
            $oldLine = $formatter->format($record);
            $record->extra['kaizen_execution_id'] = str_repeat('a', 32);
            $record->extra['kaizen_origin'] = 'http';
            $record->extra['other_processor'] = ['nested' => ['key' => 'value']];
            $newLine = $formatter->format($record);
            file_put_contents($file, $oldLine.$newLine);

            $events = (new MonologFileReader())->read($file);
            self::assertCount(2, $events);
            self::assertNull($events[0]->executionId);
            self::assertSame(str_repeat('a', 32), $events[1]->executionId);
            self::assertSame('http', $events[1]->origin);
            self::assertSame($events[0]->message, $events[1]->message);

            $issues = (new ParetoAnalyzer())->analyze($events);
            self::assertCount(1, $issues);
            self::assertSame(2, $issues[0]->count);
        } finally {
            unlink($file);
        }
    }

    public function testJsonFormatterMetadataAndInvalidIdsDoNotImplyCorrelation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaizen_correlation_');
        self::assertNotFalse($file);
        try {
            $lines = [
                [
                    'datetime' => '2026-10-09T10:00:00+03:00', 'level_name' => 'ERROR', 'channel' => 'request',
                    'message' => 'A failure',
                    'extra' => ['kaizen_execution_id' => str_repeat('b', 32), 'kaizen_origin' => 'cli'],
                ],
                [
                    'datetime' => '2026-10-09T10:01:00+03:00', 'level_name' => 'ERROR', 'channel' => 'request',
                    'message' => 'Other failure',
                    'extra' => ['kaizen_execution_id' => '../../etc/passwd', 'kaizen_origin' => 'cli'],
                ],
            ];
            file_put_contents($file, implode("\n", array_map(
                static fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR),
                $lines,
            )));

            $events = (new MonologFileReader())->read($file);
            self::assertCount(2, $events);
            self::assertSame(str_repeat('b', 32), $events[0]->executionId);
            self::assertSame('cli', $events[0]->origin);
            self::assertNull($events[1]->executionId);
        } finally {
            unlink($file);
        }
    }
}
