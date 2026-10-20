<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\BrowserConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Model\Browser\ImportMapBuilder;
use Hryvinskyi\DataLayer\Model\Data\Event;
use Hryvinskyi\DataLayer\Model\EventModifierPipeline;
use Hryvinskyi\DataLayer\Model\PageEvents;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerFrontendUi\Model\Browser\ImportMap;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\Bootstrap;
use Hryvinskyi\DataLayerApi\Api\Data\EventInterface;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The page hands the head tag manager the browser module's settings, its events, the import map, the preloads and the
 * module itself, in that order and only for a store view that reports events
 */
class BootstrapTest extends TestCase
{
    use TemplateRenderer;
    use HeadTags;

    private const STORE_ID = 3;

    private const STATIC = 'https://static.test/frontend/';

    private const PLUGIN_URL = 'https://static.test/frontend/Vendor_Module/js/plugin.js';

    private const MODULES = [
        'push-sequence', 'list-data', 'url-path', 'list-scope', 'rendered-box', 'settings', 'drain', 'delivered-ids',
        'plugins', 'beacon-batch', 'gtag-commands',
    ];

    /**
     * @var list<int>
     */
    private array $configStores = [];

    /**
     * @var list<int>
     */
    private array $pluginStores = [];

    /**
     * A store view without the data layer registers nothing, and neither does a block without its view model; the
     * template itself never prints anything
     *
     * @return void
     */
    public function testDisabledOrIncompleteBlockRegistersNothing(): void
    {
        $head = $this->headTagManager();
        $disabled = $this->bootstrap(false, ['core' => ['impressionTrigger' => 'load']], head: $head);

        self::assertFalse($disabled->isEnabled());
        self::assertSame('', $this->render('bootstrap.phtml', [
            'view_model' => $disabled,
            'page_event_sources' => [$this->source([new Event('view_item', true)])],
        ]));
        self::assertSame('', $this->render('bootstrap.phtml', ['view_model' => new \stdClass()]));
        self::assertSame('', $this->render('bootstrap.phtml', ['page_event_sources' => []]));
        self::assertSame([], $this->headLines($head));
        self::assertSame([], $this->configStores);
    }

    /**
     * The settings under their fixed id, then the events of every source in source order, then the import map, then
     * the preloads, then the entry as an external ES module, all in the head and none printed in place
     *
     * @return void
     */
    public function testRegistersSettingsEventsImportMapPreloadsAndEntryInOrder(): void
    {
        $head = $this->headTagManager();
        $settings = ['core' => ['impressionTrigger' => 'visible', 'plugins' => []]];
        $bootstrap = $this->bootstrap(true, $settings, head: $head);

        $html = $this->render('bootstrap.phtml', [
            'view_model' => $bootstrap,
            'page_event_sources' => [
                'first' => $this->source([new Event('view_item', true, ['value' => 1.5])]),
                'second' => $this->source([new Event('purchase', true)]),
            ],
        ]);

        self::assertSame('', $html);
        self::assertSame(
            array_merge(
                [
                    '<script type="application/json" id="hryvinskyi-datalayer-config" '
                    . 'data-hryvinskyi-datalayer="config">'
                    . '{"core":{"impressionTrigger":"visible","plugins":[]}}</script>',
                    '<script type="application/json" data-hryvinskyi-datalayer="page">'
                    . '[{"event":"view_item","ecommerce":{"value":1.5}},'
                    . '{"event":"purchase","ecommerce":[]}]</script>',
                    '<script type="importmap">' . $this->importMapJson() . '</script>',
                ],
                $this->preloadLinks(),
                [
                    '<script type="module" src="' . self::STATIC
                    . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/main.js"></script>',
                ]
            ),
            $this->headLines($head)
        );
        self::assertSame([self::STORE_ID], $this->configStores);
    }

