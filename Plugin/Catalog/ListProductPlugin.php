<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Catalog;

use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ListContextInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Lists\ProductListAppenderInterface;
use Magento\Catalog\Block\Product\ListProduct;

/**
 * Appends the list data of a catalogue product list, for the blocks registered by their name in layout
 *
 * Opt-in by name, because every class extending the product list block passes through here too, and some of those
 * build and load a fresh collection each time their products are asked for: reading them again would repeat the
 * whole query and report a list the shopper did not see. A block whose name is not registered is returned untouched
 * and its products are never asked for. The products are the ones the block's own render already loaded.
 */
class ListProductPlugin
{
    /**
     * @var array<string, ListContextInterface>
     */
    private readonly array $listContexts;

    /**
     * @param ProductListAppenderInterface $appender
     * @param array<mixed> $listContexts Each a ListContextInterface, keyed by the block's name in layout
     * @throws \InvalidArgumentException When a context is not a ListContextInterface
     */
    public function __construct(
        private readonly ProductListAppenderInterface $appender,
        array $listContexts = []
    ) {
        $accepted = [];
        foreach ($listContexts as $blockName => $context) {
            if (!$context instanceof ListContextInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The product list context "%s" must implement %s.',
                    (string) $blockName,
                    ListContextInterface::class
                ));
            }
            $accepted[(string) $blockName] = $context;
        }
        $this->listContexts = $accepted;
    }

    /**
     * Append the list data after the block's HTML
     *
     * @param ListProduct $subject
     * @param string $result The block's HTML
     * @return string
     */
    public function afterToHtml(ListProduct $subject, string $result): string
    {
        $context = $this->listContexts[(string) $subject->getNameInLayout()] ?? null;
        if ($context === null || trim($result) === '') {
            return $result;
        }
        $listId = $context->getListId($subject);
        if ($listId === null) {
            return $result;
        }

        return $this->appender->append(
            $subject,
            $result,
            $listId,
            $context->getListName($subject),
            static function () use ($subject): iterable {
                $collection = $subject->getLoadedProductCollection();

                return $collection->isLoaded() ? $collection->getItems() : [];
            }
        );
    }
}
