<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Model;

final readonly class Issue
{
    public function __construct(
        public string $fingerprint,
        public string $level,
        public string $channel,
        public string $example,
        public int $count,
        public \DateTimeImmutable $firstSeen,
        public \DateTimeImmutable $lastSeen,
    ) {
    }
}
