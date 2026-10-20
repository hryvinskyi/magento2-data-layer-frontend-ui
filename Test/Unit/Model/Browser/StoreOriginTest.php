<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Model\Browser\StoreOrigin;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/**
 * A request belongs to the store when its Origin, or else its Referer, names the host of the store's secure or
 * unsecure base URL
 */
class StoreOriginTest extends TestCase
{
    /**
     * Each pair of headers against a store served on two hosts
     *
     * @param string|null $origin
     * @param string|null $referer
     * @param bool $expected
     * @return void
     * @dataProvider headerProvider
     */
    public function testOriginDecidesThenReferer(?string $origin, ?string $referer, bool $expected): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getBaseUrl')->willReturnCallback(
            static fn (array $params): string => ($params['_secure'] ?? false)
                ? 'https://secure.shop.test/'
                : 'http://shop.test/'
        );

        self::assertSame($expected, (new StoreOrigin($url))->isStoreOrigin($origin, $referer));
    }

    /**
     * Get headers and whether they come from the store
     *
     * @return array<string, array{string|null, string|null, bool}>
     */
    public static function headerProvider(): array
    {
        return [
            'own origin' => ['https://secure.shop.test', null, true],
            'own unsecure origin' => ['http://shop.test', null, true],
            'origin case and port ignored' => ['https://SHOP.test:8443', null, true],
            'foreign origin' => ['https://evil.test', 'https://shop.test/cart', false],
            'origin decides over referer' => ['https://evil.test', 'https://secure.shop.test/', false],
            'opaque origin' => ['null', 'https://shop.test/', false],
            'no origin, own referer' => [null, 'https://shop.test/checkout/cart/', true],
            'blank origin, own referer' => ['  ', 'https://secure.shop.test/a', true],
            'no origin, foreign referer' => [null, 'https://evil.test/', false],
            'neither' => [null, null, false],
            'sub-domain' => ['https://sub.shop.test', null, false],
            'not a URL' => ['shop.test', null, false],
        ];
    }
}
