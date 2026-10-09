<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Analysis;

use Novora\KaizenBundle\Model\Issue;

/**
 * Groups error messages by observable symptom, not by inferred root cause.
 * Intentionally conservative: unmatched messages stay in "Other errors".
 */
final class ErrorFamilyClassifier
{
    /** @var array<string, string> */
    private const LABELS = [
        'session' => 'Sessions & headers',
        'database' => 'Database / SQL',
        'twig' => 'Twig & templates',
        'console' => 'CLI & tooling',
        'other' => 'Other errors',
    ];

    /** @return array<string, string> */
    public function labels(): array
    {
        return self::LABELS;
    }

    public function classify(Issue $issue): string
    {
        $message = $issue->example;

        if (preg_match('/\b(?:error|exception) thrown while running command\b/i', $message) === 1
            || ($issue->channel === 'console' && preg_match('/\bcommand\b/i', $message) === 1)) {
            return 'console';
        }

        if (preg_match('/(?:failed to start the session|headers? (?:have )?already been sent|cannot modify header information|session must be a string|nativesessionstorage|sessionhandler|sessionstorage|session.*cookie)/i', $message) === 1) {
            return 'session';
        }

        if (preg_match('/(?:sqlstate\[|doctrine\\\\dbal\\\\|connectionexception|deadlock found|lock wait timeout)/i', $message) === 1) {
            return 'database';
        }

        if (preg_match('/(?:twig\\\\(?:error|syntax|runtime)\\\\|twig.*(?:syntaxerror|runtimeerror|loadererror))/i', $message) === 1) {
            return 'twig';
        }

        return 'other';
    }

    /**
     * @param list<Issue> $issues
     * @return list<array{key:string,label:string,count:int,patterns:int}>
     */
    public function summarize(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $family = $this->classify($issue);
            $groups[$family] ??= ['key' => $family, 'label' => self::LABELS[$family], 'count' => 0, 'patterns' => 0];
            $groups[$family]['count'] += $issue->count;
            ++$groups[$family]['patterns'];
        }

        $result = array_values($groups);
        usort($result, static fn (array $a, array $b): int => ($b['count'] <=> $a['count'])
            ?: strcmp($a['key'], $b['key']));

        return $result;
    }
}
