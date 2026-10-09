<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Analysis;

use Novora\KaizenBundle\Analysis\LogRedactor;
use PHPUnit\Framework\TestCase;

final class LogRedactorTest extends TestCase
{
    public function testRedactsStructuredJsonContextAndUrlSecretsBeforeAnyTruncation(): void
    {
        $message = 'Session error {"password":"private123","access_token":"abc321","authorization":"Bearer hello","email":"joe@example.org"} '
            .'https://example.org/callback?token=abc123&api_key=hello123&state=ok';
        $sanitized = (new LogRedactor())->redact($message, 4096);

        foreach (['private123', 'abc321', 'hello123', 'joe@example.org'] as $secret) {
            self::assertStringNotContainsString($secret, $sanitized);
        }
        self::assertStringContainsString('Session error', $sanitized);
        self::assertStringContainsString('[REDACTED]', $sanitized);
        self::assertStringContainsString('[EMAIL]', $sanitized);
    }

    public function testDetailedOutputDoesNotTruncateRootCauseAtTwoHundredFortyCharacters(): void
    {
        $message = 'Symfony exception '.str_repeat('context ', 40).'root-cause-at-the-end';
        $redactor = new LogRedactor();

        self::assertStringNotContainsString('root-cause-at-the-end', $redactor->redact($message));
        self::assertStringContainsString('root-cause-at-the-end', $redactor->redact($message, 4096));
        self::assertLessThanOrEqual(4096, mb_strlen($redactor->redact(str_repeat('long ', 2000), 4096)));
    }

    public function testRejectsUnboundedDisplayLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LogRedactor())->redact('log', 100_000);
    }

    public function testRedactsCommonSecretsAndPersonalEmail(): void
    {
        $result = (new LogRedactor())->redact('Authorization: Bearer token123 api_key=abcd password="test" alkin@example.com');
        self::assertStringNotContainsString('token123', $result);
        self::assertStringNotContainsString('abcd', $result);
        self::assertStringNotContainsString('test"', $result);
        self::assertStringNotContainsString('alkin@example.com', $result);
        self::assertStringContainsString('[REDACTED]', $result);
    }
}
