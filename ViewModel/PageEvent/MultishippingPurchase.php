<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent;

use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Api\PurchasePageEventsInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Multishipping\Model\Checkout\Type\Multishipping;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Supplies the order events of the multiple-address checkout's success page, one per order
 *
 * `purchase` by default; given the conversion's page events instead, the Google Ads conversion that follows it.
 *
 * That checkout keeps the ids of the orders it placed in its own session and never sets the checkout session's last
 * order, so the orders are read from it and loaded together, in the order they were placed.
 */
class MultishippingPurchase implements ArgumentInterface, PageEventSourceInterface
{
    /**
     * @param Multishipping $multishipping
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param PurchasePageEventsInterface $purchases
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Multishipping $multishipping,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly PurchasePageEventsInterface $purchases,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function events(): array
    {
        $orderIds = [];
        try {
            $orderIds = array_values(array_filter(
                array_map(
                    static fn (mixed $orderId): int => is_numeric($orderId) ? (int) $orderId : 0,
                    $this->multishipping->getOrderIds()
                ),
                static fn (int $orderId): bool => $orderId > 0
            ));
            if ($orderIds === []) {
                return [];
            }

            return $this->purchases->forOrders($this->orders($orderIds));
        } catch (\Throwable $exception) {
            $this->logger->error('The multiple-address success page\'s order events could not be built.', [
                'order_ids' => $orderIds,
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * Load the orders in one read, in the order they were placed
     *
     * @param list<int> $orderIds
     * @return list<OrderInterface>
     */
    private function orders(array $orderIds): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::ENTITY_ID, $orderIds, 'in')
            ->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        usort(
            $orders,
            static fn (OrderInterface $left, OrderInterface $right): int
                => (int) $left->getEntityId() <=> (int) $right->getEntityId()
        );

        return $orders;
    }
}
