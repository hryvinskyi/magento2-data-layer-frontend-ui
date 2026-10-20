<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Response;

/**
 * Remembers that the current response was declared public, cacheable by shared caches
 *
 * The full-page cache may rewrite a response's cache headers once it has stored it, so the declaration is remembered
 * when it is made.
 */
interface PublicResponseRecorderInterface
{
    /**
     * Remember that the response was declared public
     *
     * @return void
     */
    public function recordPublic(): void;
}
