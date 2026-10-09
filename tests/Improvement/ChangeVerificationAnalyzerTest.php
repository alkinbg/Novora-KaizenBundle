<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Improvement;

use Novora\KaizenBundle\Analysis\Fingerprinter;
use Novora\KaizenBundle\Improvement\ChangeVerificationAnalyzer;
use Novora\KaizenBundle\Model\LogEvent;
use PHPUnit\Framework\TestCase;

final class ChangeVerificationAnalyzerTest extends TestCase
{
    private const MESSAGE = 'SessionHandler::gc() failed: Permission denied (13)';

    public function testEqualWindowsAndNoRecurrenceRequireObservedBaselineAndLaterActivity(): void
    {
        $changed = new \DateTimeImmutable('2026-10-09T12:00:00+03:00');
        $now = new \DateTimeImmutable('2026-10-09T13:00:00+03:00');
        $events = [
            $this->event('2026-10-09T11:00:00+03:00', 'info', 'Other work'),
            $this->event('2026-10-09T11:10:00+03:00', 'error', self::MESSAGE),
            $this->event('2026-10-09T11:45:00+03:00', 'error', self::MESSAGE),
            $this->event('2026-10-09T12:01:00+03:00', 'info', 'App still active'),
            $this->event('2026-10-09T12:55:00+03:00', 'debug', 'More app activity'),
        ];

        $result = (new ChangeVerificationAnalyzer())->analyze($events, $this->fingerprint(), $changed, $now);
        self::assertSame('no_recurrence_observed', $result['status']);
        self::assertSame(2, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame(0, $result['since']);
        self::assertSame(3, $result['beforeEvents']);
        self::assertSame(2, $result['afterEvents']);
        self::assertTrue($result['completeBefore']);
        self::assertTrue($result['sampledAfter']);
        self::assertSame(1.0, $result['hours']);
        self::assertSame('2026-10-09 11:10:00', $result['firstSeen']->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-09 11:45:00', $result['lastSeen']->format('Y-m-d H:i:s'));
    }

    public function testLateRecurrenceIsNotHiddenByFirstTwentyFourHours(): void
    {
        $changed = new \DateTimeImmutable('2026-10-09T12:00:00+03:00');
        $now = new \DateTimeImmutable('2026-10-11T13:00:00+03:00');
        $events = [
            $this->event('2026-10-08T12:00:00+03:00', 'info', 'Baseline start'),
            $this->event('2026-10-09T11:59:00+03:00', 'error', self::MESSAGE),
            $this->event('2026-10-09T20:00:00+03:00', 'info', 'First-day work'),
            $this->event('2026-10-11T12:00:00+03:00', 'error', self::MESSAGE),
        ];

        $result = (new ChangeVerificationAnalyzer())->analyze($events, $this->fingerprint(), $changed, $now);
        self::assertSame('recurrence_observed', $result['status']);
        self::assertSame(1, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame(1, $result['since']);
        self::assertTrue($result['postWindowLimited']);
    }

    public function testMissingBaselineOrPostChangeActivityNeverImpliesResolution(): void
    {
        $changed = new \DateTimeImmutable('2026-10-09T12:00:00+03:00');
        $now = new \DateTimeImmutable('2026-10-09T13:00:00+03:00');
        $partial = (new ChangeVerificationAnalyzer())->analyze([
            $this->event('2026-10-09T11:45:00+03:00', 'error', self::MESSAGE),
            $this->event('2026-10-09T12:30:00+03:00', 'info', 'Something still logged'),
        ], $this->fingerprint(), $changed, $now);

        self::assertSame('insufficient_evidence', $partial['status']);
        self::assertFalse($partial['completeBefore']);

        $idle = (new ChangeVerificationAnalyzer())->analyze([
            $this->event('2026-10-09T10:59:00+03:00', 'info', 'Before activity'),
            $this->event('2026-10-09T11:20:00+03:00', 'error', self::MESSAGE),
        ], $this->fingerprint(), $changed, $now);

        self::assertSame('insufficient_evidence', $idle['status']);
        self::assertFalse($idle['sampledAfter']);
    }

    public function testInvalidFingerprintIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ChangeVerificationAnalyzer())->analyze([], 'not-a-fingerprint', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable());
    }

    private function fingerprint(): string
    {
        return (new Fingerprinter())->fingerprint('request', self::MESSAGE, 'error');
    }

    private function event(string $at, string $level, string $message): LogEvent
    {
        return new LogEvent(new \DateTimeImmutable($at), $level, 'request', $message, 'dev.log');
    }
}
