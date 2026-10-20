<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Browser;

/**
 * The addresses of the browser plugins: modules that other modules add to the browser module
 */
interface PluginUrlsInterface
{
    /**
     * Get the static address of every plugin a store view loads
     *
     * For the current theme and locale, in registration order.
     *
     * @param int $storeId
     * @return list<string>
     * @throws \Magento\Framework\Exception\LocalizedException When a plugin's file cannot be resolved
     */
    public function urls(int $storeId): array;

    /**
     * Get the names of the modules the plugins a store view loads import, for the page to fetch them at once
     *
     * In registration order, each name once.
     *
     * @param int $storeId
     * @return list<string> Names of the browser module's import map
     */
    public function imports(int $storeId): array;
}
