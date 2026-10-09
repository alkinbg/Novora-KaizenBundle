<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Correlation;

final class ExecutionContext
{
    private ?string $messageId = null;

    public function enterMessage(): void
    {
        $this->messageId = bin2hex(random_bytes(16));
    }

    public function leaveMessage(): void
    {
        $this->messageId = null;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }
}
