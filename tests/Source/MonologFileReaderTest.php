<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Source;

use Novora\KaizenBundle\Source\MonologFileReader;
use PHPUnit\Framework\TestCase;

final class MonologFileReaderTest extends TestCase
{
    public function testReadsStructuredLinesAndSkipsUnrecognizedData(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaizen_');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, implode("\n", [
                '[2026-10-09T10:00:00+03:00] request.ERROR: Something failed [] []',
                '{"message":"Another problem","level_name":"ERROR","datetime":"2026-10-09T10:01:00+03:00","channel":"app"}',
                'this is not a structured log line',
                '',
            ]));

            $events = (new MonologFileReader())->read($file);
            self::assertCount(2, $events);
            self::assertSame('request', $events[0]->channel);
            self::assertSame('Another problem', $events[1]->message);
        } finally {
            unlink($file);
        }
    }

    public function testMissingFileProducesNoEvents(): void
    {
        self::assertSame([], (new MonologFileReader())->read('/nonexistent/kaizen.log'));
    }
}
