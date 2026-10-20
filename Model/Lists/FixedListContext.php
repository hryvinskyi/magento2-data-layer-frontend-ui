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
 * Names a product list with a fixed id and a fixed name, set where the context is registered
 *
 * Both are analytics keys: the name is reported as it is, in every store language.
 */
class FixedListContext implements ListContextInterface
{
    /**
     * @param string $listId The GA4 `item_list_id`, never translated
     * @param string $listName The GA4 `item_list_name`, never translated
     * @throws \InvalidArgumentException When the id is empty
     */
    public function __construct(
        private readonly string $listId,
        private readonly string $listName
    ) {
        if (trim($listId) === '') {
            throw new \InvalidArgumentException('A fixed product list needs a non-empty list id.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getListId(ListProduct $block): ?string
    {
        return $this->listId;
    }

    /**
     * @inheritDoc
     */
    public function getListName(ListProduct $block): string
    {
        return $this->listName;
    }
}
