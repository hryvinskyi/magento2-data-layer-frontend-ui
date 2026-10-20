<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Gtag;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;

/**
 * Decides which Google tags gtag.js configures for a store view
 *
 * Nothing while the data layer is off. The Google Analytics 4 measurement id when one is set. The Google Ads account
 * when conversions are on with an id and a label, and gtag.js is the page's way of reporting them: either gtag.js is
 * loaded anyway (a measurement id is set) or there is no Tag Manager container to fire the conversion tag on the
 * `ads_conversion` event. With a container and no measurement id the container reports the conversion, so gtag.js is
 * not loaded for it and nothing counts it twice.
 */
class GtagDestinations implements GtagDestinationsInterface
{
    /**
     * @param ConfigInterface $config
     */
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    /**
     * @inheritDoc
     */
    public function forStore(?int $storeId = null): array
    {
        if (!$this->config->isEnabled($storeId)) {
            return [];
        }

        $destinations = [];
        $measurementId = $this->config->getGtagMeasurementId($storeId);
        if ($measurementId !== null) {
            $destinations[] = $measurementId;
        }
        $adsId = $this->adsConversionId($storeId);
        if ($adsId !== null && ($measurementId !== null || $this->config->getContainerId($storeId) === null)) {
            $destinations[] = $adsId;
        }

        return $destinations;
    }

    /**
     * Get the Google Ads conversion id when the store view reports conversions
     *
     * @param int|null $storeId
     * @return string|null
     */
    private function adsConversionId(?int $storeId): ?string
    {
        if (!$this->config->isAdsConversionEnabled($storeId)
            || $this->config->getAdsConversionLabel($storeId) === null
        ) {
            return null;
        }

        return $this->config->getAdsConversionId($storeId);
    }
}
