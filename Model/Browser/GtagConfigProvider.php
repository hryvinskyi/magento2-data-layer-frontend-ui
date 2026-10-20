<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerApi\Api\BrowserConfigProviderInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;

/**
 * The gtag destination plugin's settings, under `gtag`: `measurementId`, the Google Analytics 4 stream the data
 * layer's events are sent to
 *
 * Present only where gtag.js is loaded with a measurement id. Where gtag.js is loaded for Google Ads alone, the part
 * is left out and the plugin sends conversions only.
 */
class GtagConfigProvider implements BrowserConfigProviderInterface
{
    /**
     * @param ConfigInterface $config
     * @param GtagDestinationsInterface $destinations
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly GtagDestinationsInterface $destinations
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'gtag';
    }

    /**
     * @inheritDoc
     *
     * @return array{measurementId?: string}
     */
    public function provide(int $storeId): array
    {
        $measurementId = $this->config->getGtagMeasurementId($storeId);
        if ($measurementId === null || !in_array($measurementId, $this->destinations->forStore($storeId), true)) {
            return [];
        }

        return ['measurementId' => $measurementId];
    }
}
