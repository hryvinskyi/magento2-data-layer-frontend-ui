<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Lists;

use Hryvinskyi\DataLayerFrontendUi\Model\Lists\CategoryListContext;
use Hryvinskyi\DataLayerFrontendUi\Model\Lists\FixedListContext;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use PHPUnit\Framework\TestCase;

/**
 * A category list is named after the layer's current category; a fixed list after its configured id and name
 */
class ListContextTest extends TestCase
{
    /**
     * The current category gives `category_<id>` and its name
     *
     * @return void
     */
    public function testCategoryListIsNamedAfterTheCurrentCategory(): void
    {
        $block = $this->blockOnCategory(12, 'Laptops');
        $context = new CategoryListContext();

        self::assertSame('category_12', $context->getListId($block));
        self::assertSame('Laptops', $context->getListName($block));
    }

    /**
     * A layer without a current category names no list
     *
     * @return void
     */
    public function testNoCurrentCategoryNamesNoList(): void
    {
        self::assertNull((new CategoryListContext())->getListId($this->blockOnCategory(null, null)));
    }

    /**
     * A fixed context gives its id and its name
     *
     * @return void
     */
    public function testFixedListGivesItsIdAndName(): void
    {
        $context = new FixedListContext('search_results', 'Search Results');
        $block = $this->createMock(ListProduct::class);

        self::assertSame('search_results', $context->getListId($block));
        self::assertSame('Search Results', $context->getListName($block));
    }

    /**
     * A fixed context needs an id
     *
     * @return void
     */
    public function testFixedListRefusesAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FixedListContext(' ', 'Search Results');
    }

    /**
     * A product list block whose layer shows a category
     *
     * @param int|null $categoryId
     * @param string|null $name
     * @return ListProduct
     */
    private function blockOnCategory(?int $categoryId, ?string $name): ListProduct
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($categoryId);
        $category->method('getName')->willReturn($name);
        $layer = $this->createMock(Layer::class);
        $layer->method('getCurrentCategory')->willReturn($category);
        $block = $this->createMock(ListProduct::class);
        $block->method('getLayer')->willReturn($layer);

        return $block;
    }
}
