<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent;

use Hryvinskyi\DataLayer\Api\Event\CartEventBuilderInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Supplies the event of a page that shows the shopper's cart (`view_cart` on the cart page, `begin_checkout` on
 * the checkout page: the builder it is given decides which)
 *
 * The cart is the checkout session's; a cart without visible lines reports nothing.
 */
class CartPage implements ArgumentInterface, PageEventSourceInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param CartEventBuilderInterface $builder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartEventBuilderInterface $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function events(): array
    {
        $cartId = null;
        try {
            $cart = $this->checkoutSession->getQuote();
            $cartId = $cart->getId();
            if ((int) $cart->getItemsCount() === 0) {
                return [];
            }
            $event = $this->builder->build($cart);
        } catch (\Throwable $exception) {
            $this->logger->error('A GA4 cart page event could not be built.', [
                'cart_id' => $cartId,
                'exception' => $exception,
            ]);

            return [];
        }

        return $event !== null ? [$event] : [];
    }
}
