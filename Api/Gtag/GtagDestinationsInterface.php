<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Gtag;

/**
 * The Google tags a store view's pages configure through gtag.js; gtag.js is loaded only when there is one
 */
interface GtagDestinationsInterface
{
    /**
     * Get the tag ids `gtag('config', …)` is called with, in order
     *
     * @param int|null $storeId Null for the current store view
     * @return list<string> Empty when the store view loads no gtag.js
     */
    public function forStore(?int $storeId = null): array;
}
