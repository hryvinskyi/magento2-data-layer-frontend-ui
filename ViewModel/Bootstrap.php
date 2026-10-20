<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\ViewModel;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\BrowserConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\ImportMapInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Head\HeadSortOrderInterface;
use Hryvinskyi\DataLayer\Api\Browser\ImportMapBuilderInterface;
use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Hryvinskyi\DataLayer\Api\PageEventsInterface;
use Hryvinskyi\HeadTagManager\Api\HeadTagManagerInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the page hands the browser module: its settings, the page's events, the import map its modules resolve their
 * imports through, the preloads of those modules and the module's entry
 *
 * All go into `<head>` through the head tag manager, at the places {@see HeadSortOrderInterface} gives them, so the
 * import map precedes every module that resolves through it and no script optimiser moves them out of order; the
 * manager renders scripts through the secure renderer, which handles the content security policy. All are store view
 * settings, static addresses or the page's own events, so they are safe in a full-page-cached page. Every JSON string
 * has `<` and `>` escaped, so it is printed inside its script tag as it is.
 */
class Bootstrap implements ArgumentInterface
{
    /**
     * The browser module's entry: an ES module, run once the page has been parsed, before `DOMContentLoaded`
     */
    public const ENTRY = 'Hryvinskyi_DataLayerFrontendUi::js/data-layer/main.js';

    /**
     * The names of the modules the entry imports statically, all of them: the page announces them beside the entry so
     * the browser fetches every one at once instead of discovering them only after the entry has arrived. An import
     * the entry gains is added here, or it is fetched a round trip later.
     */
    public const ENTRY_IMPORTS = [
        '@hryvinskyi/data-layer/push-sequence.js',
        '@hryvinskyi/data-layer/list-data.js',
        '@hryvinskyi/data-layer/url-path.js',
        '@hryvinskyi/data-layer/list-scope.js',
        '@hryvinskyi/data-layer/rendered-box.js',
        '@hryvinskyi/data-layer/settings.js',
        '@hryvinskyi/data-layer/drain.js',
        '@hryvinskyi/data-layer/delivered-ids.js',
        '@hryvinskyi/data-layer/plugins.js',
    ];

    /**
     * The id of the settings script: the page renders it once, in `<head>`, and the browser module reads it by this id
     */
    public const CONFIG_ID = 'hryvinskyi-datalayer-config';

    /**
     * The attribute the browser module finds its data scripts by, and the values naming the settings and the events
     */
    private const DATA_ATTRIBUTE = 'data-hryvinskyi-datalayer';
    private const DATA_SETTINGS = 'config';
    private const DATA_PAGE_EVENTS = 'page';

    /**
     * The head tag manager's keys of the elements: registering again replaces them instead of adding copies
     */
    private const KEY_SETTINGS = 'hryvinskyi_datalayer_settings';
    private const KEY_PAGE_EVENTS = 'hryvinskyi_datalayer_page_events';
    private const KEY_IMPORT_MAP = 'hryvinskyi_datalayer_import_map';
    private const KEY_MODULE_PRELOAD = 'hryvinskyi_datalayer_modulepreload:';
    private const KEY_ENTRY = 'hryvinskyi_datalayer_entry';

