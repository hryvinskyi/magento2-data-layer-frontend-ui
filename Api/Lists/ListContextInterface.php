<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Lists;

use Magento\Catalog\Block\Product\ListProduct;

/**
 * Names the list a catalogue product list block shows, for the GA4 `item_list_id` and `item_list_name`
 *
 * Contexts are registered by the block's name in layout (`listContexts` of the product list plugin): only a block
 * whose name is registered reports a list. Another module opts a block in by adding an item under that block's name.
 */
interface ListContextInterface
{
    /**
     * Get the list id
     *
     * @param ListProduct $block The rendered product list
     * @return string|null Null when the block shows no list this context can name; the block is then left untouched
     */
    public function getListId(ListProduct $block): ?string;

    /**
     * Get the list name, in the store's language
     *
     * @param ListProduct $block The rendered product list
     * @return string
     */
    public function getListName(ListProduct $block): string;
}
