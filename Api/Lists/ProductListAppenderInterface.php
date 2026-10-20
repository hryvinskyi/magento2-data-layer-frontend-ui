<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Lists;

use Magento\Framework\View\Element\AbstractBlock;

/**
 * Appends a product list's data script to the HTML of the block that rendered the list
 *
 * The HTML comes back untouched when it is empty, when neither `view_item_list` nor `select_item` is reported in the
 * store view (the products are then not even read), when there is nothing to report, or when building the data fails
 * (the failure is logged). Otherwise the data script follows the block's HTML directly and an empty start marker
 * precedes it, never wrapping it, so both stay siblings of the block's top-level elements and the browser knows which
 * elements are the list's.
 */
interface ProductListAppenderInterface
{
    /**
     * Append the list data of a block
     *
     * When the block's HTML may come from the block cache, the data is kept beside the block's own cache entry, so a
     * cached block still carries it and its products are read only when the data is not cached.
     *
     * @param AbstractBlock $block The rendered block
     * @param string $html The block's HTML
     * @param string $listId The GA4 `item_list_id`
     * @param string $listName The GA4 `item_list_name`: an analytics key, the same in every store language
     * @param callable $products Returns the products the block displays, in display order; called only when the data
     *     is not taken from the cache
     * @phpstan-param callable(): iterable<mixed> $products
     * @return string
     */
    public function append(
        AbstractBlock $block,
        string $html,
        string $listId,
        string $listName,
        callable $products
    ): string;
}
