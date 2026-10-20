<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Plugin\Session;

use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayerFrontendUi\Plugin\Session\KeepQueueEndpointsSessionless;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Session\SessionStartChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A session is never started for a listed queue endpoint when the visitor has none; every other request, and a
 * visitor with a session, keep the checker's answer
 */
class KeepQueueEndpointsSessionlessTest extends TestCase
{
    private const ROUTES = [
        'drain' => 'hryvinskyi_datalayer/events/drain',
        'acknowledge' => '/hryvinskyi_datalayer/events/acknowledge/',
    ];

    /**
     * Each path, whether the visitor has a session, and whether a session may start
     *
     * @return array<string, array{string, bool, bool}>
     */
    public static function requestProvider(): array
    {
        return [
            'drain without session' => ['/hryvinskyi_datalayer/events/drain/', false, false],
            'drain without trailing slash' => ['/hryvinskyi_datalayer/events/drain', false, false],
            'drain under a store code' => ['/de/hryvinskyi_datalayer/events/drain/', false, false],
            'acknowledge without session' => ['/hryvinskyi_datalayer/events/acknowledge/', false, false],
            'drain with session' => ['/hryvinskyi_datalayer/events/drain/', true, true],
            'acknowledge with session' => ['/hryvinskyi_datalayer/events/acknowledge/', true, true],
            'another action without session' => ['/checkout/cart/add/', false, true],
            'a longer route' => ['/hryvinskyi_datalayer/events/drainage/', false, true],
            'a route ending the same way' => ['/xhryvinskyi_datalayer/events/drain/', false, true],
        ];
    }

    /**
     * A listed endpoint without a session refuses the start; anything else keeps the answer
     *
     * @param string $path
     * @param bool $hasSession
     * @param bool $expected
     * @return void
     * @dataProvider requestProvider
     */
    #[DataProvider('requestProvider')]
    public function testOnlyListedEndpointsWithoutASessionRefuse(string $path, bool $hasSession, bool $expected): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn($path);

        self::assertSame($expected, $this->plugin($request, $hasSession)->afterCheck($this->checker(), true));
    }

    /**
     * A refusal by the checker itself stands, a request that is not HTTP keeps the answer, and without listed routes
     * nothing is refused
     *
     * @return void
     */
    public function testOtherAnswersStand(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getPathInfo')->willReturn('/hryvinskyi_datalayer/events/drain/');

        self::assertFalse($this->plugin($request, false)->afterCheck($this->checker(), false));
        self::assertTrue(
            $this->plugin($this->createMock(RequestInterface::class), false)->afterCheck($this->checker(), true)
        );
        $presence = $this->createMock(SessionPresenceInterface::class);
        self::assertTrue((new KeepQueueEndpointsSessionless($request, $presence))->afterCheck($this->checker(), true));
    }

    /**
     * A listed route that is not a path fails when the plugin is built
     *
     * @return void
     */
    public function testMalformedRouteFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KeepQueueEndpointsSessionless(
            $this->createMock(Http::class),
            $this->createMock(SessionPresenceInterface::class),
            ['broken' => '/']
        );
    }

    /**
     * Build the plugin over a request and the visitor's session cookie
     *
     * @param RequestInterface $request
     * @param bool $hasSession
     * @return KeepQueueEndpointsSessionless
     */
    private function plugin(RequestInterface $request, bool $hasSession): KeepQueueEndpointsSessionless
    {
        $presence = $this->createMock(SessionPresenceInterface::class);
        $presence->method('exists')->willReturn($hasSession);

        return new KeepQueueEndpointsSessionless($request, $presence, self::ROUTES);
    }

    /**
     * The session start checker
     *
     * @return SessionStartChecker
     */
    private function checker(): SessionStartChecker
    {
        return $this->createMock(SessionStartChecker::class);
    }
}
