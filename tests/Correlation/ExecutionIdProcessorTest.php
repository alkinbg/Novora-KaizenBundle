<?php

declare(strict_types=1);

namespace Novora\KaizenBundle\Tests\Correlation;

use Monolog\Level;
use Monolog\LogRecord;
use Novora\KaizenBundle\Correlation\ExecutionContext;
use Novora\KaizenBundle\Correlation\ExecutionIdProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ExecutionIdProcessorTest extends TestCase
{
    public function testSameHttpRequestHasOneIdAndNextRequestHasAnother(): void
    {
        $stack = new RequestStack();
        $processor = new ExecutionIdProcessor($stack, new ExecutionContext());

        $stack->push(Request::create('/a?token=do-not-collect'));
        $first = $processor($this->record());
        $second = $processor($this->record());

        self::assertSame($first->extra['kaizen_execution_id'], $second->extra['kaizen_execution_id']);
        self::assertSame('http', $first->extra['kaizen_origin']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first->extra['kaizen_execution_id']);
        self::assertArrayNotHasKey('url', $first->extra);
        self::assertStringNotContainsString('do-not-collect', json_encode($first->extra, JSON_THROW_ON_ERROR));

        $stack->pop();
        $stack->push(Request::create('/b'));
        $third = $processor($this->record());
        self::assertNotSame($first->extra['kaizen_execution_id'], $third->extra['kaizen_execution_id']);
    }

    public function testMessengerScopeOverridesHttpAndResets(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/http'));
        $context = new ExecutionContext();
        $processor = new ExecutionIdProcessor($stack, $context);

        $http = $processor($this->record());
        $context->enterMessage();
        $message = $processor($this->record());
        self::assertSame('messenger', $message->extra['kaizen_origin']);
        self::assertNotSame($http->extra['kaizen_execution_id'], $message->extra['kaizen_execution_id']);

        $context->leaveMessage();
        $restored = $processor($this->record());
        self::assertSame($http->extra['kaizen_execution_id'], $restored->extra['kaizen_execution_id']);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'A failure');
    }
}
