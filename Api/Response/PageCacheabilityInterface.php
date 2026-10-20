<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Response;

use Magento\Framework\App\Response\HttpInterface;

/**
 * Tells whether the response to the current request may be stored by the full-page cache
 *
 * A stored response is served to every visitor of the page, so nothing meant for one visitor (a cookie) may travel
 * with it.
 */
interface PageCacheabilityInterface
{
    /**
     * Whether the full-page cache may store the response
     *
     * @param HttpInterface $response The response about to be sent
     * @return bool
     */
    public function isCacheable(HttpInterface $response): bool;
}
