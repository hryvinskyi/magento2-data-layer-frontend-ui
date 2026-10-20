<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Browser;

/**
 * Tells whether a request was sent by one of the current store's own pages
 */
interface StoreOriginInterface
{
    /**
     * Whether the request's `Origin` (or, without one, its `Referer`) names the store's own host
     *
     * @param string|null $origin The `Origin` header, null when absent
     * @param string|null $referer The `Referer` header, null when absent
     * @return bool False for an opaque origin and for a request with neither header
     */
    public function isStoreOrigin(?string $origin, ?string $referer): bool;
}
