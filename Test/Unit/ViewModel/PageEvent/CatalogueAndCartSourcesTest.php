<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel\PageEvent;

use Hryvinskyi\DataLayerFrontendUi\Api\CurrentProductInterface;
use Hryvinskyi\DataLayer\Api\Event\CartEventBuilderInterface;
use Hryvinskyi\DataLayer\Api\Event\ViewItemEventBuilderInterface;
use Hryvinskyi\DataLayer\Model\Data\Event;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\CartPage;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\ViewItem;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

/**
 * The product and cart page sources report their event, report nothing without a subject, and never break the page
 */
class CatalogueAndCartSourcesTest extends TestCase
{
    /**
     * The product page reports the built event; a page without a product reports nothing
     *
     * @return void
     */
    public function testViewItem(): void
    {
        $event = new Event('view_item', true);
        $builder = $this->createMock(ViewItemEventBuilderInterface::class);
        $builder->method('build')->willReturn($event);

        self::assertSame([$event], (new ViewItem($this->currentProduct(7), $builder, new RecordingLogger()))->events());
        self::assertSame([], (new ViewItem($this->currentProduct(null), $builder, new RecordingLogger()))->events());
    }

    /**
     * A failure on the product page is logged with the product and the page gets nothing
     *
     * @return void
     */
    public function testViewItemFailureIsLogged(): void
    {
        $builder = $this->createMock(ViewItemEventBuilderInterface::class);
        $builder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = new RecordingLogger();

        self::assertSame([], (new ViewItem($this->currentProduct(7), $builder, $logger))->events());
        self::assertSame(7, $logger->records[0]['context']['product_id']);
    }

    /**
     * A cart page reports the built event; an empty cart is not even handed to the builder
     *
     * @return void
     */
    public function testCartPage(): void
    {
        $event = new Event('view_cart', true);
        $builder = $this->createMock(CartEventBuilderInterface::class);
        $builder->expects(self::once())->method('build')->willReturn($event);

        self::assertSame([$event], (new CartPage($this->session(2), $builder, new RecordingLogger()))->events());
        self::assertSame([], (new CartPage($this->session(0), $builder, new RecordingLogger()))->events());
    }

    /**
     * A failure on a cart page is logged with the cart and the page gets nothing
     *
     * @return void
     */
    public function testCartPageFailureIsLogged(): void
    {
        $builder = $this->createMock(CartEventBuilderInterface::class);
        $builder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = new RecordingLogger();

        self::assertSame([], (new CartPage($this->session(1), $builder, $logger))->events());
        self::assertSame(55, $logger->records[0]['context']['cart_id']);
    }

    /**
     * The current product
     *
     * @param int|null $productId Null outside a product page
     * @return CurrentProductInterface
     */
    private function currentProduct(?int $productId): CurrentProductInterface
    {
        $product = null;
        if ($productId !== null) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($productId);
        }
        $current = $this->createMock(CurrentProductInterface::class);
        $current->method('get')->willReturn($product);

        return $current;
    }

    /**
     * A checkout session whose cart (id 55) has the given number of visible lines
     *
     * @param int $itemsCount
     * @return CheckoutSession
     */
    private function session(int $itemsCount): CheckoutSession
    {
        $cart = $this->createMock(Quote::class);
        $cart->method('getId')->willReturn(55);
        $cart->method('getItemsCount')->willReturn($itemsCount);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($cart);

        return $session;
    }
}
