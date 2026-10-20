<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Test\Integration\ResolvesServices;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\Bootstrap as BootstrapViewModel;
use Magento\Framework\App\State;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\Minification;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * With script minification on, the browser module and every module it imports are published under their minified
 * names: the head links the entry as `main.min.js`, and the import map resolves each name its modules import one
 * another by to the minified file, as do the addresses the head preloads
 *
 * Minification never applies in developer mode, which the test application runs in, so the asset system is given a
 * minification service that sees production mode.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class BrowserModuleAssetTest extends TestCase
{
    use ResolvesServices;
    use HeadElements;

    private const MODULE_PATH = '/Hryvinskyi_DataLayerFrontendUi/js/data-layer/';

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        if ($objectManager instanceof ObjectManager) {
            $objectManager->removeSharedInstance(Minification::class);
        }
    }

    /**
     * With minification on, the entry, every name of the import map and every preloaded module resolve to minified
     * files
     *
     * @magentoConfigFixture current_store dev/js/minify_files 1
     * @magentoConfigFixture current_store hryvinskyi_datalayer/general/enabled 1
     * @return void
     */
    public function testEntryImportMapAndPreloadsUseMinifiedFiles(): void
    {
        $this->minifyAsInProduction();
        $assets = $this->fresh(Repository::class);

        $control = $assets->getUrl('Magento_Customer::js/customer-data.js');
        self::assertStringEndsWith('/Magento_Customer/js/customer-data.min.js', $control, 'Minification is on.');

        $bootstrap = $this->fresh(BootstrapViewModel::class);
        $bootstrap->registerHeadElements([]);
        $head = $this->headHtml();

        $entry = $this->onlyScript($head, 'type', 'module');
        self::assertStringEndsWith(self::MODULE_PATH . 'main.min.js', $entry->getAttribute('src'));

        $map = $this->printedImportMap($head);
        self::assertNotEmpty($map);
        foreach ($map as $name => $url) {
            self::assertIsString($name);
            self::assertIsString($url);
            $file = substr($name, strlen('@hryvinskyi/data-layer/'), -strlen('.js'));
            self::assertStringEndsWith(self::MODULE_PATH . $file . '.min.js', $url, $name);
        }
        foreach (BootstrapViewModel::ENTRY_IMPORTS as $name) {
            self::assertArrayHasKey($name, $map, 'Every name the entry imports is in the map.');
        }
        $preloaded = $this->preloadedModules($head);
        self::assertNotEmpty($preloaded);
        foreach ($preloaded as $url) {
            self::assertStringEndsWith('.min.js', $url);
        }
    }

    /**
     * Give the asset system a minification service that sees production mode
     *
     * @return void
     */
    private function minifyAsInProduction(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        $state = $objectManager->create(State::class, ['mode' => State::MODE_PRODUCTION]);
        $minification = $objectManager->create(Minification::class, ['appState' => $state]);
        $objectManager->addSharedInstance($minification, Minification::class);
    }
}
