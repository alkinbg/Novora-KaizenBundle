<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Command;

use Novora\KaizenBundle\Command\AnalyzeLogsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeLogsCommandTest extends TestCase
{
    public function testCliRedactsSensitiveContentAndStillReportsUsefulIssues(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kaizen_cli_');
        self::assertNotFalse($path);
        try {
            $message = 'Uncaught Symfony Exception: SQLSTATE[HY000] failure. '
                .'Credentials {"password":"this-is-private","access_token":"tok-very-secret"}';
            $record = json_encode([
                'datetime' => '2026-10-09T12:00:00+03:00',
                'level_name' => 'ERROR',
                'channel' => 'request',
                'message' => $message,
            ], JSON_THROW_ON_ERROR);
            file_put_contents($path, $record."\n");

            $tester = new CommandTester(new AnalyzeLogsCommand());
            $status = $tester->execute(['file' => $path]);

            self::assertSame(Command::SUCCESS, $status);
            $output = $tester->getDisplay();
            self::assertStringContainsString('SQLSTATE', $output);
            self::assertStringContainsString('[REDACTED]', $output);
            self::assertStringNotContainsString('this-is-private', $output);
            self::assertStringNotContainsString('tok-very-secret', $output);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsInvalidByteLimit(): void
    {
        $tester = new CommandTester(new AnalyzeLogsCommand());
        $status = $tester->execute(['file' => '/dev/null', '--max-bytes' => '999999999']);

        self::assertSame(Command::INVALID, $status);
    }
}
