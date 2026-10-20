<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Controller\Events;

use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingSignal;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\StoreOriginInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayerFrontendUi\Controller\Events\Acknowledge;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Translate\InlineInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The acknowledge endpoint removes the ids a page of the store posts, never cached; it refuses another site's request
 * and a body that is not a list of ids without touching the queue, and a visitor without a session is told that
 * nothing waits without the queue (and so the session) ever being read
 */
class AcknowledgeTest extends TestCase
{
    /**
     * @var array{code: int|null, headers: array<string, string>, body: string}
     */
    private array $response = ['code' => null, 'headers' => [], 'body' => ''];

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
     * The ids posted from the store's own page are removed from the queue, and the answer counts them
     *
     * @return void
     */
    public function testPostedIdsAreRemoved(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::once())->method('acknowledge')->with(['0a1b2c3d4e5f6071', '8f9e0d1c2b3a4958']);
        $queue->expects(self::never())->method('pending');

        $this->render($this->acknowledge($queue, true, true, '{"ids":["0a1b2c3d4e5f6071","8f9e0d1c2b3a4958"]}'));

        self::assertSame(200, $this->response['code']);
        self::assertSame('{"acknowledged":2}', $this->response['body']);
        self::assertSame('no-store', $this->response['headers']['Cache-Control'] ?? null);
    }

    /**
     * Entries that are not ids are skipped, and at most a hundred ids are read
     *
     * @return void
     */
    public function testOnlyIdsAreRead(): void
    {
        $ids = array_map(static fn (int $i): string => sprintf('%016x', $i), range(1, 120));
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::once())->method('acknowledge')->with(array_slice($ids, 1, 99));
        $posted = [3, ...array_slice($ids, 1, 99), str_repeat('a', 65), ...array_slice($ids, 100)];
        $body = json_encode(['ids' => $posted]);
        self::assertIsString($body);

        $this->render($this->acknowledge($queue, true, true, $body));

        self::assertSame('{"acknowledged":99}', $this->response['body']);
    }

    /**
     * Bodies that are not an object with a list of ids
     *
     * @return array<string, array{string}>
     */
    public static function malformedBodyProvider(): array
    {
        return [
            'empty' => [''],
            'not JSON' => ['ids=a,b'],
            'a list' => ['["a","b"]'],
            'no ids' => ['{"events":["a"]}'],
            'ids not a list' => ['{"ids":{"a":"b"}}'],
            'ids a string' => ['{"ids":"a"}'],
        ];
    }

    /**
     * A body that is not a list of ids is refused with 400 and the queue is left as it is
     *
     * @param string $body
     * @return void
     * @dataProvider malformedBodyProvider
     */
    #[DataProvider('malformedBodyProvider')]
    public function testMalformedBodyIsRefused(string $body): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::never())->method('acknowledge');

        $this->render($this->acknowledge($queue, true, true, $body));

        self::assertSame(400, $this->response['code']);
        self::assertSame('{"acknowledged":0}', $this->response['body']);
    }

    /**
     * A request from elsewhere is refused with 403 and the queue is left as it is
     *
     * @return void
     */
    public function testForeignRequestIsRefused(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::never())->method('acknowledge');

        $this->render($this->acknowledge($queue, false, true, '{"ids":["0a1b2c3d4e5f6071"]}'));

        self::assertSame(403, $this->response['code']);
        self::assertSame('no-store', $this->response['headers']['Cache-Control'] ?? null);
    }

    /**
     * Without the session cookie nothing is acknowledged, the browser is told nothing waits, and the queue is never
     * touched
     *
     * @return void
     */
    public function testVisitorWithoutASessionTouchesNothing(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->expects(self::never())->method('acknowledge');
        $queue->expects(self::never())->method('hasEvents');

        $this->render($this->acknowledge($queue, true, false, '{"ids":["0a1b2c3d4e5f6071"]}'));

        self::assertSame(200, $this->response['code']);
        self::assertSame('{"acknowledged":0}', $this->response['body']);
        self::assertSame(['clear'], $this->signal->calls);
    }

    /**
     * A queue that cannot be written is logged and answered with 503, so the events come back on the next read
     *
     * @return void
     */
    public function testFailureAnswersUnavailable(): void
    {
        $queue = $this->createMock(ServerEventDrainInterface::class);
        $queue->method('acknowledge')->willThrowException(new \RuntimeException('session storage down'));
        $logger = new RecordingLogger();

        $this->render($this->acknowledge($queue, true, true, '{"ids":["0a1b2c3d4e5f6071"]}', $logger));

        self::assertSame(503, $this->response['code']);
        self::assertSame('{"acknowledged":0}', $this->response['body']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    /**
     * The endpoint takes no form key: it checks the request's origin instead
     *
     * @return void
     */
    public function testNoFormKeyIsAsked(): void
    {
        $acknowledge = $this->acknowledge($this->createMock(ServerEventDrainInterface::class), true, true, '');
        $request = $this->createMock(Http::class);

        self::assertTrue($acknowledge->validateForCsrf($request));
        self::assertNull($acknowledge->createCsrfValidationException($request));
    }

    /**
     * Run the controller and render its result into the recorded response
     *
     * @param Acknowledge $acknowledge
     * @return void
     */
    private function render(Acknowledge $acknowledge): void
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

        $acknowledge->execute()->renderResult($response);
    }

    /**
     * Build the controller over a queue and a request with the given body
     *
     * @param ServerEventDrainInterface $queue
     * @param bool $sameOrigin Whether the request comes from the store's own pages
     * @param bool $hasSession Whether the request carries the session cookie
     * @param string $body
     * @param RecordingLogger|null $logger
     * @return Acknowledge
     */
    private function acknowledge(
        ServerEventDrainInterface $queue,
        bool $sameOrigin,
        bool $hasSession,
        string $body,
        ?RecordingLogger $logger = null
    ): Acknowledge {
        $origin = $this->createMock(StoreOriginInterface::class);
        $origin->method('isStoreOrigin')->with('https://shop.test', null)->willReturn($sameOrigin);
        $presence = $this->createMock(SessionPresenceInterface::class);
        $presence->method('exists')->willReturn($hasSession);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string|false => $name === 'Origin' ? 'https://shop.test' : false
        );
        $request->method('getContent')->willReturn($body);
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): Json => new Json($this->createMock(InlineInterface::class), new JsonSerializer())
        );

        return new Acknowledge(
            $request,
            $origin,
            $queue,
            $factory,
            new JsonSerializer(),
            $logger ?? new RecordingLogger(),
            $presence,
            $this->signal
        );
    }
}
