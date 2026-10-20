<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model;

use Hryvinskyi\DataLayerFrontendUi\Api\CurrentProductInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Registry;

/**
 * Reads the product the catalogue's product page registered for the request
 *
 * The product page publishes its product only through the request registry, which the framework has deprecated; this
 * adapter is the one place that reads it, so nothing else in the module depends on it.
 */
class CurrentProduct implements CurrentProductInterface
{
    private const REGISTRY_KEY = 'current_product';

    /**
     * @param Registry $registry
     */
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @inheritDoc
     */
    public function get(): ?ProductInterface
    {
        $product = $this->registry->registry(self::REGISTRY_KEY);

        return $product instanceof ProductInterface ? $product : null;
    }
}
