<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model;

use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayerFrontendUi\Model\ServerEventCookie;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The waiting-events cookie: signalling and clearing only record what the response should say, the last request
 * winning; sending writes it once per state per request with the attributes the browser module needs; discarding
 * forgets it; a failure to write it never escapes
 */
class ServerEventCookieTest extends TestCase
{
    /**
     * Sending a signal sets a session cookie for the whole site, readable by scripts, SameSite=Lax, not secure over
     * http
     *
     * @return void
     */
    public function testSendingASignalSetsTheCookie(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->request('GET', false));
        $cookie->signal();
        $cookie->send();

        self::assertCount(1, $cookies->writes);
        self::assertSame('set', $cookies->writes[0]['action']);
        self::assertSame('hdl_events', $cookies->writes[0]['name']);
        self::assertSame('1', $cookies->writes[0]['value']);
        $metadata = $cookies->writes[0]['metadata'];
        self::assertSame('/', $metadata['path'] ?? null);
        self::assertFalse($metadata['http_only'] ?? null);
        self::assertFalse($metadata['secure'] ?? null);
        self::assertSame('Lax', $metadata['samesite'] ?? null);
        self::assertArrayNotHasKey('duration', $metadata);
    }

    /**
     * Over https the cookie is secure, for setting and deleting alike
     *
     * @return void
     */
    public function testSecureRequestGivesASecureCookie(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->request('PUT', true));
        $cookie->signal();
        $cookie->send();
        $cookie->clear();
        $cookie->send();

        self::assertSame(['set', 'delete'], array_column($cookies->writes, 'action'));
        self::assertTrue($cookies->writes[0]['metadata']['secure'] ?? null);
        self::assertTrue($cookies->writes[1]['metadata']['secure'] ?? null);
        self::assertSame('/', $cookies->writes[1]['metadata']['path'] ?? null);
    }

    /**
     * The last of signal and clear in a request is what the response says
     *
     * @return void
     */
    public function testTheLastRequestWins(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->request('POST', false));
        $cookie->signal();
        $cookie->clear();
        $cookie->send();

        self::assertSame(['delete'], array_column($cookies->writes, 'action'));
    }

    /**
     * A discarded signal (the response may be cached) is never written, nothing asked for writes nothing, and a
     * signal writes nothing before the response is sent
     *
     * @return void
     */
    public function testDiscardedUnaskedOrUnsentSignalWritesNothing(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->request('GET', false));
        $cookie->send();
        $cookie->signal();
        $cookie->discard();
        $cookie->send();
        $cookie->signal();

        self::assertSame([], $cookies->writes);
    }

    /**
     * Any HTTP request's response may carry the signal, whatever its method
     *
     * @return void
     */
    public function testAnyHttpRequestCanSignal(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'put', 'DELETE'] as $method) {
            self::assertTrue($this->cookie(new FakeCookieManager(), $this->request($method, false))->canSignal());
        }
    }

    /**
     * Outside an HTTP request (a command, a consumer) nothing is recorded or written
     *
     * @return void
     */
    public function testNoHttpRequestWritesNothing(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->createMock(RequestInterface::class));
        $cookie->signal();
        $cookie->send();
        $cookie->clear();
        $cookie->send();

        self::assertFalse($cookie->canSignal());
        self::assertSame([], $cookies->writes);
    }

    /**
     * Each state is written once per request however often it is sent; a change of state is written again, and a new
     * request starts afresh
     *
     * @return void
     */
    public function testEachStateIsWrittenOncePerRequest(): void
    {
        $cookies = new FakeCookieManager();
        $cookie = $this->cookie($cookies, $this->request('POST', false));
        $cookie->signal();
        $cookie->send();
        $cookie->send();
        $cookie->clear();
        $cookie->send();
        $cookie->send();
        $cookie->signal();
        $cookie->send();
        self::assertSame(['set', 'delete', 'set'], array_column($cookies->writes, 'action'));

        $cookie->_resetState();
        $cookie->send();
        $cookie->signal();
        $cookie->send();
        self::assertSame(['set', 'delete', 'set', 'set'], array_column($cookies->writes, 'action'));
    }

    /**
     * A cookie that cannot be written is logged with the failure's class and message and never fails the caller
     *
     * @return void
     */
    public function testWriteFailureIsLoggedOnly(): void
    {
        $logger = new RecordingLogger();
        $cookie = new ServerEventCookie(
            new FakeCookieManager(new \RuntimeException('headers already sent')),
            $this->metadataFactory(),
            $this->request('POST', false),
            $logger
        );
        $cookie->signal();
        $cookie->send();
        $cookie->clear();
        $cookie->send();

        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertSame('headers already sent', $logger->records[0]['context']['reason']);
    }

    /**
     * Build the cookie over a cookie manager and a request
     *
     * @param FakeCookieManager $cookies
     * @param RequestInterface $request
     * @return ServerEventCookie
     */
    private function cookie(FakeCookieManager $cookies, RequestInterface $request): ServerEventCookie
    {
        return new ServerEventCookie($cookies, $this->metadataFactory(), $request, new RecordingLogger());
    }

    /**
     * Get a factory of fresh public cookie attributes
     *
     * @return CookieMetadataFactory
     */
    private function metadataFactory(): CookieMetadataFactory
    {
        $factory = $this->createMock(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn (): PublicCookieMetadata => new PublicCookieMetadata()
        );

        return $factory;
    }

    /**
     * Get an HTTP request with a method, over http or https
     *
     * @param string $method
     * @param bool $secure
     * @return Http
     */
    private function request(string $method, bool $secure): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('isSecure')->willReturn($secure);

        return $request;
    }
}