    /**
     * Registering again, as a second render of the block does, replaces the elements instead of adding copies
     *
     * @return void
     */
    public function testRegisteringAgainKeepsOneCopyOfEachElement(): void
    {
        $head = $this->headTagManager();
        $bootstrap = $this->bootstrap(true, [], head: $head);

        $bootstrap->registerHeadElements([]);
        $once = $this->headLines($head);
        $bootstrap->registerHeadElements([]);

        self::assertCount(3 + count($this->preloadLinks()) + 1, $once);
        self::assertSame($once, $this->headLines($head));
    }

    /**
     * Every module the entry imports, then every plugin the current store view loads with the modules it imports, is
     * announced for preloading once, escaped for the attribute
     *
     * @return void
     */
    public function testPreloadsTheEntrysImportsAndTheStoreViewsPlugins(): void
    {
        $urls = $this->bootstrap(true, [])->getModulePreloadUrls();

        self::assertSame(
            [
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/push-sequence.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/list-data.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/url-path.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/list-scope.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/rendered-box.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/settings.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/drain.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/delivered-ids.js',
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/plugins.js',
                self::PLUGIN_URL,
                self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/gtag-commands.js',
            ],
            $urls
        );
        self::assertSame([self::STORE_ID, self::STORE_ID], $this->pluginStores);
        $once = $this->bootstrap(true, [], [self::PLUGIN_URL], ['@hryvinskyi/data-layer/plugins.js'])
            ->getModulePreloadUrls();
        self::assertSame(array_slice($urls, 0, 10), $once, 'An import the entry already preloads is announced once.');

        $head = $this->headTagManager();
        $this->bootstrap(true, [], ['https://static.test/a.js?x="><b>'], head: $head)->registerHeadElements([]);
        $html = $head->render();
        self::assertStringContainsString(
            '<link href="https://static.test/a.js?x=&quot;&gt;&lt;b&gt;" rel="modulepreload">',
            $html
        );
        self::assertStringNotContainsString('<b>', $html);
    }

    /**
     * Without any provider part the settings are an empty object, not an empty list
     *
     * @return void
     */
    public function testNoSettingsPrintAnEmptyObject(): void
    {
        self::assertSame('{}', $this->bootstrap(true, [])->getConfigJson());
    }

    /**
     * Whatever is not a page event source is ignored, and a page with no events prints an empty array
     *
     * @return void
     */
    public function testIgnoresArgumentsThatAreNotSources(): void
    {
        $bootstrap = $this->bootstrap(true, []);

        self::assertSame('[]', $bootstrap->getPageEventsJson([]));
        self::assertSame(
            '[]',
            $bootstrap->getPageEventsJson(['name' => 'text', 'number' => 3, 'object' => new \stdClass()])
        );
        self::assertSame(
            '[{"event":"view_cart","ecommerce":[]}]',
            $bootstrap->getPageEventsJson([
                'text' => 'x',
                'cart' => $this->source([new Event('view_cart', true)]),
            ])
        );
    }

    /**
     * Neither an event's text nor a setting can close the script tag it is printed in
     *
     * @return void
     */
    public function testTextCannotCloseTheScriptTags(): void
    {
        $head = $this->headTagManager();
        $this->bootstrap(true, ['bridge' => ['label' => '</script><i>']], head: $head)->registerHeadElements([
            $this->source([new Event('view_item', true, ['item_list_name' => '</script><b>'])]),
        ]);
        $html = $head->render();

        self::assertSame(4, substr_count($html, '</script>'));
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<i>', $html);
    }

