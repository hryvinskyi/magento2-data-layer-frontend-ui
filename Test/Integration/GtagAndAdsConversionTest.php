<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Api\KnownEventsInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Api\PageEventsInterface;
use Hryvinskyi\DataLayer\Test\Integration\Catalog\CatalogFixtures;
use Hryvinskyi\DataLayerApi\Api\Data\EventChannel;
use Hryvinskyi\DataLayerApi\Api\EventChannelPolicyInterface;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\MultishippingPurchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\Purchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\Bootstrap as BootstrapViewModel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * gtag.js loads where a store view has a gtag destination, with its destination plugin and settings; the success page
 * prints the Google Ads conversion right after the purchase; the content security policy allows Google's hosts
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class GtagAndAdsConversionTest extends TestCase
{
    use CatalogFixtures;
    use HeadElements;

    private const LOADER = 'hryvinskyi.datalayer.gtm.loader';
    private const NOSCRIPT = 'hryvinskyi.datalayer.gtm.noscript';
    private const BOOTSTRAP = 'hryvinskyi.datalayer.bootstrap';
    private const PLUGIN = '/Hryvinskyi_DataLayerFrontendUi/js/data-layer/gtag-destination.js';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cleanCategoryTrees();
        $this->service(CheckoutSession::class)->clearQuote();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->service(CheckoutSession::class)->clearQuote();
    }

    /**
     * With a measurement id and Google Ads but no container, the head loads gtag.js and configures both tags, no
     * fallback frame is printed, and the bootstrap loads the destination plugin with its settings and preloads it
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtag/measurement_id G-TEST123
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_id AW-111222
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_label Abc_1
     * @return void
     */
    public function testGtagModeLoadsGtagJsAndItsDestination(): void
    {
        $layout = $this->layoutFor(['default']);

        self::assertSame('', $this->html($layout, self::LOADER));
        self::assertSame('', $this->html($layout, self::BOOTSTRAP));
        self::assertSame('', $this->html($layout, self::NOSCRIPT));
        $head = $this->headHtml();

        $library = $this->onlyScript($head, 'src', 'https://www.googletagmanager.com/gtag/js?id=G-TEST123');
        self::assertTrue($library->hasAttribute('async'));
        self::assertStringContainsString("gtag('config',\"G-TEST123\");gtag('config',\"AW-111222\");", $head);
        self::assertStringNotContainsString('gtm.js', $head);

        $config = $this->printedConfig($head);
        self::assertSame(['measurementId' => 'G-TEST123'], $config['gtag'] ?? null);
        $plugins = is_array($config['core'] ?? null) ? $config['core']['plugins'] ?? null : null;
        self::assertIsArray($plugins);
        self::assertCount(1, $plugins);
        self::assertIsString($plugins[0]);
        self::assertStringEndsWith(self::PLUGIN, $plugins[0]);
        $preloaded = $this->preloadedModules($head);
        self::assertContains($plugins[0], $preloaded);
        self::assertCount(
            1,
            array_filter(
                $preloaded,
                static fn (string $url): bool => str_ends_with($url, '/js/data-layer/gtag-commands.js')
            ),
            'The module the destination imports is fetched with it.'
        );
        self::assertCount(count(BootstrapViewModel::ENTRY_IMPORTS) + 2, $preloaded);
    }

    /**
     * With a container and no measurement id, Google Ads reports through the container: no gtag.js, no plugin
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtm/container_id GTM-TEST123
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_id AW-111222
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_label Abc_1
     * @return void
     */
    public function testTagManagerModeLoadsNoGtagJs(): void
    {
        $layout = $this->layoutFor(['default']);

        $this->html($layout, self::LOADER);
        $this->html($layout, self::BOOTSTRAP);
        $head = $this->headHtml();
        self::assertStringContainsString('"GTM-TEST123"', $head);
        self::assertStringNotContainsString('/gtag/js', $head);
        self::assertStringNotContainsString('function gtag(', $head);
        $config = $this->printedConfig($head);
        self::assertArrayNotHasKey('gtag', $config);
        self::assertSame([], is_array($config['core'] ?? null) ? $config['core']['plugins'] ?? null : null);
    }

    /**
     * The success page prints the order's purchase, then its Google Ads conversion with the purchase value; a reload
     * prints neither again
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_id AW-111222
     * @magentoConfigFixture current_store hryvinskyi_datalayer/ads/conversion_label Abc_1
     * @return void
     */
    public function testSuccessPagePrintsPurchaseThenTheConversion(): void
    {
        $order = $this->placedOrder();
        $this->service(CheckoutSession::class)->setLastRealOrderId((string) $order->getIncrementId());

        $layout = $this->layoutFor(['default', 'checkout_onepage_success']);
        self::assertSame('', $this->html($layout, self::BOOTSTRAP));
        $events = $this->printedEvents($this->headHtml());

        self::assertSame(['purchase', 'ads_conversion'], array_column($events, 'event'));
        self::assertSame(
            [
                'event' => 'ads_conversion',
                'send_to' => 'AW-111222/Abc_1',
                'transaction_id' => 'DLT25-100',
                'value' => 125,
                'currency' => 'USD',
            ],
            $events[1]
        );
        $purchase = is_array($events[0]['ecommerce'] ?? null) ? $events[0]['ecommerce'] : [];
        self::assertSame($purchase['value'] ?? null, $events[1]['value']);

        $sources = $this->block($layout, self::BOOTSTRAP)->getData('page_event_sources');
        self::assertIsArray($sources);
        $reload = array_filter(
            $sources,
            static fn (mixed $source): bool => $source instanceof PageEventSourceInterface
        );
        self::assertSame([], $this->service(PageEventsInterface::class)->collect($reload));
    }

    /**
     * Without Google Ads conversions the success page prints the purchase alone
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testSuccessPageWithoutConversionsPrintsThePurchaseAlone(): void
    {
        $order = $this->placedOrder();
        $this->service(CheckoutSession::class)->setLastRealOrderId((string) $order->getIncrementId());

        self::assertSame('', $this->html($this->layoutFor(['default', 'checkout_onepage_success']), self::BOOTSTRAP));
        $events = $this->printedEvents($this->headHtml());

        self::assertSame(['purchase'], array_column($events, 'event'));
    }

    /**
     * Both success pages list the conversion right after the purchase, as page event sources of their own kind;
     * the conversion is a known event whose channel stays the browser whatever the routing says
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testConversionSourcesAndChannel(): void
    {
        $this->service(MutableScopeConfigInterface::class)->setValue(
            'hryvinskyi_datalayer/channels/routing',
            '{"_1":{"event":"ads_conversion","channel":"server"},"_2":{"event":"purchase","channel":"server"}}',
            ScopeInterface::SCOPE_STORE,
            'default'
        );
        $pages = [
            'checkout_onepage_success' => Purchase::class,
            'multishipping_checkout_success' => MultishippingPurchase::class,
        ];
        foreach ($pages as $handle => $type) {
            $sources = $this->block($this->layoutFor(['default', $handle]), self::BOOTSTRAP)
                ->getData('page_event_sources');
            self::assertIsArray($sources);
            self::assertSame(['purchase', 'ads_conversion'], array_keys($sources), $handle);
            self::assertInstanceOf($type, $sources['ads_conversion'], $handle);
        }

        self::assertTrue($this->service(KnownEventsInterface::class)->isKnown('ads_conversion'));
        $channels = $this->service(EventChannelPolicyInterface::class);
        self::assertSame(EventChannel::Server, $channels->channelOf('purchase', 1), 'the routing is read');
        self::assertSame(EventChannel::Browser, $channels->channelOf('ads_conversion', 1));
    }

    /**
     * The policy allows gtag.js's host and the hosts Google Analytics 4 and Google Ads send to
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtag/measurement_id G-TEST123
     * @return void
     */
    public function testPolicyAllowsGooglesHosts(): void
    {
        $policies = $this->service(PolicyCollectorInterface::class)->collect();

        self::assertContains('www.googletagmanager.com', $this->hostsOf($policies, 'script-src'));
        self::assertContains('www.googleadservices.com', $this->hostsOf($policies, 'script-src'));
        foreach (['connect-src', 'img-src'] as $directive) {
            self::assertContains('*.google-analytics.com', $this->hostsOf($policies, $directive), $directive);
            self::assertContains('*.analytics.google.com', $this->hostsOf($policies, $directive), $directive);
        }
        self::assertContains('td.doubleclick.net', $this->hostsOf($policies, 'frame-src'));
    }

    /**
     * Save an order of store view 1 and read it back: one line, grand total 125 USD
     *
     * @return OrderInterface
     */
    private function placedOrder(): OrderInterface
    {
        $order = $this->fresh(Order::class);
        $order->setIncrementId('DLT25-100')
            ->setState(Order::STATE_PROCESSING)
            ->setStatus('processing')
            ->setStoreId(1)
            ->setSubtotal(100)
            ->setBaseSubtotal(100)
            ->setTaxAmount(15)
            ->setBaseTaxAmount(15)
            ->setShippingAmount(10)
            ->setBaseShippingAmount(10)
            ->setGrandTotal(125)
            ->setBaseGrandTotal(125)
            ->setOrderCurrencyCode('USD')
            ->setBaseCurrencyCode('USD')
            ->setCustomerIsGuest(1)
            ->setCustomerEmail('buyer@example.test');
        foreach (['billing', 'shipping'] as $type) {
            $address = $this->fresh(OrderAddress::class);
            $address->setAddressType($type)
                ->setFirstname('Test')
                ->setLastname('Buyer')
                ->setStreet(['1 Test Street'])
                ->setCity('Testville')
                ->setPostcode('12345')
                ->setCountryId('US')
                ->setRegionId(12)
                ->setTelephone('5555555')
                ->setEmail('buyer@example.test');
            if ($type === 'billing') {
                $order->setBillingAddress($address);
                continue;
            }
            $order->setShippingAddress($address);
        }
        $payment = $this->fresh(Payment::class);
        $payment->setMethod('checkmo');
        $order->setPayment($payment);

        $item = $this->fresh(OrderItem::class);
        $item->setProductId($this->createProduct('DL-T25-A', 'Conversion monitor'))
            ->setSku('DL-T25-A')
            ->setName('Conversion monitor')
            ->setProductType('simple')
            ->setQtyOrdered(2)
            ->setPrice(50)
            ->setBasePrice(50)
            ->setRowTotal(100)
            ->setBaseRowTotal(100);
        $order->addItem($item);

        $this->service(OrderRepositoryInterface::class)->save($order);
        $orderId = $order->getEntityId();

        return $this->fresh(OrderRepositoryInterface::class)->get(is_numeric($orderId) ? (int) $orderId : 0);
    }

    /**
     * Get the hosts the policy of a directive allows
     *
     * @param array<PolicyInterface> $policies
     * @param string $directive
     * @return list<string>
     */
    private function hostsOf(array $policies, string $directive): array
    {
        foreach ($policies as $policy) {
            if ($policy instanceof FetchPolicy && $policy->getId() === $directive) {
                return array_values($policy->getHostSources());
            }
        }

        return [];
    }

    /**
     * Build the layout of a page made of the given handles under the one-column page layout
     *
     * @param list<string> $handles
     * @return Layout
     */
    private function layoutFor(array $handles): Layout
    {
        $page = $this->fresh(PageFactory::class)->create();
        $layout = $page->getLayout();
        if (!$layout instanceof Layout) {
            throw new \LogicException('The page result has no layout.');
        }
        $layout->getUpdate()->addHandle($handles);
        $page->getConfig()->setPageLayout('1column');
        $layout->publicBuild();

        return $layout;
    }

    /**
     * Render a block of the layout
     *
     * @param Layout $layout
     * @param string $name
     * @return string
     */
    private function html(Layout $layout, string $name): string
    {
        return trim($this->block($layout, $name)->toHtml());
    }

    /**
     * Get a block of the layout
     *
     * @param Layout $layout
     * @param string $name
     * @return AbstractBlock
     */
    private function block(Layout $layout, string $name): AbstractBlock
    {
        $block = $layout->getBlock($name);
        if (!$block instanceof AbstractBlock) {
            throw new \LogicException(sprintf('The layout has no block "%s".', $name));
        }

        return $block;
    }
}
