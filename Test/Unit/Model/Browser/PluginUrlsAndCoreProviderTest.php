<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginConditionInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\PluginUrlsInterface;
use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Model\Browser\CoreConfigProvider;
use Hryvinskyi\DataLayerFrontendUi\Model\Browser\PluginUrls;
use Hryvinskyi\DataLayer\Model\Config\ImpressionTrigger;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Registered plugins resolve to static addresses in their order, a plugin with a condition is left out of the store
 * views that do not meet it, a malformed registration fails, and the core's own part carries the impression trigger,
 * the drain address and the store view's plugins
 */
class PluginUrlsAndCoreProviderTest extends TestCase
{
    private const STATIC = 'https://shop.test/static/version1/frontend/Vendor/theme/en_GB/';

    /**
     * Each file id resolves through the asset repository, in the order the pool gives them
     *
     * @return void
     */
    public function testPluginsResolveInOrder(): void
    {
        $urls = new PluginUrls($this->repository(), [
            'quote' => 'Vendor_Quote::js/data-layer/plugin.js',
            'consent' => 'Other_Consent::js/consent-bridge.mjs',
        ]);

        self::assertSame(
            [
                self::STATIC . 'Vendor_Quote/js/data-layer/plugin.js',
                self::STATIC . 'Other_Consent/js/consent-bridge.mjs',
            ],
            $urls->urls(1)
        );
        self::assertSame([], (new PluginUrls($this->repository()))->urls(1));
    }

    /**
     * A plugin with a condition is loaded only by the store views that meet it, asked with their own id; the others
     * keep their order
     *
     * @return void
     */
    public function testAConditionLimitsItsPluginToTheStoreViewsMeetingIt(): void
    {
        $asked = [];
        $condition = $this->createMock(PluginConditionInterface::class);
        $condition->method('isMet')->willReturnCallback(static function (int $storeId) use (&$asked): bool {
            $asked[] = $storeId;

            return $storeId === 2;
        });
        $urls = new PluginUrls(
            $this->repository(),
            [
                'first' => 'Vendor_First::js/first.js',
                'gated' => 'Vendor_Gated::js/gated.js',
                'last' => 'Vendor_Last::js/last.js',
            ],
            ['gated' => $condition]
        );

        self::assertSame(
            [self::STATIC . 'Vendor_First/js/first.js', self::STATIC . 'Vendor_Last/js/last.js'],
            $urls->urls(1)
        );
        self::assertSame(
            [
                self::STATIC . 'Vendor_First/js/first.js',
                self::STATIC . 'Vendor_Gated/js/gated.js',
                self::STATIC . 'Vendor_Last/js/last.js',
            ],
            $urls->urls(2)
        );
        self::assertSame([1, 2], $asked);
    }

    /**
     * The names a plugin imports are listed only for the store views that load the plugin, in order, each once
     *
     * @return void
     */
    public function testImportsFollowTheirPlugins(): void
    {
        $condition = $this->createMock(PluginConditionInterface::class);
        $condition->method('isMet')->willReturnCallback(static fn (int $storeId): bool => $storeId === 2);
        $urls = new PluginUrls(
            $this->repository(),
            ['first' => 'Vendor_First::js/first.js', 'gated' => 'Vendor_Gated::js/gated.js'],
            ['gated' => $condition],
            ['first' => ['@vendor/shared.js', '@vendor/first.js'], 'gated' => ['@vendor/gated.js', '@vendor/shared.js']]
        );

        self::assertSame(['@vendor/shared.js', '@vendor/first.js'], $urls->imports(1));
        self::assertSame(['@vendor/shared.js', '@vendor/first.js', '@vendor/gated.js'], $urls->imports(2));
        self::assertSame([], (new PluginUrls($this->repository()))->imports(1));
    }

    /**
     * Imports that name no plugin, or that are not a list of names, fail when the list is built
     *
     * @param array<string, mixed> $imports
     * @return void
     * @dataProvider badImportsProvider
     */
    public function testMalformedImportsFail(array $imports): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('imports of the data layer browser plugin');

        new PluginUrls($this->repository(), ['known' => 'Vendor_Module::js/plugin.js'], [], $imports);
    }

    /**
     * Get imports that are not usable
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badImportsProvider(): array
    {
        return [
            'names no plugin' => [['unknown' => ['@vendor/a.js']]],
            'not a list' => [['known' => '@vendor/a.js']],
            'empty name' => [['known' => [' ']]],
            'not a name' => [['known' => [42]]],
        ];
    }

    /**
     * A condition that is not one, or that names no registered plugin, fails when the list is built
     *
     * @param array<string, mixed> $conditions
     * @return void
     * @dataProvider badConditionProvider
     */
    public function testMalformedConditionFails(array $conditions): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('condition "');

        new PluginUrls($this->repository(), ['known' => 'Vendor_Module::js/plugin.js'], $conditions);
    }

    /**
     * Get conditions that are not usable
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badConditionProvider(): array
    {
        return [
            'not a condition' => [['known' => new \stdClass()]],
            'names no plugin' => [['unknown' => new class implements PluginConditionInterface {
                /**
                 * @inheritDoc
                 */
                public function isMet(int $storeId): bool
                {
                    return true;
                }
            }]],
        ];
    }

    /**
     * An entry that is not a module script's file id fails when the list is built, naming the entry
     *
     * @param mixed $fileId
     * @return void
     * @dataProvider badFileIdProvider
     */
    public function testMalformedRegistrationFails(mixed $fileId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plugin "typo"');

        new PluginUrls($this->repository(), ['typo' => $fileId]);
    }

    /**
     * Get entries that are not a module script's file id
     *
     * @return array<string, array{mixed}>
     */
    public static function badFileIdProvider(): array
    {
        return [
            'theme file' => ['js/plugin.js'],
            'not a script' => ['Vendor_Module::css/styles.css'],
            'no module' => ['::js/plugin.js'],
            'absolute address' => ['https://cdn.test/plugin.js'],
            'trailing newline' => ["Vendor_Module::js/plugin.js\n"],
            'not text' => [42],
            'object' => [new \stdClass()],
        ];
    }

    /**
     * The core part holds the store view's impression trigger, the drain address without a session id, and the
     * plugin addresses
     *
     * @return void
     */
    public function testCorePartCarriesTriggerDrainAndPlugins(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getImpressionTrigger')->with(5)->willReturn(ImpressionTrigger::Visible);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $params === ['_scope' => 5, '_nosid' => true]
                ? 'https://shop.test/' . $route . '/'
                : 'wrong parameters'
        );
        $plugins = $this->createMock(PluginUrlsInterface::class);
        $plugins->method('urls')->with(5)->willReturn(['https://shop.test/static/plugin.js']);

        $provider = new CoreConfigProvider($config, $url, $plugins);

        self::assertSame('core', $provider->getKey());
        self::assertSame(
            [
                'impressionTrigger' => 'visible',
                'drainUrl' => 'https://shop.test/hryvinskyi_datalayer/events/drain/',
                'acknowledgeUrl' => 'https://shop.test/hryvinskyi_datalayer/events/acknowledge/',
                'plugins' => ['https://shop.test/static/plugin.js'],
            ],
            $provider->provide(5)
        );
    }

    /**
     * Get an asset repository that resolves a file id the way the static view files are laid out
     *
     * @return Repository
     */
    private function repository(): Repository
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('getUrl')->willReturnCallback(
            static fn (string $fileId): string => self::STATIC . str_replace('::', '/', $fileId)
        );

        return $repository;
    }
}
