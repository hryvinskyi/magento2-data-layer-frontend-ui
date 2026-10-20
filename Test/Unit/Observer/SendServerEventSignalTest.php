<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Observer;

use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingSignal;
use Hryvinskyi\DataLayerFrontendUi\Api\Response\PageCacheabilityInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Signal\PendingSignalInterface;
use Hryvinskyi\DataLayerFrontendUi\Observer\SendServerEventSignal;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * Right before a response is sent: a cacheable response drops the signal and never looks into the queue; any other
 * response sends it, after signalling events waiting in the session of a visitor who has one when an action ran,
 * except on ignored routes; a failure to look is logged only
 */
class SendServerEventSignalTest extends TestCase
{
    /**
     * @var list<string> What happened to the pending signal
     */
    private array $pending = [];

    /**
     * @var bool
     */
    private bool $cacheable = false;

    /**
     * @var bool
     */
    private bool $hasSession = true;

    /**
     * @var bool Whether an action ran in the request
     */
    private bool $dispatched = true;

    /**
     * @var int How often the queue was looked into
     */
    private int $looks = 0;

    /**
     * A response that cannot be cached is sent with the signal of events waiting in the open session
     *
     * @return void
     */
    public function testUncacheableResponseSignalsWaitingEventsAndSends(): void
    {
        $signal = new RecordingSignal();

        $this->observer($signal, true)->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame(['signal'], $signal->calls);
        self::assertSame(['send'], $this->pending);
    }

    /**
     * An empty queue asks for nothing, and what the request asked for is still sent
     *
     * @return void
     */
    public function testEmptyQueueStillSendsWhatTheRequestAskedFor(): void
    {
        $signal = new RecordingSignal();

        $this->observer($signal, false)->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame([], $signal->calls);
        self::assertSame(['send'], $this->pending);
    }

    /**
     * A response the full-page cache may store drops the signal and never looks into the queue
     *
     * @return void
     */
    public function testCacheableResponseDropsTheSignal(): void
    {
        $this->cacheable = true;
        $signal = new RecordingSignal();

        $this->observer($signal, true)->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame(['discard'], $this->pending);
        self::assertSame(0, $this->looks);
        self::assertSame([], $signal->calls);
    }

    /**
     * Something that is not an HTTP response drops the signal
     *
     * @return void
     */
    public function testNoHttpResponseDropsTheSignal(): void
    {
        $this->observer(new RecordingSignal(), true)->execute($this->event(new \stdClass()));

        self::assertSame(['discard'], $this->pending);
        self::assertSame(0, $this->looks);
    }

    /**
     * A visitor without a session never has the queue looked into, so no session is created
     *
     * @return void
     */
    public function testVisitorWithoutASessionIsNeverLookedInto(): void
    {
        $this->hasSession = false;
        $signal = new RecordingSignal();

        $this->observer($signal, true)->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame(0, $this->looks);
        self::assertSame([], $signal->calls);
        self::assertSame(['send'], $this->pending);
    }

    /**
     * A response no action produced (a page served from the cache) never looks into the queue, and still sends what
     * the request asked for
     *
     * @return void
     */
    public function testResponseWithoutAnActionIsNeverLookedInto(): void
    {
        $this->dispatched = false;

        $this->observer(new RecordingSignal(), true)->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame(0, $this->looks);
        self::assertSame(['send'], $this->pending);
    }

    /**
     * An ignored route, and a request that is not a storefront action, never look into the queue
     *
     * @return void
     */
    public function testIgnoredRouteAndOtherRequestsAreNotLookedInto(): void
    {
        $this->observer(new RecordingSignal(), true, 'hryvinskyi_datalayer')
            ->execute($this->event($this->createMock(HttpInterface::class)));
        $this->observer(new RecordingSignal(), true, 'checkout', $this->createMock(RequestInterface::class))
            ->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertSame(0, $this->looks);
        self::assertSame(['send', 'send'], $this->pending);
    }

    /**
     * A queue that cannot be read is logged and the response is still sent
     *
     * @return void
     */
    public function testQueueFailureIsLoggedOnly(): void
    {
        $logger = new RecordingLogger();
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->method('hasEvents')->willThrowException(new \RuntimeException('session storage down'));

        $this->observer(new RecordingSignal(), true, 'checkout', null, $queue, $logger)
            ->execute($this->event($this->createMock(HttpInterface::class)));

        self::assertCount(1, $logger->records);
        self::assertSame('session storage down', $logger->records[0]['context']['reason']);
        self::assertSame(['send'], $this->pending);
    }

    /**
     * Build the observer over fakes
     *
     * @param RecordingSignal $signal
     * @param bool $waiting Whether the queue holds events
     * @param string $route The request's route name
     * @param RequestInterface|null $request
     * @param ServerEventDrainInterface|null $queue
     * @param RecordingLogger|null $logger
     * @return SendServerEventSignal
     */
    private function observer(
        RecordingSignal $signal,
        bool $waiting,
        string $route = 'checkout',
        ?RequestInterface $request = null,
        ?ServerEventDrainInterface $queue = null,
        ?RecordingLogger $logger = null
    ): SendServerEventSignal {
        $http = $this->createMock(Http::class);
        $http->method('getRouteName')->willReturn($route);
        $http->method('isDispatched')->willReturnCallback(fn (): bool => $this->dispatched);
        $cacheability = $this->createMock(PageCacheabilityInterface::class);
        $cacheability->method('isCacheable')->willReturnCallback(fn (): bool => $this->cacheable);
        $pending = $this->createMock(PendingSignalInterface::class);
        $pending->method('send')->willReturnCallback(function (): void {
            $this->pending[] = 'send';
        });
        $pending->method('discard')->willReturnCallback(function (): void {
            $this->pending[] = 'discard';
        });
        $presence = $this->createMock(SessionPresenceInterface::class);
        $presence->method('exists')->willReturnCallback(fn (): bool => $this->hasSession);
        if ($queue === null) {
            $queue = $this->createMock(ServerEventDrainInterface::class);
            $queue->method('hasEvents')->willReturnCallback(function () use ($waiting): bool {
                $this->looks++;

                return $waiting;
            });
        }

        return new SendServerEventSignal(
            $request ?? $http,
            $cacheability,
            $pending,
            $signal,
            $presence,
            $queue,
            $logger ?? new RecordingLogger(),
            ['hryvinskyi_datalayer' => true]
        );
    }

    /**
     * The send-response event carrying a response
     *
     * @param object $response
     * @return Observer
     */
    private function event(object $response): Observer
    {
        $observer = new Observer();
        $observer->setEvent(new Event(['response' => $response]));

        return $observer;
    }
}
