<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Source;

use Novora\KaizenBundle\Model\LogEvent;

final class MonologFileReader
{
    /**
     * Reads at most $maxBytes from the end of a trusted, explicitly supplied log path.
     * @return list<LogEvent>
     */
    public function read(string $path, int $maxBytes = 2_097_152): array
    {
        if ($maxBytes < 1 || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $size = $file->getSize();
        $offset = max(0, $size - $maxBytes);
        $file->fseek($offset);

        if ($offset > 0) {
            $file->fgets(); // Discard a partial record.
        }

        $events = [];
        while (!$file->eof()) {
            $line = trim($file->fgets());
            if ($line === '') {
                continue;
            }

            $event = $this->parse($line, $path);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function parse(string $line, string $source): ?LogEvent
    {
        $data = json_decode($line, true);
        if (is_array($data) && isset($data['message'], $data['level_name'], $data['datetime'])) {
            try {
                return new LogEvent(
                    new \DateTimeImmutable((string) $data['datetime']),
                    strtolower((string) $data['level_name']),
                    (string) ($data['channel'] ?? 'app'),
                    (string) $data['message'],
                    $source,
                    ...$this->correlation($data['extra'] ?? null),
                );
            } catch (\Exception) {
                return null;
            }
        }

        if (preg_match('/^\[(?<date>[^]]+)] (?<channel>[^. ]+)\.(?<level>[A-Z]+): (?<message>.*)$/', $line, $match) !== 1) {
            return null;
        }

        $message = $match['message'];
        $extra = null;

        // Only peel off an actual validated Monolog extra object. It may
        // contain other processors' nested JSON in addition to Kaizen fields.
        [$message, $extra] = $this->extractLineExtra($message);

        try {
            return new LogEvent(
                new \DateTimeImmutable($match['date']),
                strtolower($match['level']),
                $match['channel'],
                $message,
                $source,
                ...$this->correlation($extra),
            );
        } catch (\Exception) {
            return null;
        }
    }
    /**
     * @return array{string,?array}
     */
    private function extractLineExtra(string $message): array
    {
        if (!str_contains($message, 'kaizen_execution_id')) {
            return [$message, null];
        }

        $cursor = strlen($message);
        for ($attempts = 0; $attempts < 32; ++$attempts) {
            $start = strrpos(substr($message, 0, $cursor), ' {');
            if ($start === false) {
                break;
            }

            // Correlation metadata is tiny. Never decode unbounded log payloads.
            if (strlen($message) - $start - 1 > 8192) {
                break;
            }

            $decoded = json_decode(substr($message, $start + 1), true);
            if (is_array($decoded) && $this->correlation($decoded)[0] !== null) {
                return [substr($message, 0, $start).' []', $decoded];
            }

            $cursor = $start;
        }

        return [$message, null];
    }

    /**
     * @return array{?string,?string}
     */
    private function correlation(mixed $extra): array
    {
        if (!is_array($extra)) {
            return [null, null];
        }

        $id = $extra['kaizen_execution_id'] ?? null;
        $origin = $extra['kaizen_origin'] ?? null;

        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1
            || !in_array($origin, ['http', 'messenger', 'cli'], true)) {
            return [null, null];
        }

        return [$id, $origin];
    }

}
