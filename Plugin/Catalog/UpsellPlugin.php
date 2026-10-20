<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Catalog;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Magento\Catalog\Block\Product\ProductList\Upsell;

/**
 * Appends the list data of a product page's upsell products, read from the items the block already rendered
 */
class UpsellPlugin
{
    private const LIST_ID = 'upsell_products';
    private const LIST_NAME = 'Upsell Products';

    /**
     * @param ProductListAppenderInterface $appender
     */
    public function __construct(private readonly ProductListAppenderInterface $appender)
    {
    }

    /**
     * Append the list data after the block's HTML
     *
     * @param Upsell $subject
     * @param string $result The block's HTML
     * @return string
     */
    public function afterToHtml(Upsell $subject, string $result): string
    {
        return $this->appender->append(
            $subject,
            $result,
            self::LIST_ID,
            self::LIST_NAME,
            static fn (): iterable => $subject->getItems()
        );
    }
}
