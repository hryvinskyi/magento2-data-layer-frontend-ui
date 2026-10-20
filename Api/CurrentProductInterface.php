<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * The catalogue product the current page shows
 */
interface CurrentProductInterface
{
    /**
     * Get the product of the current product page
     *
     * @return ProductInterface|null Null outside a product page
     */
    public function get(): ?ProductInterface;
}
