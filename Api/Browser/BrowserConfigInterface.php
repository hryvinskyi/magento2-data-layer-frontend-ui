<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Browser;

/**
 * The settings the page hands to the browser module: every provider's part, under the provider's key
 */
interface BrowserConfigInterface
{
    /**
     * Get the settings of a store view
     *
     * @param int $storeId
     * @return array<string, array<string, mixed>> Keyed by provider; a provider with nothing to contribute is absent
     */
    public function forStore(int $storeId): array;
}
