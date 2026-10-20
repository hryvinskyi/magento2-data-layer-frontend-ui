<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Checkout;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Magento\Checkout\Block\Cart\Crosssell;

/**
 * Appends the list data of the cart page's cross-sell products, read from the items the block already rendered
 */
class CrosssellPlugin
{
    private const LIST_ID = 'crosssell_products';
    private const LIST_NAME = 'Crosssell Products';

    /**
     * @param ProductListAppenderInterface $appender
     */
    public function __construct(private readonly ProductListAppenderInterface $appender)
    {
    }

    /**
     * Append the list data after the block's HTML
     *
     * @param Crosssell $subject
     * @param string $result The block's HTML
     * @return string
     */
    public function afterToHtml(Crosssell $subject, string $result): string
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
