<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Test\Integration\ResolvesServices;
use Hryvinskyi\HeadTagManager\Api\HeadTagManagerInterface;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Text;
use Magento\Framework\View\Element\Text\ListText;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

/**
 * A rendered storefront page carries the Google tag loaders and the browser module's elements in `<head>`, in their
 * fixed order and nowhere else, and the head tag manager still gets them when the blocks that registered them are
 * served from the block cache
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class FullPageHeadTest extends TestCase
{
    use ResolvesServices;

    private const LOADER = 'hryvinskyi.datalayer.gtm.loader';
    private const BOOTSTRAP = 'hryvinskyi.datalayer.bootstrap';

    /**
     * What identifies each element of the data layer in the page, in the order the head must carry them
     */
    private const IN_ORDER = [
        'gtm loader' => '~/gtm\.js\?id=~',
        'gtag library' => '~<script[^>]* src="https://www\.googletagmanager\.com/gtag/js\?id=G-TEST123"~',
        'gtag stub' => "~gtag\\('config',\"G-TEST123\"\\);~",
        'settings' => '~<script[^>]* id="hryvinskyi-datalayer-config"~',
        'page events' => '~<script[^>]* data-hryvinskyi-datalayer="page"~',
        'import map' => '~<script[^>]* type="importmap"~',
        'preload' => '~<link[^>]* rel="modulepreload"~',
        'entry' => '~<script[^>]* type="module"~',
    ];

    /**
     * A full page puts every element into `<head>`, in order, and the body keeps only the fallback frame
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtm/container_id GTM-TEST123
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtag/measurement_id G-TEST123
     * @return void
     */
    public function testHeadCarriesTheElementsInOrder(): void
    {
        [$head, $body] = $this->split($this->render($this->page()));

        $this->assertHeadOrder($head);
        foreach (['settings', 'page events', 'import map', 'preload', 'entry', 'gtm loader', 'gtag stub'] as $kind) {
            self::assertSame(0, preg_match(self::IN_ORDER[$kind], $body), $kind . ' is not in the body.');
        }
        self::assertStringContainsString('https://www.googletagmanager.com/ns.html?id=GTM-TEST123', $body);
        self::assertStringNotContainsString('HRYVINSKYI:PLACEHOLDER', $head);
    }

    /**
     * Rendered again from the same layout, a block served from the block cache does not run the blocks under it, yet
     * the head tag manager gets back every element they registered, and prints them in order
     *
     * @magentoCache block_html enabled
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtm/container_id GTM-TEST123
     * @magentoConfigFixture current_store hryvinskyi_datalayer/gtag/measurement_id G-TEST123
     * @return void
     */
    public function testElementsSurviveTheBlockCache(): void
    {
        $source = new CountingPageEventSource();
        $wrapper = $this->cachedWrapper($source);
        $headTagManager = $this->service(HeadTagManagerInterface::class);
        self::assertInstanceOf(HeadTagManager::class, $headTagManager);

        self::assertStringContainsString('<!-- cached wrapper -->', $wrapper->toHtml());
        self::assertSame(1, $source->calls, 'The first render runs the bootstrap.');
        $this->assertHeadOrder($headTagManager->render());

        $headTagManager->clear();
        self::assertSame('', $headTagManager->render());

        self::assertStringContainsString('<!-- cached wrapper -->', $wrapper->toHtml());
        self::assertSame(1, $source->calls, 'The second render is served from the block cache.');
        $this->assertHeadOrder($headTagManager->render());
    }

    /**
     * Assert the head carries each element of the data layer once (the preloads several times), in the fixed order
     *
     * @param string $head
     * @return void
     */
    private function assertHeadOrder(string $head): void
    {
        $previous = -1;
        foreach (self::IN_ORDER as $kind => $pattern) {
            self::assertGreaterThan(
                0,
                preg_match_all($pattern, $head, $matches, PREG_OFFSET_CAPTURE),
                $kind . ' is in the head.'
            );
            $offsets = array_column($matches[0], 1);
            if ($kind !== 'preload') {
                self::assertCount(1, $offsets, $kind . ' is in the head once.');
            }
            $first = reset($offsets);
            $last = end($offsets);
            self::assertIsInt($first);
            self::assertIsInt($last);
            self::assertGreaterThan($previous, $first, $kind . ' follows what must come before it.');
            $previous = $last;
        }
    }

    /**
     * Build the default page under the one-column page layout
     *
     * @return Page
     */
    private function page(): Page
    {
        $page = $this->fresh(PageFactory::class)->create();
        $layout = $page->getLayout();
        self::assertInstanceOf(Layout::class, $layout);
        $layout->getUpdate()->addHandle(['default']);
        $page->getConfig()->setPageLayout('1column');

        return $page;
    }

    /**
     * Build the default page's layout with the loader and the bootstrap moved under a block-cached wrapper of their
     * own, the bootstrap reporting the given source's events
     *
     * @param PageEventSourceInterface $source
     * @return ListText
     */
    private function cachedWrapper(PageEventSourceInterface $source): ListText
    {
        $layout = $this->page()->getLayout();
        self::assertInstanceOf(Layout::class, $layout);
        $layout->publicBuild();

        $wrapperName = 'hryvinskyi.datalayer.test.cached';
        $wrapper = $layout->addBlock(ListText::class, $wrapperName, 'before.body.end');
        self::assertInstanceOf(ListText::class, $wrapper);
        $wrapper->setData('cache_lifetime', 3600);
        $wrapper->setData('cache_key', 'hryvinskyi_datalayer_head_test_' . bin2hex(random_bytes(8)));
        $marker = $layout->addBlock(Text::class, 'hryvinskyi.datalayer.test.marker', $wrapperName);
        self::assertInstanceOf(Text::class, $marker);
        $marker->setText('<!-- cached wrapper -->');
        $layout->setChild($wrapperName, self::LOADER, 'loader');
        $layout->setChild($wrapperName, self::BOOTSTRAP, 'bootstrap');
        $bootstrap = $layout->getBlock(self::BOOTSTRAP);
        self::assertInstanceOf(AbstractBlock::class, $bootstrap);
        $bootstrap->setData('page_event_sources', ['counting' => $source]);

        return $wrapper;
    }

    /**
     * Render a page result into a fresh response, as the storefront does
     *
     * @param Page $page
     * @return string
     */
    private function render(Page $page): string
    {
        $response = $this->fresh(HttpResponse::class);
        $page->renderResult($response);

        $html = $response->getContent();
        self::assertIsString($html);

        return $html;
    }

    /**
     * Split a page into its head and its body
     *
     * @param string $html
     * @return array{string, string}
     */
    private function split(string $html): array
    {
        $headStart = stripos($html, '<head');
        $headEnd = stripos($html, '</head>');
        self::assertNotFalse($headStart);
        self::assertNotFalse($headEnd);

        return [substr($html, $headStart, $headEnd - $headStart), substr($html, $headEnd)];
    }
}
