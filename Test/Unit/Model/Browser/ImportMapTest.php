<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Model\Browser\ImportMap;
use Hryvinskyi\DataLayer\Model\Browser\ImportMapBuilder;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Asset\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The import map resolves each registered name to the address the asset repository gives its file, minified name
 * included, and refuses registrations and names it does not know
 */
class ImportMapTest extends TestCase
{
    private const STATIC = 'https://static.test/frontend/';

    /**
     * Every name resolves to its file's static address, whatever name the repository publishes the file under
     *
     * @return void
     */
    public function testNamesResolveThroughTheAssetRepository(): void
    {
        $map = new ImportMap($this->builder(), [
            '@vendor/feature/a.js' => 'Vendor_Module::js/feature/a.js',
            '@vendor/feature/b.js' => 'Vendor_Module::js/feature/b.js',
        ]);

        self::assertSame(
            [
                '@vendor/feature/a.js' => self::STATIC . 'Vendor_Module/js/feature/a.min.js',
                '@vendor/feature/b.js' => self::STATIC . 'Vendor_Module/js/feature/b.min.js',
            ],
            $map->imports()
        );
        self::assertSame(
            [self::STATIC . 'Vendor_Module/js/feature/b.min.js', self::STATIC . 'Vendor_Module/js/feature/a.min.js'],
            $map->urlsOf(['@vendor/feature/b.js', '@vendor/feature/a.js'])
        );
        self::assertSame([], (new ImportMap($this->builder()))->imports());
    }

    /**
     * Asking for a name nobody registered fails loudly, naming it
     *
     * @return void
     */
    public function testUnknownNameFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"@vendor/typo.js"');

        (new ImportMap($this->builder(), ['@vendor/a.js' => 'Vendor_Module::js/a.js']))
            ->urlsOf(['@vendor/typo.js']);
    }

    /**
     * A registration that is not a name with a module script's file id fails when the map is built
     *
     * @param array<int|string, mixed> $modules
     * @return void
     * @dataProvider badModulesProvider
     */
    #[DataProvider('badModulesProvider')]
    public function testMalformedRegistrationFails(array $modules): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must map to the file id of a module script');

        (new ImportMap($this->builder(), $modules))->imports();
    }

    /**
     * Asking for a name whose registration is not a module script's file id fails too, naming it
     *
     * @return void
     */
    public function testMalformedRegistrationFailsWhenAskedFor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"@vendor/a.css" must map to the file id of a module script');

        (new ImportMap($this->builder(), ['@vendor/a.css' => 'Vendor_Module::css/a.css']))
            ->urlsOf(['@vendor/a.css']);
    }

    /**
     * Get registrations that are not usable
     *
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function badModulesProvider(): array
    {
        return [
            'theme file' => [['@vendor/a.js' => 'js/a.js']],
            'not a script' => [['@vendor/a.css' => 'Vendor_Module::css/a.css']],
            'absolute address' => [['@vendor/a.js' => 'https://cdn.test/a.js']],
            'trailing newline' => [['@vendor/a.js' => "Vendor_Module::js/a.js\n"]],
            'not text' => [['@vendor/a.js' => 42]],
            'no name' => [['Vendor_Module::js/a.js']],
            'blank name' => [[' ' => 'Vendor_Module::js/a.js']],
        ];
    }

    /**
     * The core's import map builder over an asset repository that publishes minified names
     *
     * @return ImportMapBuilder
     */
    private function builder(): ImportMapBuilder
    {
        return new ImportMapBuilder($this->repository(), new JsonHexTag());
    }

    /**
     * An asset repository that publishes every script under its minified name, as with minification on
     *
     * @return Repository
     */
    private function repository(): Repository
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('getUrl')->willReturnCallback(
            static fn (string $fileId): string => self::STATIC . str_replace(['::', '.js'], ['/', '.min.js'], $fileId)
        );

        return $repository;
    }
}
