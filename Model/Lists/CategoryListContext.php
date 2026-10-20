<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Lists;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ListContextInterface;
use Magento\Catalog\Block\Product\ListProduct;

/**
 * Names a category page's product list after the layer's current category: `category_<id>` and the category's name
 *
 * The `category_<id>` id also tells the item pipeline which category path to report for the listed products.
 */
class CategoryListContext implements ListContextInterface
{
    private const ID_PREFIX = 'category_';

    /**
     * @inheritDoc
     */
    public function getListId(ListProduct $block): ?string
    {
        $categoryId = $block->getLayer()->getCurrentCategory()->getId();

        return is_numeric($categoryId) && (int) $categoryId > 0 ? self::ID_PREFIX . (int) $categoryId : null;
    }

    /**
     * @inheritDoc
     */
    public function getListName(ListProduct $block): string
    {
        return (string) $block->getLayer()->getCurrentCategory()->getName();
    }
}
