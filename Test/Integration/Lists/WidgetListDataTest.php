<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration\Lists;

use Hryvinskyi\DataLayer\Test\Integration\Lists\ListFixtures;
use Hryvinskyi\DataLayerApi\Api\BlockListDataInterface;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\CatalogWidget\Block\Product\ProductsList;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;

/**
 * A block-cached catalogue products widget carries its list data on a cache hit too, without building it again
 *
 * The widget's collection joins the price index, which a product save fills only once its transaction commits, so
 * these tests run outside the rolled-back transaction and delete their products themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class WidgetListDataTest extends TestCase
{
    use ListFixtures;

    private const SKU_PREFIX = 'dl-t6-widget-';
    private const STORE_ID = 1;
    private const ROOT_CATEGORY_ID = 2;

    /**
     * @var list<int>
     */
    private array $productIds = [];

    /**
     * @var string The SKU prefix of this run's products, unique so no leftover row of an earlier run matches it
     */
    private string $skuPrefix = '';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cleanCategoryTrees();
        $this->service(CacheInterface::class)->clean([AbstractBlock::CACHE_GROUP]);
        $this->skuPrefix = self::SKU_PREFIX . bin2hex(random_bytes(4)) . '-';
        $this->productIds = [
            $this->createListedProduct($this->skuPrefix . 'a', 15.0),
            $this->createListedProduct($this->skuPrefix . 'b', 25.0),
        ];
        $this->showInStoreRoot($this->productIds);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $registry = $this->service(Registry::class);
        $registry->register('isSecureArea', true, true);
        try {
            $resource = $this->service(ProductResource::class);
            foreach ($this->productIds as $productId) {
                $product = $this->fresh(Product::class);
                $resource->load($product, $productId);
                $resource->delete($product);
            }
        } finally {
            $registry->unregister('isSecureArea');
        }
        $connection = $this->service(ResourceConnection::class)->getConnection();
        $connection->delete(
            $this->service(TableMaintainer::class)->getMainTable(self::STORE_ID),
            ['product_id IN (?)' => $this->productIds]
        );
        $this->service(CacheInterface::class)->clean([AbstractBlock::CACHE_GROUP]);
        $this->cleanCategoryTrees();
    }

    /**
     * The second render comes from the block cache with the same list data, and reads no product
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoCache block_html enabled
     * @return void
     */
    public function testCachedWidgetCarriesTheSameListDataWithoutReadingProducts(): void
    {
        $first = $this->widget()->toHtml();
        $data = $this->listDataOf($first);
        $listId = $this->listOf($data)['item_list_id'];
        self::assertIsString($listId);
        self::assertMatchesRegularExpression('/^catalog_widget_[0-9a-f]{12}$/D', $listId);
        self::assertSame('Data layer widget', $this->listOf($data)['item_list_name']);
        self::assertCount(2, (array) $this->listOf($data)['items']);

        $second = '';
        $queries = $this->queriesOf(function () use (&$second): void {
            $second = $this->widget()->toHtml();
        });

        self::assertSame($first, $second);
        self::assertSame(
            [],
            array_values(array_filter(
                $queries,
                static fn (string $sql): bool => str_contains($sql, 'catalog_product_entity')
            )),
            'A cached widget reads no product.'
        );
    }

    /**
     * On a real widget the list data is rendered on the first call only; the second is served from its cache entry
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @magentoCache block_html enabled
     * @return void
     */
    public function testRenderIsCalledOnlyOnAMiss(): void
    {
        $blockListData = $this->service(BlockListDataInterface::class);
        $renders = 0;
        $render = static function () use (&$renders): string {
            $renders++;

            return '<script type="application/json" data-hryvinskyi-datalayer="list">{"probe":true}</script>';
        };

        $first = $blockListData->append($this->widget(), '<div></div>', $render);
        $second = $blockListData->append($this->widget(), '<div></div>', $render);

        self::assertSame(1, $renders);
        self::assertSame($first, $second);
    }

    /**
     * Place the products in the store's root category index, where the widget looks for visible products
     *
     * A synchronous category reindex would also visit store views whose index tables this sandbox lacks, so the
     * rows are written directly.
     *
     * @param list<int> $productIds
     * @return void
     */
    private function showInStoreRoot(array $productIds): void
    {
        $this->service(ResourceConnection::class)->getConnection()->insertMultiple(
            $this->service(TableMaintainer::class)->getMainTable(self::STORE_ID),
            array_map(
                static fn (int $productId): array => [
                    'category_id' => self::ROOT_CATEGORY_ID,
                    'product_id' => $productId,
                    'position' => 0,
                    'is_parent' => 1,
                    'store_id' => self::STORE_ID,
                    'visibility' => Visibility::VISIBILITY_BOTH,
                ],
                $productIds
            )
        );
    }

    /**
     * Each widget is its own list: the same widget keeps its id whatever its title, and a widget showing other
     * products gets another id
     *
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEachWidgetHasAListIdOfItsOwn(): void
    {
        $listId = fn (ProductsList $widget): mixed
            => $this->listOf($this->listDataOf($widget->toHtml()))['item_list_id'];
        $first = $listId($this->widget());
        $retitled = $this->widget();
        $retitled->setData('title', 'Another title');
        $fewer = $this->widget();
        $fewer->setData('products_count', 1);

        self::assertSame($first, $listId($retitled));
        self::assertNotSame($first, $listId($fewer));
    }

    /**
     * A products widget showing this test's products, as a CMS page places it
     *
     * @return ProductsList
     */
    private function widget(): ProductsList
    {
        $conditions = '^[`1`:^[`type`:`Magento||CatalogWidget||Model||Rule||Condition||Combine`,'
            . '`aggregator`:`all`,`value`:`1`,`new_child`:``^],'
            . '`1--1`:^[`type`:`Magento||CatalogWidget||Model||Rule||Condition||Product`,'
            . '`attribute`:`sku`,`operator`:`^[^]`,`value`:`' . $this->skuPrefix . '`^]^]';
        $block = $this->service(LayoutInterface::class)->createBlock(ProductsList::class, '', [
            'data' => [
                'title' => 'Data layer widget',
                'products_count' => 2,
                'conditions_encoded' => $conditions,
                'template' => 'Magento_CatalogWidget::product/widget/content/grid.phtml',
            ],
        ]);
        self::assertInstanceOf(ProductsList::class, $block);

        return $block;
    }

    /**
     * Get the list object of decoded list data
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function listOf(array $data): array
    {
        self::assertIsArray($data['list']);

        return $data['list'];
    }
}
