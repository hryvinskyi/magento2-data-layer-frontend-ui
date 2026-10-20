<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration\Event;

use Hryvinskyi\DataLayer\Api\Event\CartEventBuilderInterface;
use Hryvinskyi\DataLayer\Api\PageEventsInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Api\PurchaseLedgerInterface;
use Hryvinskyi\DataLayer\Model\PageEvents;
use Hryvinskyi\DataLayer\Model\PurchaseLedger;
use Hryvinskyi\DataLayer\Test\Integration\ResolvesServices;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\CartPage;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\MultishippingPurchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\Purchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\ViewItem;
use Hryvinskyi\DataLayerApi\Api\Data\ItemInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

/**
 * The page event sources a layout lists are built by the container, and the cart events report a real cart
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea frontend
 */
class PageEventSourcesTest extends TestCase
{
    use ResolvesServices;

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->service(CheckoutSession::class)->clearQuote();
    }

    /**
     * Every source a layout can list is a view model and a page event source
     *
     * @return void
     */
    public function testSourcesAreViewModels(): void
    {
        $sources = [
            ViewItem::class => ViewItem::class,
            'HryvinskyiDataLayerViewCartPageEvent' => CartPage::class,
            'HryvinskyiDataLayerBeginCheckoutPageEvent' => CartPage::class,
            Purchase::class => Purchase::class,
            MultishippingPurchase::class => MultishippingPurchase::class,
        ];
        foreach ($sources as $name => $type) {
            $source = $this->fresh(PageEventSourceInterface::class, $name);
            self::assertInstanceOf(ArgumentInterface::class, $source, $name);
            self::assertSame($type, $source::class, $name);
        }
        self::assertInstanceOf(PageEvents::class, $this->service(PageEventsInterface::class));
    }

    /**
     * Outside a product page, with an empty cart and without a placed order, the sources report nothing
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testSourcesWithoutSubjectsReportNothing(): void
    {
        $sources = [
            $this->fresh(ViewItem::class),
            $this->fresh(CartPage::class, 'HryvinskyiDataLayerViewCartPageEvent'),
            $this->fresh(Purchase::class),
            $this->fresh(MultishippingPurchase::class),
        ];

        self::assertSame([], $this->service(PageEventsInterface::class)->collect($sources));
    }

    /**
     * The cart page and checkout builders report a real cart under their own event names
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testCartEventsOfARealCart(): void
    {
        $cart = $this->fresh(Quote::class);
        $cart->setStoreId(1);
        $cart->addProduct($this->sellableProduct('DL-T5-CART', 'Cart monitor', 30.0), 3);
        $cart->getBillingAddress();
        $cart->getShippingAddress();
        $cart->collectTotals();

        $builders = [
            'HryvinskyiDataLayerViewCartEventBuilder' => 'view_cart',
            'HryvinskyiDataLayerBeginCheckoutEventBuilder' => 'begin_checkout',
        ];
        foreach ($builders as $builder => $name) {
            $event = $this->fresh(CartEventBuilderInterface::class, $builder)->build($cart);

            self::assertNotNull($event, $name);
            self::assertSame($name, $event->getName());
            self::assertSame(90.0, $event->getParameters()['value'] ?? null);
            self::assertSame(
                [['DL-T5-CART', 3]],
                array_map(
                    static fn (ItemInterface $item): array => [
                        $item->getParameters()['item_id'] ?? null,
                        $item->getParameters()['quantity'] ?? null,
                    ],
                    $event->getItems()
                )
            );
        }
    }

    /**
     * The ledger keeps what it was told in the data layer's session namespace, shared by every ledger of the request
     *
     * @return void
     */
    public function testLedgerUsesTheSessionNamespace(): void
    {
        self::assertInstanceOf(PurchaseLedger::class, $this->service(PurchaseLedgerInterface::class));

        $this->fresh(PurchaseLedgerInterface::class)->markReported('DLT5-LEDGER');

        self::assertTrue($this->fresh(PurchaseLedgerInterface::class)->wasReported('DLT5-LEDGER'));
        self::assertFalse($this->fresh(PurchaseLedgerInterface::class)->wasReported('DLT5-OTHER'));
    }

    /**
     * Create an enabled simple product that can be added to a cart without stock, and load it for store view 1
     *
     * @param string $sku
     * @param string $name
     * @param float $price
     * @return Product
     */
    private function sellableProduct(string $sku, string $name, float $price): Product
    {
        $product = $this->fresh(Product::class);
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setSku($sku)
            ->setName($name)
            ->setPrice($price)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0]);
        $resource = $this->service(ProductResource::class);
        $resource->save($product);

        $loaded = $this->fresh(Product::class);
        $loaded->setStoreId(1);
        $resource->load($loaded, (int) $product->getId());

        return $loaded;
    }
}
