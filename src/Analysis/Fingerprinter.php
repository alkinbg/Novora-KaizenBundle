<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

final class Fingerprinter
{
    public function fingerprint(string $channel, string $message, string $level = ''): string
    {
        // Preserve numeric HTTP codes, SQLSTATE and exception classes; normalize identifiable entity IDs.
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '{uuid}', $message) ?? $message;
        $value = preg_replace('/\b(id|order|user|entity|request)[ #:=]+[0-9]+\b/i', '$1={id}', $value) ?? $value;
        $value = preg_replace('/\b0x[0-9a-f]+\b/i', '{hex}', $value) ?? $value;
        $value = preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/', '{timestamp}', $value) ?? $value;

        return hash('sha256', strtolower($level)."\0".$channel."\0".$value);
    }
}
