<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

/**
 * Best-effort defensive display redaction; applications must not log secrets.
 * Always sanitize before truncation, including in expanded error messages.
 */
final class LogRedactor
{
    public function redact(string $message, int $maxLength = 240): string
    {
        if ($maxLength < 1 || $maxLength > 4096) {
            throw new \InvalidArgumentException('Display length must be between 1 and 4096.');
        }

        // Hide the entire credential, not just the authentication scheme.
        $message = preg_replace('/\b(Bearer|Basic)\s+[^\s"<>]+/i', '$1 [REDACTED]', $message) ?? $message;

        // Both structured exception contexts and simple key=value pairs.
        // Accept the closing quote around keys in JSON.
        $message = preg_replace(
            '/\b(password|passwd|passphrase|api[_-]?key|secret|client[_-]?secret|access[_-]?token|refresh[_-]?token|authorization|cookie|session[_-]?id)\b(["\x27]?\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^\s&,}]+)/i',
            '$1$2[REDACTED]',
            $message,
        ) ?? $message;

        // Query strings in logged URLs.
        $message = preg_replace(
            '/([?&](?:token|api[_-]?key|access[_-]?token|refresh[_-]?token|session[_-]?id)=)[^&#\s"<>]+/i',
            '$1[REDACTED]',
            $message,
        ) ?? $message;

        $message = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[EMAIL]', $message) ?? $message;

        return mb_substr($message, 0, $maxLength);
    }
}
