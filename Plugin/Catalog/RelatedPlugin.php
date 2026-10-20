<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Catalog;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Magento\Catalog\Block\Product\ProductList\Related;

/**
 * Appends the list data of a product page's related products, read from the items the block already rendered
 */
class RelatedPlugin
{
    private const LIST_ID = 'related_products';
    private const LIST_NAME = 'Related Products';

    /**
     * @param ProductListAppenderInterface $appender
     */
    public function __construct(private readonly ProductListAppenderInterface $appender)
    {
    }

    /**
     * Append the list data after the block's HTML
     *
     * @param Related $subject
     * @param string $result The block's HTML
     * @return string
     */
    public function afterToHtml(Related $subject, string $result): string
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
