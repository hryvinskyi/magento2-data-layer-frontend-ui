<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Browser;

/**
 * The import map of the browser module: the names its ES modules and its plugins import one another by, each with the
 * static address of the file it stands for
 *
 * The modules import by name rather than by relative file name, so the published file can carry whatever name the
 * store's static content gives it (a minified copy is published as `.min.js`).
 */
interface ImportMapInterface
{
    /**
     * Get every name with the static address it resolves to, for the current theme and locale
     *
     * @return array<string, string> Keyed by the name a module imports
     * @throws \Magento\Framework\Exception\LocalizedException When a module's file cannot be resolved
     */
    public function imports(): array;

    /**
     * Get the static addresses of the given names, in the given order
     *
     * @param list<string> $names
     * @return list<string>
     * @throws \InvalidArgumentException When a name is not in the map
     * @throws \Magento\Framework\Exception\LocalizedException When a module's file cannot be resolved
     */
    public function urlsOf(array $names): array;
}
