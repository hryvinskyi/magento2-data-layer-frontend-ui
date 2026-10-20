<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\CatalogWidget;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Magento\CatalogWidget\Block\Product\ProductsList;
use Magento\Framework\Data\Collection;

/**
 * Appends the list data of a catalogue products widget, kept beside the widget's own block cache entry
 *
 * The widget's HTML is block-cached, so its list data is cached alongside and its products are read only when that
 * data is not cached. A widget rendered from its cache holds no collection; its products are then read once with the
 * widget's own collection, the query the widget would have run.
 *
 * Each widget is its own list: its `item_list_id` is `catalog_widget_` followed by a short hash of what decides its
 * products (its conditions and how many products it shows). The id stays the same across renders, store views and
 * languages, and two widgets showing different products never share it; the title, which is translated content, is
 * the list's name and plays no part in its id.
 */
class ProductsListPlugin
{
    private const LIST_ID_PREFIX = 'catalog_widget_';
    private const LIST_ID_HASH_LENGTH = 12;
    private const LIST_NAME = 'Catalog Products List';

    /**
     * @param ProductListAppenderInterface $appender
     */
    public function __construct(private readonly ProductListAppenderInterface $appender)
    {
    }

    /**
     * Append the list data after the widget's HTML
     *
     * @param ProductsList $subject
     * @param string $result The widget's HTML
     * @return string
     */
    public function afterToHtml(ProductsList $subject, string $result): string
    {
        $title = $subject->getTitle();

        return $this->appender->append(
            $subject,
            $result,
            $this->listId($subject),
            is_string($title) && trim($title) !== '' ? $title : self::LIST_NAME,
            static function () use ($subject): iterable {
                $collection = $subject->getData('product_collection');

                return ($collection instanceof Collection ? $collection : $subject->createCollection())->getItems();
            }
        );
    }

    /**
     * Get the widget's list id from what decides its products
     *
     * The conditions are read as the widget reads them: the encoded conditions it was placed with, else the
     * conditions it was given directly.
     *
     * @param ProductsList $subject
     * @return string
     */
    private function listId(ProductsList $subject): string
    {
        $conditions = $subject->getData('conditions_encoded') ?: $subject->getData('conditions');
        $definition = (string) json_encode([$conditions, $subject->getProductsCount()]);

        return self::LIST_ID_PREFIX . substr(sha1($definition), 0, self::LIST_ID_HASH_LENGTH);
    }
}