    /**
     * @param ConfigInterface $config
     * @param PageEventsInterface $pageEvents
     * @param BrowserConfigInterface $browserConfig
     * @param StoreManagerInterface $storeManager
     * @param JsonHexTag $json
     * @param ImportMapInterface $importMap
     * @param PluginUrlsInterface $pluginUrls
     * @param ImportMapBuilderInterface $importMapBuilder Writes the import map and resolves the entry's address
     * @param HeadTagManagerInterface $headTagManager Prints the elements into `<head>`
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly PageEventsInterface $pageEvents,
        private readonly BrowserConfigInterface $browserConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly JsonHexTag $json,
        private readonly ImportMapInterface $importMap,
        private readonly PluginUrlsInterface $pluginUrls,
        private readonly ImportMapBuilderInterface $importMapBuilder,
        private readonly HeadTagManagerInterface $headTagManager
    ) {
    }

    /**
     * Hand the head tag manager everything the browser module needs
     *
     * In their order: the settings, the page's events, the import map, the preloads and the entry. Registers nothing
     * for a store view that does not report events.
     *
     * @param array<mixed> $sources The block's `page_event_sources` argument
     * @return void
     * @throws \InvalidArgumentException When the settings, an event or the import map cannot be written as JSON, or a
     *      name the page announces is not in the import map
     * @throws \Magento\Framework\Exception\LocalizedException When a module's file cannot be resolved
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is no current store view
     */
    public function registerHeadElements(array $sources): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->addScript(self::KEY_SETTINGS, HeadSortOrderInterface::SETTINGS, [
            'type' => 'application/json',
            'id' => self::CONFIG_ID,
            self::DATA_ATTRIBUTE => self::DATA_SETTINGS,
        ], $this->getConfigJson());
        $this->addScript(self::KEY_PAGE_EVENTS, HeadSortOrderInterface::PAGE_EVENTS, [
            'type' => 'application/json',
            self::DATA_ATTRIBUTE => self::DATA_PAGE_EVENTS,
        ], $this->getPageEventsJson($sources));
        $this->addScript(
            self::KEY_IMPORT_MAP,
            HeadSortOrderInterface::IMPORT_MAP,
            ['type' => 'importmap'],
            $this->getImportMapJson()
        );
        foreach ($this->getModulePreloadUrls() as $url) {
            $this->headTagManager->createElement('link', [
                'attributes' => ['rel' => 'modulepreload', 'href' => $url],
                'sort_order' => HeadSortOrderInterface::MODULE_PRELOAD,
            ], self::KEY_MODULE_PRELOAD . $url);
        }
        $this->addScript(self::KEY_ENTRY, HeadSortOrderInterface::ENTRY, [
            'type' => 'module',
            'src' => $this->getEntryUrl(),
        ], null);
    }

    /**
     * Whether the store view reports events
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Get the page's events as the objects the browser pushes to `dataLayer`
     *
     * @param array<mixed> $sources The block's `page_event_sources` argument
     * @return string A JSON array, empty when the page has no events; anything that is not a page event source is
     *      ignored
     * @throws \InvalidArgumentException When an event cannot be written as JSON
     */
    public function getPageEventsJson(array $sources): string
    {
        $pageSources = [];
        foreach ($sources as $source) {
            if ($source instanceof PageEventSourceInterface) {
                $pageSources[] = $source;
            }
        }

        return $this->json->serialize($this->pageEvents->collect($pageSources));
    }

    /**
     * Get the browser module's settings for the current store view
     *
     * @return string A JSON object keyed by settings provider
     * @throws \InvalidArgumentException When the settings cannot be written as JSON
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is no current store view
     */
    public function getConfigJson(): string
    {
        $config = $this->browserConfig->forStore((int) $this->storeManager->getStore()->getId());

        return $config === [] ? '{}' : $this->json->serialize($config);
    }

    /**
     * Get the import map: every name the browser module and its plugins import by, with the file's static address
     *
     * @return string A JSON object `{"imports": {name: address}}`
     * @throws \InvalidArgumentException When the map cannot be written as JSON
     * @throws \Magento\Framework\Exception\LocalizedException When a module's file cannot be resolved
     */
    public function getImportMapJson(): string
    {
        return $this->importMapBuilder->json($this->importMap->imports());
    }

    /**
     * Get the addresses of the modules the browser module loads besides its entry
     *
     * The entry's imports, then the plugins the store view loads, then the modules those plugins import.
     *
     * @return list<string>
     * @throws \InvalidArgumentException When a name the page announces is not in the import map
     * @throws \Magento\Framework\Exception\LocalizedException When a module's file cannot be resolved
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is no current store view
     */
    public function getModulePreloadUrls(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        return array_values(array_unique(array_merge(
            $this->importMap->urlsOf(self::ENTRY_IMPORTS),
            $this->pluginUrls->urls($storeId),
            $this->importMap->urlsOf($this->pluginUrls->imports($storeId))
        )));
    }

    /**
     * Get the static address of the browser module's entry, for the current theme and locale
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException When the entry's file cannot be resolved
     */
    public function getEntryUrl(): string
    {
        return $this->importMapBuilder->addresses([self::ENTRY => self::ENTRY])[self::ENTRY];
    }

    /**
     * Register one script element with the head tag manager
     *
     * @param string $key
     * @param int $sortOrder
     * @param array<string,string> $attributes
     * @param string|null $content Null for an external script
     * @return void
     */
    private function addScript(string $key, int $sortOrder, array $attributes, ?string $content): void
    {
        $this->headTagManager->createElement('script', [
            'attributes' => $attributes,
            'content' => $content,
            'sort_order' => $sortOrder,
        ], $key);
    }
}
