<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Correlation;

use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds only a random execution identifier and origin: no URLs, cookies,
 * request bodies or user attributes are collected.
 */
final class ExecutionIdProcessor
{
    private ?string $commandId = null;

    /** @var \WeakMap<\Symfony\Component\HttpFoundation\Request, string> */
    private \WeakMap $requestIds;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ExecutionContext $context,
    ) {
        $this->requestIds = new \WeakMap();
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->context->messageId() !== null) {
            $id = $this->context->messageId();
            $origin = 'messenger';
        } elseif (($request = $this->requestStack->getMainRequest()) !== null) {
            $id = $this->requestIds[$request] ??= bin2hex(random_bytes(16));
            $origin = 'http';
        } elseif (\PHP_SAPI === 'cli') {
            $id = $this->commandId ??= bin2hex(random_bytes(16));
            $origin = 'cli';
        } else {
            return $record;
        }

        $record->extra['kaizen_execution_id'] = $id;
        $record->extra['kaizen_origin'] = $origin;

        return $record;
    }
}
