<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\ViewModel\PageEvent;

use Hryvinskyi\DataLayerFrontendUi\Api\CurrentProductInterface;
use Hryvinskyi\DataLayer\Api\Event\ViewItemEventBuilderInterface;
use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Supplies `view_item` on a product page
 */
class ViewItem implements ArgumentInterface, PageEventSourceInterface
{
    /**
     * @param CurrentProductInterface $currentProduct
     * @param ViewItemEventBuilderInterface $builder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CurrentProductInterface $currentProduct,
        private readonly ViewItemEventBuilderInterface $builder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function events(): array
    {
        $productId = null;
        try {
            $product = $this->currentProduct->get();
            if ($product === null) {
                return [];
            }
            $productId = $product->getId();
            $event = $this->builder->build($product);
        } catch (\Throwable $exception) {
            $this->logger->error('GA4 view_item could not be built for the product page.', [
                'product_id' => $productId,
                'exception' => $exception,
            ]);

            return [];
        }

        return $event !== null ? [$event] : [];
    }
}
