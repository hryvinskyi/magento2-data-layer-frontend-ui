<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Lists;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Hryvinskyi\DataLayerApi\Api\BlockListDataInterface;
use Hryvinskyi\DataLayerApi\Api\EventAvailabilityInterface;
use Hryvinskyi\DataLayerApi\Api\ListDataRendererInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Psr\Log\LoggerInterface;

/**
 * Appends a product list's data script after the block's HTML, never letting the data break the page
 */
class ProductListAppender implements ProductListAppenderInterface
{
    private const VIEW_ITEM_LIST = 'view_item_list';
    private const SELECT_ITEM = 'select_item';

    /**
     * @param ListDataRendererInterface $renderer
     * @param BlockListDataInterface $blockListData
     * @param EventAvailabilityInterface $eventAvailability
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ListDataRendererInterface $renderer,
        private readonly BlockListDataInterface $blockListData,
        private readonly EventAvailabilityInterface $eventAvailability,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function append(
        AbstractBlock $block,
        string $html,
        string $listId,
        string $listName,
        callable $products
    ): string {
        return $this->guarded(
            $html,
            $listId,
            fn (): string => $this->blockListData->append(
                $block,
                $html,
                fn (): string => $this->render($listId, $listName, $products)
            )
        );
    }

    /**
     * Run the append unless there is nothing to append to or nothing to report; a failure leaves the HTML as it was
     *
     * @param string $html
     * @param string $listId
     * @param callable $append
     * @phpstan-param callable(): string $append
     * @return string
     */
    private function guarded(string $html, string $listId, callable $append): string
    {
        if (trim($html) === ''
            || (!$this->eventAvailability->isEnabled(self::VIEW_ITEM_LIST)
                && !$this->eventAvailability->isEnabled(self::SELECT_ITEM))
        ) {
            return $html;
        }

        try {
            return $append();
        } catch (\Throwable $exception) {
            $this->logger->error('The data layer could not add the data of a product list; the list is not tracked.', [
                'list_id' => $listId,
                'exception' => $exception,
            ]);

            return $html;
        }
    }

    /**
     * Render the data script of the given products
     *
     * @param string $listId
     * @param string $listName
     * @param callable $products
     * @phpstan-param callable(): iterable<mixed> $products
     * @return string
     */
    private function render(string $listId, string $listName, callable $products): string
    {
        $listed = [];
        foreach ($products() as $product) {
            if ($product instanceof ProductInterface) {
                $listed[] = $product;
            }
        }

        return $this->renderer->renderProductList($listId, $listName, $listed);
    }
}
