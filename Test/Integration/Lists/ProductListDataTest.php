<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration\Lists;

use Hryvinskyi\DataLayer\Test\Integration\Lists\ListFixtures;
use Hryvinskyi\DataLayer\Model\ListDataRenderer;
use Hryvinskyi\DataLayerApi\Api\ListDataRendererInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

/**
 * A category page's product list, built from the real category page layout, carries its list data after its HTML,
 * reads the products its own render loaded, and builds the data with a number of queries that does not grow with
 * the number of products
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class ProductListDataTest extends TestCase
{
    use ListFixtures;

    private const ROOT_CATEGORY_ID = 2;
    private const LIST_START = '<script data-hryvinskyi-datalayer-list="([0-9a-f]+)" type="application/json" '
        . 'data-hryvinskyi-datalayer="list-start"></script>';
    private const LIST_DATA = '<script data-hryvinskyi-datalayer-list="([0-9a-f]+)" type="application/json" '
        . 'data-hryvinskyi-datalayer="list">';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cleanCategoryTrees();
    }

    /**
     * The list renderer port resolves to the core renderer
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testRendererPortResolvesToTheCoreRenderer(): void
    {
        self::assertInstanceOf(ListDataRenderer::class, $this->service(ListDataRendererInterface::class));
    }

    /**
     * The rendered list ends with one data script holding both products, linked by their URL paths
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testRenderedListEndsWithItsListData(): void
    {
        $ids = [$this->createListedProduct('dl-t6-list-a', 12.5), $this->createListedProduct('dl-t6-list-b', 20.0)];
        $collection = $this->collectionOf($ids);

        $html = $this->listBlock($collection)->toHtml();
        $data = $this->listDataOf($html);

        self::assertSame(
            1,
            preg_match('~^\s*(?:<[a-z][^>]*>\s*)*' . self::LIST_START . '~', $html, $start),
            'Nothing but the opening tags of an element enclosing the whole list precedes the start marker.'
        );
        self::assertSame(1, preg_match_all('~' . self::LIST_START . '~', $html));
        self::assertSame(1, preg_match('~' . self::LIST_DATA . '~', $html, $script));
        self::assertSame($start[1], $script[1], 'The start marker and the data script are paired.');
        self::assertTrue($data['impression']);
        self::assertTrue($data['clicks']);
        $list = $data['list'];
        self::assertIsArray($list);
        self::assertSame('category_' . self::ROOT_CATEGORY_ID, $list['item_list_id']);
        $items = $list['items'];
        self::assertIsArray($items);
        self::assertCount(2, $items);
        $skus = [];
        foreach ($items as $position => $item) {
            self::assertIsArray($item);
            self::assertSame($position, $item['index']);
            self::assertSame('category_' . self::ROOT_CATEGORY_ID, $item['item_list_id']);
            $skus[] = $item['item_id'];
        }
        sort($skus);
        self::assertSame(['dl-t6-list-a', 'dl-t6-list-b'], $skus);
        $links = $data['links'];
        self::assertIsArray($links);
        self::assertCount(2, $links);
        foreach ($links as $path => $position) {
            self::assertIsString($path);
            self::assertIsInt($position);
            self::assertStringStartsWith('/', $path);
            self::assertIsArray($items[$position]);
            self::assertIsString($items[$position]['item_id']);
            self::assertStringContainsString($items[$position]['item_id'], $path);
        }
    }

    /**
     * With select_item disabled the list is still impressed, and carries no links: no click is reported
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/events/disabled select_item
     * @return void
     */
    public function testDisabledClicksPrintNoLinks(): void
    {
        $collection = $this->collectionOf([$this->createListedProduct('dl-list-clicks-a')]);

        $data = $this->listDataOf($this->listBlock($collection)->toHtml());

        self::assertTrue($data['impression']);
        self::assertFalse($data['clicks']);
        self::assertSame([], $data['links']);
    }

    /**
     * With both list events disabled the list's HTML is left as it is: no marker, no data
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/events/disabled view_item_list,select_item
     * @return void
     */
    public function testDisabledListEventsPrintNothing(): void
    {
        $collection = $this->collectionOf([$this->createListedProduct('dl-list-off-a')]);

        $html = $this->listBlock($collection)->toHtml();

        self::assertNotSame('', trim($html));
        self::assertStringNotContainsString('data-hryvinskyi-datalayer', $html);
    }

    /**
     * Rendering the list twice loads its product collection once: the data is read from what the render loaded
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testProductCollectionIsLoadedOnce(): void
    {
        $collection = $this->collectionOf(
            [$this->createListedProduct('dl-t6-once-a'), $this->createListedProduct('dl-t6-once-b')]
        );
        $block = $this->listBlock($collection);

        $first = $block->toHtml();
        $second = $block->toHtml();

        self::assertSame(1, $collection->loads);
        self::assertCount(2, (array) $this->listDataOf($first)['links']);
        self::assertSame($this->listDataOf($first), $this->listDataOf($second));
    }

    /**
     * Building a rendered list's data costs the same queries for two products as for four: nothing per product
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testListDataQueriesDoNotGrowWithTheNumberOfProducts(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c', 'd'] as $suffix) {
            $ids[] = $this->createListedProduct('dl-t6-count-' . $suffix);
        }

        $two = $this->listDataQueries(array_slice($ids, 0, 2));
        $four = $this->listDataQueries($ids);

        self::assertCount(count($two), $four, "Queries for two products:\n" . implode("\n", $two)
            . "\nQueries for four products:\n" . implode("\n", $four));
    }

    /**
     * Render a list of the given products as the category page does, then count the queries of building its data
     * again from the products the render loaded
     *
     * @param list<int> $ids
     * @return list<string>
     */
    private function listDataQueries(array $ids): array
    {
        $collection = $this->collectionOf($ids);
        $this->listBlock($collection)->toHtml();
        $products = [];
        foreach ($collection->getItems() as $product) {
            self::assertInstanceOf(ProductInterface::class, $product);
            $products[] = $product;
        }
        self::assertCount(count($ids), $products);
        $renderer = $this->service(ListDataRendererInterface::class);

        return $this->queriesOf(
            static fn (): string => $renderer->renderProductList('category_2', 'Default Category', $products)
        );
    }

    /**
     * A product collection limited to the given products, counting its loads
     *
     * @param list<int> $ids
     * @return CountingProductCollection
     */
    private function collectionOf(array $ids): CountingProductCollection
    {
        $collection = $this->fresh(CountingProductCollection::class);
        $collection->addAttributeToSelect('*')->addIdFilter($ids)->addStoreFilter();

        return $collection;
    }

    /**
     * The category page's product list block, showing the given collection under the store's root category
     *
     * @param CountingProductCollection $collection
     * @return ListProduct
     */
    private function listBlock(CountingProductCollection $collection): ListProduct
    {
        $page = $this->service(PageFactory::class)->create();
        $page->addHandle(['default', 'catalog_category_view']);
        $layout = $page->getLayout();
        $layout->generateXml();
        $block = $layout->getBlock('category.products.list');
        self::assertInstanceOf(ListProduct::class, $block);
        $block->getLayer()->setCurrentCategory(self::ROOT_CATEGORY_ID);
        $block->setCollection($collection);

        return $block;
    }
}
