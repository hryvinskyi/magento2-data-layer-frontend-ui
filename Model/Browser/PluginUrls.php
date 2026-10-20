<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginConditionInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Magento\Framework\View\Asset\Repository;

/**
 * Resolves the registered browser plugins to static addresses
 *
 * A module adds its browser code as an item of the `plugins` argument: the file id of an ES module
 * (`Vendor_Module::js/file.js`) whose default export is called with the browser module's plugin interface. Items are
 * kept in their `sortOrder`. A plugin that only some store views need also gets an item of the same name in the
 * `conditions` argument; without one, every store view loads it. A plugin that imports modules by name lists those
 * names under its item name in the `imports` argument, so the page fetches them with the plugin. An entry that is not
 * such a file id, a condition that is not a plugin condition, a condition or a list of imports naming no plugin, and
 * imports that are not a list of names fail loudly when the list is built, so a typo in a registration is found on
 * the first page, not silently skipped.
 */
class PluginUrls implements PluginUrlsInterface
{
    private const FILE_ID = '/^[A-Za-z0-9]+_[A-Za-z0-9]+::[A-Za-z0-9_\/.-]+\.m?js$/D';

    /**
     * @var array<string, string>
     */
    private readonly array $fileIds;

    /**
     * @var array<string, PluginConditionInterface>
     */
    private readonly array $conditions;

    /**
     * @var array<string, list<string>> The names each plugin imports, keyed by the plugin's item name
     */
    private readonly array $imports;

    /**
     * @param Repository $assetRepository Resolves a file id for the current theme and locale
     * @param array<string|int,mixed> $plugins File ids of the plugins, in sortOrder
     * @param array<string|int,mixed> $conditions Conditions of the plugins only some store views load, keyed by the
     *      plugin's item name
     * @param array<string|int,mixed> $imports Lists of the names each plugin imports, keyed by the plugin's item name
     * @throws \InvalidArgumentException When an entry is not the file id of a module's script, a condition is not
     *      one or names no plugin, or imports name no plugin or are not a list of names
     */
    public function __construct(
        private readonly Repository $assetRepository,
        array $plugins = [],
        array $conditions = [],
        array $imports = []
    ) {
        $fileIds = [];
        foreach ($plugins as $name => $fileId) {
            if (!is_string($fileId) || preg_match(self::FILE_ID, $fileId) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The data layer browser plugin "%s" must be the file id of a module script '
                    . '(Vendor_Module::js/file.js).',
                    $name
                ));
            }
            $fileIds[(string) $name] = $fileId;
        }
        $checked = [];
        foreach ($conditions as $name => $condition) {
            if (!$condition instanceof PluginConditionInterface || !isset($fileIds[(string) $name])) {
                throw new \InvalidArgumentException(sprintf(
                    'The data layer browser plugin condition "%s" must implement %s and name a registered plugin.',
                    $name,
                    PluginConditionInterface::class
                ));
            }
            $checked[(string) $name] = $condition;
        }
        $this->fileIds = $fileIds;
        $this->conditions = $checked;
        $this->imports = $this->checkedImports($imports, $fileIds);
    }

    /**
     * @inheritDoc
     */
    public function urls(int $storeId): array
    {
        $urls = [];
        foreach ($this->fileIds as $name => $fileId) {
            if (isset($this->conditions[$name]) && !$this->conditions[$name]->isMet($storeId)) {
                continue;
            }
            $urls[] = $this->assetRepository->getUrl($fileId);
        }

        return $urls;
    }

    /**
     * @inheritDoc
     */
    public function imports(int $storeId): array
    {
        $names = [];
        foreach ($this->imports as $plugin => $pluginImports) {
            if (isset($this->conditions[$plugin]) && !$this->conditions[$plugin]->isMet($storeId)) {
                continue;
            }
            $names = [...$names, ...$pluginImports];
        }

        return array_values(array_unique($names));
    }

    /**
     * Check the imports of each plugin
     *
     * @param array<string|int,mixed> $imports
     * @param array<string,string> $fileIds The registered plugins
     * @return array<string, list<string>>
     * @throws \InvalidArgumentException When imports name no plugin or are not a list of names
     */
    private function checkedImports(array $imports, array $fileIds): array
    {
        $checked = [];
        foreach ($imports as $plugin => $names) {
            if (!isset($fileIds[(string) $plugin]) || !is_array($names)) {
                throw $this->invalidImports((string) $plugin);
            }
            $list = [];
            foreach ($names as $name) {
                if (!is_string($name) || trim($name) === '') {
                    throw $this->invalidImports((string) $plugin);
                }
                $list[] = $name;
            }
            $checked[(string) $plugin] = $list;
        }

        return $checked;
    }

    /**
     * Describe imports that are not a list of names or name no plugin
     *
     * @param string $plugin
     * @return \InvalidArgumentException
     */
    private function invalidImports(string $plugin): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            'The imports of the data layer browser plugin "%s" must be a list of names and name a registered plugin.',
            $plugin
        ));
    }
}
