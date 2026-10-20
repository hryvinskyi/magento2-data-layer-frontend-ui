<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration\Lists;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * A product collection that counts how many times it really loads from the database
 */
class CountingProductCollection extends Collection
{
    /**
     * @var int
     */
    public int $loads = 0;

    /**
     * Count a load that is about to query the database
     *
     * @return $this
     */
    protected function _beforeLoad()
    {
        $this->loads++;

        return parent::_beforeLoad();
    }
}
