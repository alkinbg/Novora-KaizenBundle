<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\ErrorFamilyClassifier;
use Novora\KaizenBundle\Model\Issue;
use PHPUnit\Framework\TestCase;

final class ErrorFamilyClassifierTest extends TestCase
{
    public function testClassifiesObservationsWithoutClaimingCommonRootCause(): void
    {
        $classifier = new ErrorFamilyClassifier();

        self::assertSame('session', $classifier->classify($this->issue('Exception thrown when handling an exception (RuntimeException: Failed to start the session because headers have already been sent)', 6)));
        self::assertSame('session', $classifier->classify($this->issue('Uncaught PHP Exception ErrorException: "Warning: Cannot modify header information - headers already sent by"', 3)));
        self::assertSame('session', $classifier->classify($this->issue('Session must be a string at NativeSessionStorage.php', 2)));
        self::assertSame('database', $classifier->classify($this->issue('Uncaught PHP Exception Doctrine\DBAL\Exception\ConnectionException: An exception occurred in the driver: SQLSTATE[HY000] [2002]', 2)));
        self::assertSame('twig', $classifier->classify($this->issue('Uncaught PHP Exception Twig\Error\SyntaxError: Unexpected endblock', 1)));
        self::assertSame('console', $classifier->classify($this->issue('Error thrown while running command "debug:container session.handler.native_file" --env=dev. Message: There is no extension', 1)));
        self::assertSame('other', $classifier->classify($this->issue('Unexpected application error hidden from the user', 2)));
    }

    public function testReturnsCountsAndDistinctPatternCounts(): void
    {
        $classifier = new ErrorFamilyClassifier();
        $issues = [
            $this->issue('Failed to start the session', 6),
            $this->issue('Cannot modify header information', 3),
            $this->issue('SQLSTATE[HY000]: connection refused', 2),
            $this->issue('Error thrown while running command "lint:twig"', 1),
            $this->issue('Unrecognized application error', 4),
        ];

        $summary = $classifier->summarize($issues);

        self::assertSame(['session', 'other', 'database', 'console'], array_column($summary, 'key'));
        self::assertSame([9, 4, 2, 1], array_column($summary, 'count'));
        self::assertSame([2, 1, 1, 1], array_column($summary, 'patterns'));
        self::assertSame('Sessions & headers', $summary[0]['label']);
        self::assertSame(16, array_sum(array_column($summary, 'count')));
    }

    public function testUnclassifiedIssuesRemainVisible(): void
    {
        $summary = (new ErrorFamilyClassifier())->summarize([$this->issue('Unknown exception', 1)]);
        self::assertSame('other', $summary[0]['key']);
    }

    private function issue(string $message, int $count): Issue
    {
        $now = new \DateTimeImmutable();

        return new Issue(hash('sha256', $message), 'error', 'request', $message, $count, $now, $now);
    }
}
