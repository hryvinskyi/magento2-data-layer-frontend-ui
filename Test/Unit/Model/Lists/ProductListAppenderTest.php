<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Lists;

use Hryvinskyi\DataLayerFrontendUi\Model\Lists\ProductListAppender;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerApi\Api\BlockListDataInterface;
use Hryvinskyi\DataLayerApi\Api\EventAvailabilityInterface;
use Hryvinskyi\DataLayerApi\Api\ListDataRendererInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\AbstractBlock;
use PHPUnit\Framework\TestCase;

/**
 * Every list goes through the block list data, which places the script after the HTML; empty HTML, disabled list
 * events and failures leave the HTML untouched, and the products are read only when there is something to report
 */
class ProductListAppenderTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $disabledEvents = [];

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var list<array{0: string, 1: string, 2: list<int>}> Every render the renderer was asked for
     */
    private array $rendered = [];

    /**
     * @var int
     */
    private int $productReads = 0;

    /**
     * @var bool
     */
    private bool $rendererFails = false;

    /**
     * @var list<AbstractBlock> The blocks the block list data was given
     */
    private array $blocks = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    /**
     * The block, its HTML and the rendered script go through the block list data, built from the listed products only
     *
     * @return void
     */
    public function testListGoesThroughBlockListData(): void
    {
        $block = $this->createMock(AbstractBlock::class);

        $html = $this->appender()
            ->append($block, '<ol></ol>', 'related_products', 'Related Products', $this->products());

        self::assertSame('<!--start--><ol></ol><script>related_products</script>', $html);
        self::assertSame([$block], $this->blocks);
        self::assertSame([['related_products', 'Related Products', [1, 2]]], $this->rendered);
    }

    /**
     * Empty HTML is returned as it is and the products are never read
     *
     * @return void
     */
    public function testEmptyHtmlIsUntouched(): void
    {
        $html = $this->appender()->append($this->block(), "  \n", 'related_products', 'Related', $this->products());

        self::assertSame("  \n", $html);
        self::assertSame(0, $this->productReads);
        self::assertSame([], $this->rendered);
        self::assertSame([], $this->blocks);
    }

    /**
     * With both list events disabled (or the module off) the HTML is untouched, the products are never read and the
     * cache is never asked
     *
     * @return void
     */
    public function testDisabledListEventsLeaveTheHtmlUntouched(): void
    {
        $this->disabledEvents = ['view_item_list', 'select_item'];

        self::assertSame(
            '<ol></ol>',
            $this->appender()->append($this->block(), '<ol></ol>', 'x', 'X', $this->products())
        );
        self::assertSame(0, $this->productReads);
        self::assertSame([], $this->blocks);
    }

    /**
     * One enabled list event is enough to append
     *
     * @return void
     */
    public function testOneEnabledListEventIsEnough(): void
    {
        $this->disabledEvents = ['view_item_list'];

        $html = $this->appender()->append($this->block(), '<ol></ol>', 'x', 'X', $this->products());

        self::assertSame('<!--start--><ol></ol><script>x</script>', $html);
    }

    /**
     * A failure is logged and the HTML is returned as it was
     *
     * @return void
     */
    public function testFailureIsLoggedAndHtmlKept(): void
    {
        $this->rendererFails = true;

        $html = $this->appender()->append($this->block(), '<ol></ol>', 'upsell_products', 'U', $this->products());

        self::assertSame('<ol></ol>', $html);
        self::assertCount(1, $this->logger->records);
        self::assertSame('upsell_products', $this->logger->records[0]['context']['list_id'] ?? null);
    }

    /**
     * A block
     *
     * @return AbstractBlock
     */
    private function block(): AbstractBlock
    {
        return $this->createMock(AbstractBlock::class);
    }

    /**
     * Build the appender over fakes; the block list data marks the start and appends whatever is rendered
     *
     * @return ProductListAppender
     */
    private function appender(): ProductListAppender
    {
        $renderer = $this->createMock(ListDataRendererInterface::class);
        $renderer->method('renderProductList')->willReturnCallback(
            function (string $listId, string $listName, array $products): string {
                if ($this->rendererFails) {
                    throw new \InvalidArgumentException('Cannot encode.');
                }
                $ids = [];
                foreach ($products as $product) {
                    self::assertInstanceOf(Product::class, $product);
                    $ids[] = (int) $product->getId();
                }
                $this->rendered[] = [$listId, $listName, $ids];

                return '<script>' . $listId . '</script>';
            }
        );
        $availability = $this->createMock(EventAvailabilityInterface::class);
        $availability->method('isEnabled')->willReturnCallback(
            fn (string $eventName): bool => !in_array($eventName, $this->disabledEvents, true)
        );
        $blockListData = $this->createMock(BlockListDataInterface::class);
        $blockListData->method('append')->willReturnCallback(
            function (AbstractBlock $block, string $html, callable $render): string {
                $this->blocks[] = $block;
                $data = $render();
                self::assertIsString($data);

                return $data === '' ? $html : '<!--start-->' . $html . $data;
            }
        );

        return new ProductListAppender($renderer, $blockListData, $availability, $this->logger);
    }

    /**
     * A product source holding two products and something that is not a product
     *
     * @return callable(): iterable<mixed>
     */
    private function products(): callable
    {
        return function (): array {
            $this->productReads++;
            $first = $this->createMock(Product::class);
            $first->method('getId')->willReturn(1);
            $second = $this->createMock(Product::class);
            $second->method('getId')->willReturn(2);

            return [$first, 'not a product', $second];
        };
    }
}
