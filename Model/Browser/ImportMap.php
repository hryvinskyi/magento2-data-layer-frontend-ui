<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayer\Api\Browser\ImportMapBuilderInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\ImportMapInterface;

/**
 * The browser module's registered names, resolved to static addresses by the core's import map builder
 *
 * A module adds the ES modules its browser code imports by name as items of the `modules` argument: the name (a
 * specifier such as `@vendor/feature/file.js`) as the item's name, the file id (`Vendor_Module::js/file.js`) as its
 * value. An entry whose value is not such a file id fails loudly when the map is built, and so does asking for a name
 * no module registered, so a typo is found on the first page instead of a module failing to load in the browser.
 */
class ImportMap implements ImportMapInterface
{
    /**
     * @param ImportMapBuilderInterface $builder Resolves file ids for the current theme and locale
     * @param array<int|string,mixed> $modules File ids keyed by the name modules import them by
     */
    public function __construct(
        private readonly ImportMapBuilderInterface $builder,
        private readonly array $modules = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function imports(): array
    {
        return $this->builder->addresses($this->modules);
    }

    /**
     * @inheritDoc
     */
    public function urlsOf(array $names): array
    {
        $urls = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $this->modules)) {
                throw new \InvalidArgumentException(sprintf(
                    'No browser module is registered under the name "%s".',
                    $name
                ));
            }
            foreach ($this->builder->addresses([$name => $this->modules[$name]]) as $url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
