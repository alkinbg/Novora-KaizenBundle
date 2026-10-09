<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Correlation;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Enabled only if Symfony Messenger is installed and correlation is opted in.
 */
final readonly class MessengerExecutionSubscriber implements EventSubscriberInterface
{
    public function __construct(private ExecutionContext $context)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onReceived', 2048],
            WorkerMessageHandledEvent::class => ['onFinished', -2048],
            WorkerMessageFailedEvent::class => ['onFinished', -2048],
            WorkerRunningEvent::class => ['onWorkerRunning', -2048],
        ];
    }

    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->context->enterMessage();
    }

    public function onFinished(WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void
    {
        $this->context->leaveMessage();
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // Covers messages deliberately skipped by another listener, which
        // may never produce a handled/failed event.
        $this->context->leaveMessage();
    }
}
