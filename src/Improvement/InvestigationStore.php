<?php
declare(strict_types=1);

namespace Novora\KaizenBundle\Improvement;

/**
 * Small, local, file-backed investigation workspace.
 * One JSON document and one lock per case. Not a multi-node storage backend.
 *
 * @phpstan-type CaseData array<string,mixed>
 */
final readonly class InvestigationStore
{
    public function __construct(private string $directory)
    {
    }

    /**
     * Preflight check without mutating the filesystem. Supports a not-yet-
     * created storage directory by checking its nearest existing parent.
     */
    public function isStorageWritable(): bool
    {
        $path = rtrim($this->directory, '/');

        while (!file_exists($path) && $path !== dirname($path)) {
            $path = dirname($path);
        }

        return is_dir($path) && is_readable($path) && is_writable($path);
    }

    /** @return array<string,mixed> */
    public function create(string $title, string $problem, string $fingerprint = ''): array
    {
        $title = $this->requiredText($title);
        $problem = $this->optionalText($problem);
        if ($fingerprint !== '' && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid issue fingerprint.');
        }

        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $case = [
            'id' => $id, 'title' => $title, 'problem' => $problem, 'fingerprint' => $fingerprint,
            'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            'gemba' => [], 'causes' => [], 'whys' => [], 'correction_applied_at' => null,
            'pdca' => ['plan' => '', 'do' => '', 'check' => '', 'act' => ''],
        ];

        return $this->locked($id, static function (?array $existing) use ($case): array {
            if ($existing !== null) {
                throw new \RuntimeException('Case ID collision.');
            }

            return $case;
        });
    }

    /**
     * Avoid repeat investigations for one log pattern. Closed work is historical:
     * a subsequent recurrence may start a fresh investigation.
     *
     * A single file lock protects the lookup-and-create step across workers.
     * Manual cases without a fingerprint are intentionally independent.
     *
     * @return array<string,mixed>
     */
    public function createOrReuse(string $title, string $problem, string $fingerprint = ''): array
    {
        if ($fingerprint === '') {
            return $this->create($title, $problem);
        }

        // Validate before acquiring the lock, including when a case already exists.
        $this->requiredText($title);
        $this->optionalText($problem);
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Invalid issue fingerprint.');
        }

        $this->ensureDirectory();
        $handle = @fopen(rtrim($this->directory, '/').'/issue-create.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Unable to lock investigation creation.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock investigation creation.');
            }

            $active = null;
            foreach (glob(rtrim($this->directory, '/').'/[a-f0-9]*.json') ?: [] as $file) {
                $id = basename($file, '.json');
                if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                    continue;
                }

                $case = $this->find($id);
                if ($case === null || ($case['fingerprint'] ?? '') !== $fingerprint
                    || !in_array($case['status'], ['open', 'investigating', 'verifying'], true)) {
                    continue;
                }

                if ($active === null || strcmp((string) $case['updated_at'], (string) $active['updated_at']) > 0) {
                    $active = $case;
                }
            }

            return $active ?? $this->create($title, $problem, $fingerprint);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }

        return $this->decode((string) file_get_contents($path));
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        if (!is_readable($this->directory)) {
            throw new \RuntimeException('Kaizen storage is not readable by the PHP process. Check var/kaizen ownership and permissions.');
        }

        $cases = [];
        foreach (glob(rtrim($this->directory, '/').'/[a-f0-9]*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                continue;
            }
            $case = $this->find($id);
            if ($case !== null) {
                $cases[] = $case;
            }
        }

        usort($cases, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($cases, 0, 200);
    }

    /** @return array<string,mixed> */
    public function addGemba(string $id, string $observation, string $evidence): array
    {
        return $this->change($id, function (array $case) use ($observation, $evidence): array {
            if (count($case['gemba']) >= 100) {
                throw new \DomainException('Maximum number of observations reached.');
            }

            $case['gemba'][] = [
                'observation' => $this->requiredText($observation),
                'evidence' => $this->optionalText($evidence),
                'at' => gmdate('c'),
            ];

            return $case;
        });
    }

    /** @return array<string,mixed> */
    public function addCause(string $id, string $category, string $hypothesis, string $evidence): array
    {
        $allowed = ['code', 'data', 'infrastructure', 'dependencies', 'process', 'configuration'];
        if (!in_array($category, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown Ishikawa category.');
        }

        return $this->change($id, function (array $case) use ($category, $hypothesis, $evidence): array {
            if (count($case['causes']) >= 100) {
                throw new \DomainException('Maximum number of hypotheses reached.');
            }

            $case['causes'][] = [
                'category' => $category,
                'hypothesis' => $this->requiredText($hypothesis),
                'evidence' => $this->optionalText($evidence),
                'assessment' => 'unverified',
                'at' => gmdate('c'),
            ];

            return $case;
        });
    }

    /** @return array<string,mixed> */
    public function assessCause(string $id, int $index, string $assessment, string $evidence): array
    {
        if (!in_array($assessment, ['unverified', 'confirmed', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unknown assessment.');
        }
        if ($assessment === 'confirmed' && trim($evidence) === '') {
            throw new \InvalidArgumentException('Evidence is required to confirm a cause.');
        }

        return $this->change($id, function (array $case) use ($index, $assessment, $evidence): array {
            if (!isset($case['causes'][$index])) {
                throw new \OutOfBoundsException('Unknown hypothesis.');
            }

            $case['causes'][$index]['assessment'] = $assessment;
            $case['causes'][$index]['evidence'] = $this->optionalText($evidence);

            return $case;
        });
    }

    /** @return array<string,mixed> */
    public function setWhy(string $id, int $step, string $answer): array
    {
        if ($step < 1 || $step > 5) {
            throw new \InvalidArgumentException('5 Whys has five steps.');
        }

        return $this->change($id, function (array $case) use ($step, $answer): array {
            $case['whys'][(string) $step] = $this->optionalText($answer);

            return $case;
        });
    }

    /** @return array<string,mixed> */
    public function setPdca(string $id, string $phase, string $description): array
    {
        if (!in_array($phase, ['plan', 'do', 'check', 'act'], true)) {
            throw new \InvalidArgumentException('Unknown PDCA phase.');
        }

        return $this->change($id, function (array $case) use ($phase, $description): array {
            $case['pdca'][$phase] = $this->optionalText($description);

            return $case;
        });
    }

    /**
     * Record an explicitly documented correction time. No automatic close or
     * inferred resolution state is applied to the investigation.
     *
     * @return array<string,mixed>
     */
    public function recordCorrection(string $id, \DateTimeImmutable $at): array
    {
        if ($at > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Correction time cannot be in the future.');
        }

        return $this->change($id, static function (array $case) use ($at): array {
            if (($case['fingerprint'] ?? '') === '') {
                throw new \DomainException('This case must be linked to a log fingerprint.');
            }

            $case['correction_applied_at'] = $at->setTimezone(new \DateTimeZone('UTC'))->format('c');

            return $case;
        });
    }

    /** @return array<string,mixed> */
    public function setStatus(string $id, string $status): array
    {
        if (!in_array($status, ['open', 'investigating', 'verifying', 'closed'], true)) {
            throw new \InvalidArgumentException('Unknown status.');
        }

        return $this->change($id, static function (array $case) use ($status): array {
            $case['status'] = $status;

            return $case;
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed>
     */
    private function change(string $id, callable $mutator): array
    {
        return $this->locked($id, static function (?array $case) use ($mutator): array {
            if ($case === null) {
                throw new \OutOfBoundsException('Investigation not found.');
            }

            $changed = $mutator($case);
            $changed['updated_at'] = gmdate('c');

            return $changed;
        });
    }

    /** @param callable(?array<string,mixed>):array<string,mixed> $transform
     * @return array<string,mixed>
     */
    private function locked(string $id, callable $transform): array
    {
        $path = $this->path($id);
        $this->ensureDirectory();
        $handle = @fopen($path.'.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open investigation lock.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock investigation.');
            }

            $current = is_file($path) ? $this->decode((string) file_get_contents($path)) : null;
            $changed = $transform($current);
            $json = json_encode($changed, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

            try {
                if (@file_put_contents($tmp, $json) !== strlen($json)) {
                    throw new \RuntimeException('Unable to persist investigation.');
                }
                if (!@chmod($tmp, 0600)) {
                    throw new \RuntimeException('Unable to protect investigation data file.');
                }
                if (!@rename($tmp, $path)) {
                    throw new \RuntimeException('Unable to commit investigation.');
                }
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }

            return $changed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid investigation identifier.');
        }

        return rtrim($this->directory, '/').'/'.$id.'.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create Kaizen storage directory. Check the PHP process filesystem permissions.');
        }
        if (!$this->isStorageWritable()) {
            throw new \RuntimeException('Kaizen storage is not writable by the PHP process. Check var/kaizen ownership and permissions (do not use chmod 777).');
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !isset($value['id'], $value['status'])) {
            throw new \UnexpectedValueException('Invalid investigation document.');
        }

        return $value;
    }

    private function requiredText(string $text): string
    {
        $text = $this->optionalText($text);
        if ($text === '') {
            throw new \InvalidArgumentException('This field is required.');
        }

        return $text;
    }

    private function optionalText(string $text): string
    {
        $text = trim($text);
        if (strlen($text) > 4000) {
            throw new \InvalidArgumentException('Text exceeds maximum length.');
        }

        return $text;
    }
}
