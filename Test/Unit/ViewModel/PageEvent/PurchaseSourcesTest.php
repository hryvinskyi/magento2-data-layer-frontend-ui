<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel\PageEvent;

use Hryvinskyi\DataLayer\Api\Event\PurchaseEventBuilderInterface;
use Hryvinskyi\DataLayer\Api\PurchasePageEventsInterface;
use Hryvinskyi\DataLayer\Model\Data\Event;
use Hryvinskyi\DataLayer\Model\PageEvent\PurchasePageEvents;
use Hryvinskyi\DataLayer\Model\PurchaseLedger;
use Hryvinskyi\DataLayer\Model\Session\VisitorSession;
use Hryvinskyi\DataLayer\Test\Unit\Model\FakeSession;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\MultishippingPurchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\Purchase;
use Hryvinskyi\DataLayerApi\Api\Data\EventChannel;
use Hryvinskyi\DataLayerApi\Api\Data\EventInterface;
use Hryvinskyi\DataLayerApi\Api\EventChannelPolicyInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Multishipping\Model\Checkout\Type\Multishipping;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * The success page sources report each order once per session and never break the page
 */
class PurchaseSourcesTest extends TestCase
{
    /**
     * @var FakeSession
     */
    private FakeSession $session;

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->session = new FakeSession();
        $this->logger = new RecordingLogger();
    }

    /**
     * The success page reports the last order once; a reload reports nothing
     *
     * @return void
     */
    public function testSuccessPageReportsOnceAndNotOnReload(): void
    {
        $source = new Purchase($this->checkoutSession($this->order(7, '100')), $this->purchases(), $this->logger);

        self::assertSame(['100'], $this->transactionIds($source->events()));
        self::assertSame([], $source->events());
    }

    /**
     * A session without a placed order reports nothing
     *
     * @return void
     */
    public function testNoLastOrder(): void
    {
        $source = new Purchase($this->checkoutSession($this->order(0, '')), $this->purchases(), $this->logger);

        self::assertSame([], $source->events());
    }

    /**
     * A failure is logged with the order and the page gets nothing
     *
     * @return void
     */
    public function testFailureIsLogged(): void
    {
        $purchases = $this->createMock(PurchasePageEventsInterface::class);
        $purchases->method('forOrders')->willThrowException(new \RuntimeException('boom'));

        $source = new Purchase($this->checkoutSession($this->order(7, '100')), $purchases, $this->logger);

        self::assertSame([], $source->events());
        self::assertSame('100', $this->logger->records[0]['context']['order']);
    }

    /**
     * The multiple-address success page reports one event per placed order, in placement order, except orders already
     * reported in the session
     *
     * @return void
     */
    public function testMultishippingReportsOnePerOrder(): void
    {
        (new PurchaseLedger(new VisitorSession($this->session)))->markReported('102');
        $source = $this->multishippingSource(
            [7 => '100', 9 => '102', 8 => '101'],
            [$this->order(9, '102'), $this->order(8, '101'), $this->order(7, '100')]
        );

        self::assertSame(['100', '101'], $this->transactionIds($source->events()));
        self::assertSame([], $source->events(), 'a reload reports nothing');
    }

    /**
     * Without order ids the orders are not even read; a failure is logged with the ids
     *
     * @return void
     */
    public function testMultishippingWithoutOrdersOrFailing(): void
    {
        self::assertSame([], $this->multishippingSource([], null)->events());

        self::assertSame([], $this->multishippingSource([7 => '100'], new \RuntimeException('boom'))->events());
        self::assertSame([7], $this->logger->records[0]['context']['order_ids']);
    }

    /**
     * The real once-per-session service over the test session, building an event per order
     *
     * @return PurchasePageEventsInterface
     */
    private function purchases(): PurchasePageEventsInterface
    {
        $builder = $this->createMock(PurchaseEventBuilderInterface::class);
        $builder->method('build')->willReturnCallback(
            static fn (OrderInterface $order): EventInterface => new Event(
                'purchase',
                true,
                ['transaction_id' => (string) $order->getIncrementId()]
            )
        );
        $channels = $this->createMock(EventChannelPolicyInterface::class);
        $channels->method('channelOf')->willReturn(EventChannel::Browser);

        return new PurchasePageEvents($builder, new PurchaseLedger(new VisitorSession($this->session)), $channels);
    }

    /**
     * A checkout session whose last placed order is the given one
     *
     * @param Order $order
     * @return CheckoutSession
     */
    private function checkoutSession(Order $order): CheckoutSession
    {
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getLastRealOrder')->willReturn($order);

        return $checkoutSession;
    }

    /**
     * The multiple-address source
     *
     * @param array<int, string> $placedOrders Order id to increment id, as that checkout keeps them
     * @param list<Order>|\Throwable|null $read What reading the orders returns or throws; null when it must not run
     * @return MultishippingPurchase
     */
    private function multishippingSource(array $placedOrders, array|\Throwable|null $read): MultishippingPurchase
    {
        $multishipping = $this->createMock(Multishipping::class);
        $multishipping->method('getOrderIds')->willReturn(array_keys($placedOrders));
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $repository = $this->createMock(OrderRepositoryInterface::class);
        if ($read === null) {
            $repository->expects(self::never())->method('getList');
        } elseif ($read instanceof \Throwable) {
            $repository->method('getList')->willThrowException($read);
        } else {
            $results = $this->createMock(OrderSearchResultInterface::class);
            $results->method('getItems')->willReturn($read);
            $repository->method('getList')->willReturn($results);
        }

        return new MultishippingPurchase(
            $multishipping,
            $repository,
            $criteriaBuilder,
            $this->purchases(),
            $this->logger
        );
    }

    /**
     * An order
     *
     * @param int $entityId 0 for none
     * @param string $incrementId
     * @return Order
     */
    private function order(int $entityId, string $incrementId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId > 0 ? $entityId : null);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    /**
     * The transaction ids of events
     *
     * @param list<EventInterface> $events
     * @return list<string>
     */
    private function transactionIds(array $events): array
    {
        return array_map(
            static fn (EventInterface $event): string => (string) ($event->getParameters()['transaction_id'] ?? ''),
            $events
        );
    }
}
