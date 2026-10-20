<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Gtag;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginConditionInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;

/**
 * Met where the store view's pages load gtag.js, so its destination plugin is loaded only where it has a tag to feed
 */
class GtagLoadedCondition implements PluginConditionInterface
{
    /**
     * @param GtagDestinationsInterface $destinations
     */
    public function __construct(private readonly GtagDestinationsInterface $destinations)
    {
    }

    /**
     * @inheritDoc
     */
    public function isMet(int $storeId): bool
    {
        return $this->destinations->forStore($storeId) !== [];
    }
}
