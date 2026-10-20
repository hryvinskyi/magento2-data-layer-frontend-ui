<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Hryvinskyi\DataLayerFrontendUi\Controller\Events\Acknowledge;
use Hryvinskyi\DataLayerFrontendUi\Controller\Events\Drain;
use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerApi\Api\BrowserConfigProviderInterface;
use Magento\Framework\UrlInterface;

/**
 * The browser module's own settings, under `core`: when lists are impressed, where waiting server events are taken
 * from and acknowledged, and which plugins to load
 *
 * `impressionTrigger` is `load` or `visible`; `drainUrl` is the store's own address of the endpoint that hands over
 * the events detected on the server, and `acknowledgeUrl` of the one that removes those the page pushed (no session
 * id in either: they are printed into pages the full-page cache shares); `plugins` lists the static addresses of the
 * registered plugins the store view loads.
 */
class CoreConfigProvider implements BrowserConfigProviderInterface
{
    /**
     * @param ConfigInterface $config
     * @param UrlInterface $url Builds storefront URLs
     * @param PluginUrlsInterface $pluginUrls
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly UrlInterface $url,
        private readonly PluginUrlsInterface $pluginUrls
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'core';
    }

    /**
     * @inheritDoc
     *
     * @return array{impressionTrigger: string, drainUrl: string, acknowledgeUrl: string, plugins: list<string>}
     */
    public function provide(int $storeId): array
    {
        return [
            'impressionTrigger' => $this->config->getImpressionTrigger($storeId)->value,
            'drainUrl' => $this->url->getUrl(Drain::ROUTE, ['_scope' => $storeId, '_nosid' => true]),
            'acknowledgeUrl' => $this->url->getUrl(Acknowledge::ROUTE, ['_scope' => $storeId, '_nosid' => true]),
            'plugins' => $this->pluginUrls->urls($storeId),
        ];
    }
}
