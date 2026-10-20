<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\ViewModel;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Head\HeadSortOrderInterface;
use Hryvinskyi\HeadTagManager\Api\HeadTagManagerInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The Google tag loaders for the store view: Google Tag Manager's container (its inline loader in `<head>` and the
 * address of its `<noscript>` fallback) and gtag.js (the library and the inline stub that configures it)
 *
 * A store view loads the container when it has a container id, gtag.js when it has a gtag destination (a Google
 * Analytics 4 measurement id, or a Google Ads account the page reports conversions to), both when it has both, and
 * nothing while the data layer is off. The loaders go into `<head>` through the head tag manager, at the places
 * {@see HeadSortOrderInterface} gives them, so they lead the data layer's own elements and no script optimiser moves
 * them; the manager renders them through the secure renderer, which handles the content security policy. Every id
 * and the host are written through the JSON serializer, so none can leave its script literal. The script attributes
 * are the ones a consent manager reads; a module that needs its own adds them to the `scriptAttributes` argument, and
 * they go on every loader script.
 */
class GtmLoader implements ArgumentInterface
{
    private const GTAG_URL = 'https://%s/gtag/js?id=%s';

    /**
     * The head tag manager's keys of the loaders: registering again replaces them instead of adding copies
     */
    private const KEY_GTM_LOADER = 'hryvinskyi_datalayer_gtm_loader';
    private const KEY_GTAG_LIBRARY = 'hryvinskyi_datalayer_gtag_library';
    private const KEY_GTAG_STUB = 'hryvinskyi_datalayer_gtag_stub';

    /**
     * @param ConfigInterface $config
     * @param JsonHexTag $json
     * @param GtagDestinationsInterface $gtagDestinations
     * @param HeadTagManagerInterface $headTagManager Prints the loaders into `<head>`
     * @param array<string,string> $scriptAttributes
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly JsonHexTag $json,
        private readonly GtagDestinationsInterface $gtagDestinations,
        private readonly HeadTagManagerInterface $headTagManager,
        private readonly array $scriptAttributes = []
    ) {
    }

    /**
     * Hand the store view's loaders to the head tag manager: the container's loader, then gtag.js and its stub
     *
     * Registers nothing for a store view that loads no Google tag.
     *
     * @return void
     * @throws \InvalidArgumentException When the container id, the host or a destination cannot be written as JSON
     */
    public function registerHeadElements(): void
    {
        $tagManager = $this->loaderScript();
        if ($tagManager !== '') {
            $this->headTagManager->createElement('script', [
                'attributes' => $this->scriptAttributes,
                'content' => $tagManager,
                'sort_order' => HeadSortOrderInterface::GTM_LOADER,
            ], self::KEY_GTM_LOADER);
        }

        $destinations = $this->gtagDestinations->forStore();
        if ($destinations === []) {
            return;
        }
        $this->headTagManager->createElement('script', [
            'attributes' => $this->scriptAttributes + [
                'async' => 'async',
                'src' => sprintf(self::GTAG_URL, ConfigInterface::DEFAULT_SCRIPT_HOST, rawurlencode($destinations[0])),
            ],
            'sort_order' => HeadSortOrderInterface::GTAG_LIBRARY,
        ], self::KEY_GTAG_LIBRARY);
        $this->headTagManager->createElement('script', [
            'attributes' => $this->scriptAttributes,
            'content' => $this->gtagStub($destinations),
            'sort_order' => HeadSortOrderInterface::GTAG_STUB,
        ], self::KEY_GTAG_STUB);
    }

    /**
     * Get the address of the `<noscript>` fallback frame
     *
     * @return string Empty when the store view loads no container
     */
    public function getNoscriptUrl(): string
    {
        $containerId = $this->config->getContainerId();
        if (!$this->config->isEnabled() || $containerId === null) {
            return '';
        }

        return 'https://' . $this->config->getScriptHost() . '/ns.html?id=' . rawurlencode($containerId);
    }

    /**
     * Get the Tag Manager loader script
     *
     * @return string The standard loader for the container, fetching `gtm.js` from the configured host; empty when
     *      the store view loads no container
     * @throws \InvalidArgumentException When the container id or the host cannot be written as JSON
     */
    private function loaderScript(): string
    {
        $containerId = $this->config->getContainerId();
        if (!$this->config->isEnabled() || $containerId === null) {
            return '';
        }

        return "(function(w,d,s,l,i,h){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
            . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';"
            . "j.async=true;j.src='https://'+h+'/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})"
            . "(window,document,'script','dataLayer'," . $this->json->serialize($containerId) . ','
            . $this->json->serialize($this->config->getScriptHost()) . ');';
    }

    /**
     * Get the inline gtag.js stub: the `gtag()` function, its start and one `config` per destination
     *
     * It must run before anything calls `gtag()`, so it is inline; everything else gtag.js does comes from its own
     * file.
     *
     * @param non-empty-list<string> $destinations
     * @return string
     * @throws \InvalidArgumentException When a destination cannot be written as JSON
     */
    private function gtagStub(array $destinations): string
    {
        $script = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            . "gtag('js',new Date());";
        foreach ($destinations as $destination) {
            $script .= "gtag('config'," . $this->json->serialize($destination) . ');';
        }

        return $script;
    }
}
