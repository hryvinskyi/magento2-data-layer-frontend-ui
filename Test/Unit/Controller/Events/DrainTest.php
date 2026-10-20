<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Controller\Events;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\StoreOriginInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayerFrontendUi\Controller\Events\Drain;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingSignal;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Translate\InlineInterface;
use PHPUnit\Framework\TestCase;

/**
 * The drain endpoint hands the waiting events with their ids to a request from the store's own pages, never cached,
 * and refuses any other request without touching the queue; a visitor without a session is told that nothing waits,
 * without the queue (and so the session) ever being read
 */
class DrainTest extends TestCase
{
    private const EVENTS = [
        [
            'id' => '0a1b2c3d4e5f6071',
            'event' => [
                'event' => 'add_to_cart',
                'ecommerce' => ['currency' => 'GBP', 'items' => [['item_id' => 'A']]],
            ],
        ],
        ['id' => '8f9e0d1c2b3a4958', 'event' => ['event' => 'login', 'method' => 'website']],
    ];

    /**
     * @var array{code: int|null, headers: array<string, string>, body: string}
     */
    private array $response = ['code' => null, 'headers' => [], 'body' => ''];

    /**
     * @var bool Whether the request carries the session cookie
     */
    private bool $hasSession = true;

    /**
     * @var RecordingSignal
     */
    private RecordingSignal $signal;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->signal = new RecordingSignal();
    }

    /**
     * Without the session cookie the answer is an empty list, the browser is told nothing waits, and the queue is
     * never touched
     *
     * @return void
     */
    public function testVisitorWithoutASessionGetsNothingAndNoSession(): void
    {
        $this->hasSession = false;
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::never())->method('pending');
        $queue->expects(self::never())->method('acknowledge');
        $queue->expects(self::never())->method('hasEvents');
        $origins = [];

        $this->render($this->drain($queue, $this->origin(true, $origins), 'https://shop.test', null));

        self::assertSame(200, $this->response['code']);
        self::assertSame('{"events":[]}', $this->response['body']);
        self::assertSame('no-store', $this->response['headers']['Cache-Control'] ?? null);
        self::assertSame(['clear'], $this->signal->calls);
    }

    /**
     * A request from the store's own page receives the events with their ids; the queue is read once and nothing is
     * removed from it
     *
     * @return void
     */
    public function testSameOriginRequestReceivesTheEvents(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::once())->method('pending')->willReturn(self::EVENTS);
        $queue->expects(self::never())->method('acknowledge');
        $origins = [];

        $this->render($this->drain($queue, $this->origin(true, $origins), 'https://shop.test', null));

        self::assertSame(200, $this->response['code']);
        self::assertSame(['events' => self::EVENTS], json_decode($this->response['body'], true));
        self::assertSame('no-store', $this->response['headers']['Cache-Control'] ?? null);
        self::assertSame('application/json', $this->response['headers']['Content-Type'] ?? null);
        self::assertSame([['https://shop.test', null]], $origins);
    }

    /**
     * An empty queue answers an empty list
     *
     * @return void
     */
    public function testEmptyQueueAnswersAnEmptyList(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->method('pending')->willReturn([]);
        $origins = [];

        $this->render($this->drain($queue, $this->origin(true, $origins), null, 'https://shop.test/cart'));

        self::assertSame('{"events":[]}', $this->response['body']);
        self::assertSame([[null, 'https://shop.test/cart']], $origins);
    }

    /**
     * A request from elsewhere is refused with 403 and the queue is left as it is
     *
     * @return void
     */
    public function testForeignRequestIsRefused(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::never())->method('pending');
        $origins = [];

        $this->render($this->drain($queue, $this->origin(false, $origins), 'https://evil.test', null));

        self::assertSame(403, $this->response['code']);
        self::assertSame('{"events":[]}', $this->response['body']);
        self::assertSame('no-store', $this->response['headers']['Cache-Control'] ?? null);
    }

    /**
     * A queue that cannot be read is logged and answered with 503, so the browser asks again later
     *
     * @return void
     */
    public function testFailureAnswersUnavailable(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->method('pending')->willThrowException(new \RuntimeException('session storage down'));
        $logger = new RecordingLogger();
        $origins = [];

        $sessionPresence = $this->createMock(SessionPresenceInterface::class);
        $sessionPresence->method('exists')->willReturn(true);
        $this->render(new Drain(
            $this->request('https://shop.test', null),
            $this->origin(true, $origins),
            $queue,
            $this->jsonFactory(),
            $logger,
            $sessionPresence,
            $this->signal
        ));

        self::assertSame(503, $this->response['code']);
        self::assertSame('{"events":[]}', $this->response['body']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    /**
     * The endpoint takes no form key: it checks the request's origin instead
     *
     * @return void
     */
    public function testNoFormKeyIsAsked(): void
    {
        $origins = [];
        $drain = $this->drain(
            $this->createMock(ServerEventDrainInterface::class),
            $this->origin(true, $origins),
            null,
            null
        );
        $request = $this->request(null, null);

        self::assertTrue($drain->validateForCsrf($request));
        self::assertNull($drain->createCsrfValidationException($request));
    }

    /**
     * Run the controller and render its result into the recorded response
     *
     * @param Drain $drain
     * @return void
     */
    private function render(Drain $drain): void
    {
        $response = $this->createMock(HttpInterface::class);
        $response->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($response) {
            $this->response['code'] = $code;

            return $response;
        });
        $response->method('setHeader')->willReturnCallback(
            function (string $name, string $value) use ($response) {
                $this->response['headers'][$name] = $value;

                return $response;
            }
        );
        $response->method('setBody')->willReturnCallback(function (string $body) use ($response) {
            $this->response['body'] = $body;

            return $response;
        });

        $drain->execute()->renderResult($response);
    }

    /**
     * Build the controller over a queue, an origin check and a request with the given headers
     *
     * @param ServerEventDrainInterface $queue
     * @param StoreOriginInterface $origin
     * @param string|null $originHeader
     * @param string|null $refererHeader
     * @return Drain
     */
    private function drain(
        ServerEventDrainInterface $queue,
        StoreOriginInterface $origin,
        ?string $originHeader,
        ?string $refererHeader
    ): Drain {
        $sessionPresence = $this->createMock(SessionPresenceInterface::class);
        $sessionPresence->method('exists')->willReturnCallback(fn (): bool => $this->hasSession);

        return new Drain(
            $this->request($originHeader, $refererHeader),
            $origin,
            $queue,
            $this->jsonFactory(),
            new RecordingLogger(),
            $sessionPresence,
            $this->signal
        );
    }

    /**
     * Get an origin check with a fixed answer that records what it was asked
     *
     * @param bool $sameOrigin
     * @param list<array{string|null, string|null}> $asked
     * @return StoreOriginInterface
     */
    private function origin(bool $sameOrigin, array &$asked): StoreOriginInterface
    {
        $origin = $this->createMock(StoreOriginInterface::class);
        $origin->method('isStoreOrigin')->willReturnCallback(
            static function (?string $originHeader, ?string $refererHeader) use ($sameOrigin, &$asked): bool {
                $asked[] = [$originHeader, $refererHeader];

                return $sameOrigin;
            }
        );

        return $origin;
    }

    /**
     * Get a request carrying the given headers
     *
     * @param string|null $origin
     * @param string|null $referer
     * @return Http
     */
    private function request(?string $origin, ?string $referer): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string|false => match ($name) {
                'Origin' => $origin ?? false,
                'Referer' => $referer ?? false,
                default => false,
            }
        );

        return $request;
    }

    /**
     * Get a factory of real JSON results
     *
     * @return JsonFactory
     */
    private function jsonFactory(): JsonFactory
    {
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): Json => new Json($this->createMock(InlineInterface::class), new JsonSerializer())
        );

        return $factory;
    }
}
