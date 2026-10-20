<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayer\Model\Browser\ImportMapBuilder;
use Hryvinskyi\DataLayer\Model\EventModifierPipeline;
use Hryvinskyi\DataLayer\Model\PageEvents;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\BrowserConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;
use Hryvinskyi\DataLayerFrontendUi\Model\Browser\ImportMap;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\Bootstrap;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\GtmLoader;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Whatever order the blocks render in, the head carries the Google tag loaders first, then the settings and the
 * page's events, then the import map, the preloads and the entry, all after what other modules register at the head
 * tag manager's default order
 */
class HeadOrderTest extends TestCase
{
    use HeadTags;

    /**
     * The bootstrap registers before the loaders and a neighbour's script registers between them, yet the head reads
     * neighbour, loaders, settings, events, import map, preloads, entry
     *
     * @return void
     */
    public function testHeadOrderIsFixedWhateverTheRenderOrder(): void
    {
        $head = $this->headTagManager();

        $this->bootstrap($head)->registerHeadElements([]);
        $head->addInlineScript('window.neighbour=true;', [], 'neighbour');
        $this->loader($head)->registerHeadElements();

        $kinds = array_map(static function (string $line): string {
            return match (true) {
                str_contains($line, 'window.neighbour') => 'neighbour',
                str_contains($line, '/gtm.js?id=') => 'gtm loader',
                str_contains($line, '/gtag/js?id=') => 'gtag library',
                str_contains($line, "gtag('config'") => 'gtag stub',
                str_contains($line, 'data-hryvinskyi-datalayer="config"') => 'settings',
                str_contains($line, 'data-hryvinskyi-datalayer="page"') => 'page events',
                str_contains($line, 'type="importmap"') => 'import map',
                str_contains($line, 'rel="modulepreload"') => 'preload',
                str_contains($line, 'type="module"') => 'entry',
                default => 'other',
            };
        }, $this->headLines($head));

        self::assertSame(
            array_merge(
                ['neighbour', 'gtm loader', 'gtag library', 'gtag stub', 'settings', 'page events', 'import map'],
                array_fill(0, count(Bootstrap::ENTRY_IMPORTS), 'preload'),
                ['entry']
            ),
            $kinds
        );
    }

    /**
     * Get a loader for a store view with a container and a measurement id
     *
     * @param HeadTagManager $head
     * @return GtmLoader
     */
    private function loader(HeadTagManager $head): GtmLoader
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getContainerId')->willReturn('GTM-AB12');
        $config->method('getScriptHost')->willReturn(ConfigInterface::DEFAULT_SCRIPT_HOST);
        $destinations = $this->createMock(GtagDestinationsInterface::class);
        $destinations->method('forStore')->willReturn(['G-ABC123']);

        return new GtmLoader($config, new JsonHexTag(), $destinations, $head);
    }

    /**
     * Get a bootstrap for a store view that reports events, with one module the entry imports
     *
     * @param HeadTagManager $head
     * @return Bootstrap
     */
    private function bootstrap(HeadTagManager $head): Bootstrap
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $browser = $this->createMock(BrowserConfigInterface::class);
        $browser->method('forStore')->willReturn(['core' => ['plugins' => []]]);
        $plugins = $this->createMock(PluginUrlsInterface::class);
        $plugins->method('urls')->willReturn([]);
        $plugins->method('imports')->willReturn([]);
        $assets = $this->createMock(Repository::class);
        $assets->method('getUrl')->willReturnCallback(
            static fn (string $fileId): string => 'https://static.test/' . str_replace('::', '/', $fileId)
        );
        $builder = new ImportMapBuilder($assets, new JsonHexTag());
        $modules = [];
        foreach (Bootstrap::ENTRY_IMPORTS as $name) {
            $modules[$name] = 'Hryvinskyi_DataLayerFrontendUi::js/data-layer/' . basename($name);
        }

        return new Bootstrap(
            $config,
            new PageEvents(new EventModifierPipeline(), new RecordingLogger()),
            $browser,
            $storeManager,
            new JsonHexTag(),
            new ImportMap($builder, $modules),
            $plugins,
            $builder,
            $head
        );
    }
}
