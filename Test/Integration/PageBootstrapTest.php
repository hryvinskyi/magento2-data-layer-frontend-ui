<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Test\Integration\ResolvesServices;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Api\ServerEventSignalInterface;
use Hryvinskyi\DataLayerFrontendUi\Model\ServerEventCookie;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\MultishippingPurchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\Purchase;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent\ViewItem;
use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

/**
 * The storefront layout places the Tag Manager loader, its fallback frame and the page bootstrap, the loader and the
 * bootstrap hand their elements to the head tag manager, each page adds its own events to the bootstrap, and the
 * content security policy allows the configured Tag Manager host
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class PageBootstrapTest extends TestCase
{
    use ResolvesServices;
    use HeadElements;

    private const LOADER = 'hryvinskyi.datalayer.gtm.loader';
    private const NOSCRIPT = 'hryvinskyi.datalayer.gtm.noscript';
    private const BOOTSTRAP = 'hryvinskyi.datalayer.bootstrap';
    private const PAGE_ITEMS = ['view_item', 'view_cart', 'begin_checkout', 'purchase'];

    /**
     * The loader sits in the head, the fallback frame at the top of the body and the bootstrap at its end
     *
     * @return void
     */
    public function testBlocksSitInTheirContainers(): void
    {
        $layout = $this->layoutFor(['default']);

        self::assertSame('head.additional', $layout->getParentName(self::LOADER));
        self::assertSame('after.body.start', $layout->getParentName(self::NOSCRIPT));
        self::assertSame('before.body.end', $layout->getParentName(self::BOOTSTRAP));
        self::assertContains(self::LOADER, $layout->getChildNames('head.additional'));
        self::assertContains(self::NOSCRIPT, $layout->getChildNames('after.body.start'));
        self::assertSame([], array_intersect(self::PAGE_ITEMS, array_keys($this->eventSources($layout))));
    }

    /**
     * The product page adds its view_item source to the bootstrap block
     *
     * @return void
     */
    public function testProductPageAddsViewItem(): void
    {
        $sources = $this->eventSources($this->layoutFor(['default', 'catalog_product_view']));

        self::assertInstanceOf(ViewItem::class, $sources['view_item'] ?? null);
        self::assertSame([], array_intersect(['view_cart', 'begin_checkout', 'purchase'], array_keys($sources)));
    }

    /**
     * The cart, checkout and success pages each add their source
     *
     * @param string $handle
     * @param string $item
     * @param class-string<PageEventSourceInterface> $type
     * @return void
     * @dataProvider pageSourceProvider
     */
    public function testPagesAddTheirSource(string $handle, string $item, string $type): void
    {
        $sources = $this->eventSources($this->layoutFor(['default', $handle]));

        self::assertInstanceOf($type, $sources[$item] ?? null);
        self::assertSame([], array_intersect(array_diff(self::PAGE_ITEMS, [$item]), array_keys($sources)));
    }

    /**
     * Get the layout handle, the item it adds and the source type
     *
     * @return array<string, array{string, string, class-string<PageEventSourceInterface>}>
     */
    public static function pageSourceProvider(): array
    {
        return [
            'cart' => ['checkout_cart_index', 'view_cart', PageEventSourceInterface::class],
            'checkout' => ['checkout_index_index', 'begin_checkout', PageEventSourceInterface::class],
            'success' => ['checkout_onepage_success', 'purchase', Purchase::class],
            'multishipping success' => ['multishipping_checkout_success', 'purchase', MultishippingPurchase::class],
        ];
    }

    /**
     * An enabled store view hands the loader and the bootstrap's elements to the head tag manager, printing nothing in
     * place, and prints the fallback frame where it stands
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtm/container_id GTM-TEST123
     * @return void
     */
    public function testEnabledStoreViewRegistersTheHeadElements(): void
    {
        $layout = $this->layoutFor(['default']);

        self::assertSame('', $this->html($layout, self::LOADER));
        self::assertSame('', $this->html($layout, self::BOOTSTRAP));
        self::assertStringContainsString(
            'https://www.googletagmanager.com/ns.html?id=GTM-TEST123',
            $this->html($layout, self::NOSCRIPT)
        );

        $head = $this->headHtml();
        self::assertStringContainsString('"GTM-TEST123","www.googletagmanager.com"', $head);
        self::assertSame([], $this->printedEvents($head));
        self::assertStringNotContainsString('x-magento-init', $head);

        $config = $this->printedConfig($head);
        $core = $config['core'] ?? null;
        self::assertIsArray($core);
        self::assertSame('load', $core['impressionTrigger'] ?? null);
        self::assertSame([], $core['plugins'] ?? null);
        self::assertIsString($core['drainUrl'] ?? null);
        self::assertStringEndsWith('/hryvinskyi_datalayer/events/drain/', $core['drainUrl']);
        self::assertIsString($core['acknowledgeUrl'] ?? null);
        self::assertStringEndsWith('/hryvinskyi_datalayer/events/acknowledge/', $core['acknowledgeUrl']);
        self::assertArrayNotHasKey('server', $config);

        $entry = $this->onlyScript($head, 'type', 'module');
        self::assertStringEndsWith(
            '/Hryvinskyi_DataLayerFrontendUi/js/data-layer/main.js',
            $entry->getAttribute('src')
        );
        self::assertSame('', $entry->textContent, 'The entry is an external ES module script.');

        $map = $this->printedImportMap($head);
        self::assertLessThan(
            (int) strpos($head, 'type="module"'),
            (int) strpos($head, 'type="importmap"'),
            'The import map comes before the entry.'
        );
        foreach (['push-sequence', 'drain', 'plugins', 'beacon-batch', 'gtag-commands'] as $module) {
            $url = $map['@hryvinskyi/data-layer/' . $module . '.js'] ?? null;
            self::assertIsString($url, $module);
            self::assertStringEndsWith('/Hryvinskyi_DataLayerFrontendUi/js/data-layer/' . $module . '.js', $url);
        }
    }

    /**
     * A store view with the data layer off prints nothing and registers nothing
     *
     * @return void
     */
    public function testDisabledStoreViewPrintsNothing(): void
    {
        $layout = $this->layoutFor(['default']);

        self::assertSame('', $this->html($layout, self::LOADER));
        self::assertSame('', $this->html($layout, self::NOSCRIPT));
        self::assertSame('', $this->html($layout, self::BOOTSTRAP));
        $head = $this->headHtml();
        self::assertStringNotContainsString('hryvinskyi-datalayer', $head);
        self::assertStringNotContainsString('gtm.js', $head);
        self::assertStringNotContainsString('importmap', $head);
    }

    /**
     * The core's signal that signals nothing is replaced by this module's cookie, so queued events reach the browser
     *
     * @return void
     */
    public function testServerEventsAreSignalledByTheCookie(): void
    {
        self::assertInstanceOf(ServerEventCookie::class, $this->service(ServerEventSignalInterface::class));
    }

    /**
     * The composite policy collector lists the configured host for every directive the loader needs, beside Google's
     * own host from the module's whitelist
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtm/script_host metrics.example.com
     * @return void
     */
    public function testConfiguredHostIsAllowedByThePolicy(): void
    {
        $policies = $this->service(PolicyCollectorInterface::class)->collect();

        foreach (['script-src', 'img-src', 'connect-src', 'frame-src'] as $directive) {
            $hosts = $this->hostsOf($policies, $directive);
            self::assertContains('metrics.example.com', $hosts, $directive);
            self::assertContains('www.googletagmanager.com', $hosts, $directive);
        }
    }

    /**
     * Without a custom host only Google's host is allowed, and not by this module's collector
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testDefaultHostIsAllowedByTheWhitelistOnly(): void
    {
        $policies = $this->service(PolicyCollectorInterface::class)->collect();

        $hosts = $this->hostsOf($policies, 'script-src');
        self::assertContains('www.googletagmanager.com', $hosts);
        self::assertNotContains('metrics.example.com', $hosts);
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
        $page = $this->service(PageFactory::class)->create();
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
     * Get the page event sources the layout gave the bootstrap block
     *
     * @param Layout $layout
     * @return array<mixed>
     */
    private function eventSources(Layout $layout): array
    {
        $sources = $this->block($layout, self::BOOTSTRAP)->getData('page_event_sources');
        self::assertIsArray($sources);

        return $sources;
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
