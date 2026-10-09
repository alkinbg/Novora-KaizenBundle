<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Correlation;

use Novora\KaizenBundle\Correlation\ExecutionContext;
use Novora\KaizenBundle\Correlation\MessengerExecutionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

final class MessengerExecutionSubscriberTest extends TestCase
{
    public function testHandlesSeparateWorkerMessagesAndClearsContextOnFailure(): void
    {
        $context = new ExecutionContext();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MessengerExecutionSubscriber($context));

        $first = new Envelope(new \stdClass());
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($first, 'async'));
        $firstId = $context->messageId();
        self::assertNotNull($firstId);

        $dispatcher->dispatch(new WorkerMessageHandledEvent($first, 'async'));
        self::assertNull($context->messageId());

        $second = new Envelope(new \stdClass());
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($second, 'async'));
        self::assertNotNull($context->messageId());
        self::assertNotSame($firstId, $context->messageId());
        $dispatcher->dispatch(new WorkerMessageFailedEvent($second, 'async', new \RuntimeException('Expected test failure')));
        self::assertNull($context->messageId());
    }

    public function testWorkerRunningClearsScopeForSkippedMessages(): void
    {
        $context = new ExecutionContext();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MessengerExecutionSubscriber($context));

        $received = new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');
        $dispatcher->dispatch($received);
        $received->shouldHandle(false);
        self::assertNotNull($context->messageId());

        $worker = (new \ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $dispatcher->dispatch(new WorkerRunningEvent($worker, false));

        self::assertNull($context->messageId());
    }
}
