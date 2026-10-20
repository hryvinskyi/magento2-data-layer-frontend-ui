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
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Supplies the order events of the checkout success page, for the order the checkout session last placed
 *
 * `purchase` by default; given the conversion's page events instead, the Google Ads conversion that follows it.
 */
class Purchase implements ArgumentInterface, PageEventSourceInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param PurchasePageEventsInterface $purchases
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PurchasePageEventsInterface $purchases,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function events(): array
    {
        $incrementId = null;
        try {
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order->getEntityId()) {
                return [];
            }
            $incrementId = $order->getIncrementId();

            return $this->purchases->forOrders([$order]);
        } catch (\Throwable $exception) {
            $this->logger->error('The success page\'s order events could not be built.', [
                'order' => $incrementId,
                'exception' => $exception,
            ]);

            return [];
        }
    }
}
