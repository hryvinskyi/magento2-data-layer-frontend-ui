<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Plugin\Catalog;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ListContextInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Hryvinskyi\DataLayerFrontendUi\Plugin\Catalog\ListProductPlugin;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\View\Element\AbstractBlock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only product lists registered by their name in layout report a list; any other is untouched and its products are
 * never asked for; registered lists report the products their own render loaded
 */
class ListProductPluginTest extends TestCase
{
    /**
     * @var list<array{html: string, id: string, name: string, products: list<mixed>}>
     */
    private array $appended = [];

    /**
     * A block whose name is not registered is returned untouched without reading its products
     *
     * @return void
     */
    public function testUnlistedBlockIsUntouched(): void
    {
        $block = $this->block('home.latest.products');
        $block->expects(self::never())->method('getLoadedProductCollection');

        self::assertSame('<ol></ol>', $this->plugin()->afterToHtml($block, '<ol></ol>'));
        self::assertSame([], $this->appended);
    }

    /**
     * A block without a name is untouched too
     *
     * @return void
     */
    public function testUnnamedBlockIsUntouched(): void
    {
        $block = $this->block(null);
        $block->expects(self::never())->method('getLoadedProductCollection');

        self::assertSame('<ol></ol>', $this->plugin()->afterToHtml($block, '<ol></ol>'));
    }

    /**
     * A registered block reports its context's id and name with the products of its loaded collection
     *
     * @return void
     */
    public function testRegisteredBlockReportsItsLoadedProducts(): void
    {
        $product = $this->createMock(Product::class);
        $block = $this->block('category.products.list', $this->collection(true, [$product]));

        self::assertSame('<ol></ol>+data', $this->plugin()->afterToHtml($block, '<ol></ol>'));
        self::assertSame(
            [['html' => '<ol></ol>', 'id' => 'category_12', 'name' => 'Laptops', 'products' => [$product]]],
            $this->appended
        );
    }

    /**
     * A collection the block did not load is never loaded: the list reports nothing
     *
     * @return void
     */
    public function testUnloadedCollectionIsNeverLoaded(): void
    {
        $collection = $this->collection(false, []);
        $collection->expects(self::never())->method('getItems');
        $block = $this->block('category.products.list', $collection);

        $this->plugin()->afterToHtml($block, '<ol></ol>');

        self::assertSame([], $this->appended[0]['products']);
    }

    /**
     * A context that cannot name the list leaves the block untouched
     *
     * @return void
     */
    public function testContextWithoutAnIdLeavesTheBlockUntouched(): void
    {
        $block = $this->block('search_result_list');
        $block->expects(self::never())->method('getLoadedProductCollection');

        self::assertSame('<ol></ol>', $this->plugin()->afterToHtml($block, '<ol></ol>'));
    }

    /**
     * Something other than a list context is refused when the plugin is built
     *
     * @return void
     */
    public function testForeignContextIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ListProductPlugin($this->createMock(ProductListAppenderInterface::class), ['x' => new \stdClass()]);
    }

    /**
     * Build the plugin with a context per registered name and a recording appender
     *
     * @return ListProductPlugin
     */
    private function plugin(): ListProductPlugin
    {
        $appender = $this->createMock(ProductListAppenderInterface::class);
        $appender->method('append')->willReturnCallback(
            function (
                AbstractBlock $block,
                string $html,
                string $listId,
                string $listName,
                callable $products
            ): string {
                $read = [];
                $listed = $products();
                self::assertIsIterable($listed);
                foreach ($listed as $product) {
                    $read[] = $product;
                }
                $this->appended[] = ['html' => $html, 'id' => $listId, 'name' => $listName, 'products' => $read];

                return $html . '+data';
            }
        );

        return new ListProductPlugin($appender, [
            'category.products.list' => $this->context('category_12', 'Laptops'),
            'search_result_list' => $this->context(null, ''),
        ]);
    }

    /**
     * A list context with a fixed answer
     *
     * @param string|null $listId
     * @param string $listName
     * @return ListContextInterface
     */
    private function context(?string $listId, string $listName): ListContextInterface
    {
        $context = $this->createMock(ListContextInterface::class);
        $context->method('getListId')->willReturn($listId);
        $context->method('getListName')->willReturn($listName);

        return $context;
    }

    /**
     * A product list block with a name in layout
     *
     * @param string|null $name
     * @param Collection|null $collection
     * @return ListProduct&MockObject
     */
    private function block(?string $name, ?Collection $collection = null): ListProduct
    {
        $block = $this->createMock(ListProduct::class);
        $block->method('getNameInLayout')->willReturn($name);
        if ($collection !== null) {
            $block->method('getLoadedProductCollection')->willReturn($collection);
        }

        return $block;
    }

    /**
     * A product collection, loaded or not
     *
     * @param bool $loaded
     * @param list<Product> $items
     * @return Collection&MockObject
     */
    private function collection(bool $loaded, array $items): Collection
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('isLoaded')->willReturn($loaded);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }
}