    /**
     * The import map names every registered module with its static address; with none it is an empty object
     *
     * @return void
     */
    public function testImportMapNamesEveryModule(): void
    {
        $map = json_decode($this->bootstrap(true, [])->getImportMapJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($map);
        self::assertSame(['imports'], array_keys($map));
        self::assertIsArray($map['imports']);
        self::assertSame(
            self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/drain.js',
            $map['imports']['@hryvinskyi/data-layer/drain.js']
        );
        self::assertCount(count(self::MODULES), $map['imports']);
    }

    /**
     * The entry is the module's own script, at the address the asset repository gives it
     *
     * @return void
     */
    public function testEntryIsTheModulesOwnScript(): void
    {
        self::assertSame(
            self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/main.js',
            $this->bootstrap(true, [])->getEntryUrl()
        );
    }

    /**
     * Get a bootstrap over fixed settings, the real page events service and a browser configuration that records
     * the store view it is asked for
     *
     * @param bool $enabled
     * @param array<string, array<string, mixed>> $browserConfig
     * @param list<string> $pluginUrls The plugins the store view loads
     * @param list<string> $pluginImports The names those plugins import
     * @param HeadTagManager|null $head The head tag manager it registers with; a fresh one when null
     * @return Bootstrap
     */
    private function bootstrap(
        bool $enabled,
        array $browserConfig,
        array $pluginUrls = [self::PLUGIN_URL],
        array $pluginImports = ['@hryvinskyi/data-layer/gtag-commands.js'],
        ?HeadTagManager $head = null
    ): Bootstrap {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn($enabled);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $browser = $this->createMock(BrowserConfigInterface::class);
        $browser->method('forStore')->willReturnCallback(function (int $storeId) use ($browserConfig): array {
            $this->configStores[] = $storeId;

            return $browserConfig;
        });

        $assets = $this->createMock(Repository::class);
        $assets->method('getUrl')->willReturnCallback(
            static fn (string $fileId): string => self::STATIC . str_replace('::', '/', $fileId)
        );
        $plugins = $this->createMock(PluginUrlsInterface::class);
        $plugins->method('urls')->willReturnCallback(function (int $storeId) use ($pluginUrls): array {
            $this->pluginStores[] = $storeId;

            return $pluginUrls;
        });
        $plugins->method('imports')->willReturnCallback(function (int $storeId) use ($pluginImports): array {
            $this->pluginStores[] = $storeId;

            return $pluginImports;
        });
        $modules = [];
        foreach (self::MODULES as $module) {
            $modules['@hryvinskyi/data-layer/' . $module . '.js']
                = 'Hryvinskyi_DataLayerFrontendUi::js/data-layer/' . $module . '.js';
        }

        $builder = new ImportMapBuilder($assets, new JsonHexTag());

        return new Bootstrap(
            $config,
            new PageEvents(new EventModifierPipeline(), new RecordingLogger()),
            $browser,
            $storeManager,
            new JsonHexTag(),
            new ImportMap($builder, $modules),
            $plugins,
            $builder,
            $head ?? $this->headTagManager()
        );
    }

    /**
     * The import map registered for the registered modules
     *
     * @return string
     */
    private function importMapJson(): string
    {
        $imports = [];
        foreach (self::MODULES as $module) {
            $imports['@hryvinskyi/data-layer/' . $module . '.js']
                = self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/' . $module . '.js';
        }

        return (new JsonHexTag())->serialize(['imports' => $imports]);
    }

    /**
     * The preload links registered after the import map, for the default plugin list
     *
     * @return list<string>
     */
    private function preloadLinks(): array
    {
        $links = [];
        $files = [
            'push-sequence', 'list-data', 'url-path', 'list-scope', 'rendered-box', 'settings', 'drain',
            'delivered-ids', 'plugins',
        ];
        foreach ($files as $file) {
            $links[] = '<link href="' . self::STATIC . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/' . $file
                . '.js" rel="modulepreload">';
        }
        $links[] = '<link href="' . self::PLUGIN_URL . '" rel="modulepreload">';
        $links[] = '<link href="' . self::STATIC
            . 'Hryvinskyi_DataLayerFrontendUi/js/data-layer/gtag-commands.js" rel="modulepreload">';

        return $links;
    }

    /**
     * Get a page event source supplying the given events
     *
     * @param list<EventInterface> $events
     * @return PageEventSourceInterface
     */
    private function source(array $events): PageEventSourceInterface
    {
        $source = $this->createMock(PageEventSourceInterface::class);
        $source->method('events')->willReturn($events);

        return $source;
    }
}
