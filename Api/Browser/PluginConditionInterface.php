<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Browser;

/**
 * Decides whether a store view's pages load one browser plugin
 *
 * The answer is printed into pages the full-page cache shares between visitors, so it depends on store-level
 * settings only — never on the visitor, the session or the cart.
 */
interface PluginConditionInterface
{
    /**
     * Whether the store view loads the plugin
     *
     * @param int $storeId
     * @return bool
     */
    public function isMet(int $storeId): bool;
}
