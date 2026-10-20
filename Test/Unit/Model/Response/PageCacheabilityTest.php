<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Response;

use Hryvinskyi\DataLayerFrontendUi\Model\Response\PageCacheability;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a GET or HEAD answered 200 or 404 and declared public is cacheable: by its header, or by the declaration the
 * built-in cache erases from the header before the response is sent
 */
class PageCacheabilityTest extends TestCase
{
    /**
     * Each request and response, and whether the full-page cache may store the response
     *
     * @return array<string, array{string, int, string|null, bool}>
     */
    public static function responseProvider(): array
    {
        $public = 'public, max-age=86400, s-maxage=86400';
        $noCache = 'no-store, no-cache, must-revalidate, max-age=0';

        return [
            'public page' => ['GET', 200, $public, true],
            'public not found page' => ['GET', 404, $public, true],
            'public HEAD' => ['head', 200, $public, true],
            'redirect' => ['GET', 302, $public, false],
            'error' => ['GET', 503, $public, false],
            'POST' => ['POST', 200, $public, false],
            'uncacheable page' => ['GET', 200, $noCache, false],
            'private page' => ['GET', 200, 'private, max-age=60', false],
            'public without shared age' => ['GET', 200, 'public, max-age=60', false],
            'no header' => ['GET', 200, null, false],
        ];
    }

    /**
     * The response's own cache headers decide
     *
     * @param string $method
     * @param int $status
     * @param string|null $cacheControl
     * @param bool $expected
     * @return void
     * @dataProvider responseProvider
     */
    #[DataProvider('responseProvider')]
    public function testCacheHeadersDecide(string $method, int $status, ?string $cacheControl, bool $expected): void
    {
        $cacheability = new PageCacheability($this->request($method));

        self::assertSame($expected, $cacheability->isCacheable($this->response($status, $cacheControl)));
    }

    /**
     * A page declared public stays cacheable once the cache has rewritten its headers, and a new request forgets it
     *
     * @return void
     */
    public function testADeclaredPublicPageStaysCacheable(): void
    {
        $cacheability = new PageCacheability($this->request('GET'));
        $rewritten = $this->response(200, 'no-store, no-cache, must-revalidate, max-age=0');

        $cacheability->recordPublic();
        self::assertTrue($cacheability->isCacheable($rewritten));
        self::assertFalse($cacheability->isCacheable($this->response(302, null)), 'A redirect never is.');

        $cacheability->_resetState();
        self::assertFalse($cacheability->isCacheable($rewritten));
    }

    /**
     * Without an HTTP request behind it, nothing is cacheable
     *
     * @return void
     */
    public function testNoHttpRequestIsNeverCacheable(): void
    {
        $cacheability = new PageCacheability($this->createMock(RequestInterface::class));
        $cacheability->recordPublic();

        self::assertFalse($cacheability->isCacheable($this->response(200, 'public, max-age=1, s-maxage=1')));
    }

    /**
     * An HTTP request with a method
     *
     * @param string $method
     * @return HttpRequest
     */
    private function request(string $method): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn($method);

        return $request;
    }

    /**
     * A real response with a status and, when given, a Cache-Control header
     *
     * @param int $status
     * @param string|null $cacheControl
     * @return HttpResponse
     */
    private function response(int $status, ?string $cacheControl): HttpResponse
    {
        $response = $this->getMockBuilder(HttpResponse::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $response->setHttpResponseCode($status);
        if ($cacheControl !== null) {
            $response->setHeader('Cache-Control', $cacheControl, true);
        }

        return $response;
    }
}
