<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Model;

final readonly class LogEvent
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $level,
        public string $channel,
        public string $message,
        public string $source,
        public ?string $executionId = null,
        public ?string $origin = null,
    ) {
    }
}
