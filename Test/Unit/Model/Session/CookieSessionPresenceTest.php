<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Session;

use Hryvinskyi\DataLayerFrontendUi\Model\Session\CookieSessionPresence;
use Magento\Framework\Session\Config\ConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * A visitor has a session when the request carries a non-empty session cookie under the configured name
 */
class CookieSessionPresenceTest extends TestCase
{
    /**
     * The configured cookie name decides
     *
     * @param array<string, string> $cookies
     * @param bool $expected
     * @return void
     * @dataProvider cookieProvider
     */
    public function testSessionCookieDecides(array $cookies, bool $expected): void
    {
        $reader = $this->createMock(CookieReaderInterface::class);
        $reader->method('getCookie')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $cookies[$name] ?? $default
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getName')->willReturn('STORESESSID');

        self::assertSame($expected, (new CookieSessionPresence($reader, $config))->exists());
    }

    /**
     * Get request cookies and whether they show a session
     *
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function cookieProvider(): array
    {
        return [
            'session cookie' => [['STORESESSID' => 'abc123'], true],
            'empty session cookie' => [['STORESESSID' => ''], false],
            'another name' => [['PHPSESSID' => 'abc123'], false],
            'no cookies' => [[], false],
        ];
    }
}
