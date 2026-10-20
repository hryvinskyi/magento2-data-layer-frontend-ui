<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Controller\Events;

use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Browser\StoreOriginInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayer\Api\ServerEventSignalInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes from the visitor's queue the events the browser has pushed: a JSON body `{"ids": [the events' ids]}`
 *
 * The browser posts here once it has pushed the events the drain endpoint handed over, so an event leaves the queue
 * only after it reached the page. Ids the queue does not hold are ignored, so a repeated acknowledgement is harmless.
 * Once nothing is left, the browser is told that nothing waits. The answer, `{"acknowledged": n}` with the number of
 * ids read, is never cached.
 *
 * The request is accepted only from the store's own pages (its `Origin`, or else its `Referer`), without the store's
 * form key; any other is refused with 403. A body that is not such a list is refused with 400, at most 100 ids of at
 * most 64 characters each are read, and a failure is answered with 503; none of them changes the queue. A visitor
 * without a session cookie has no queue: the answer is that nothing was acknowledged and nothing waits, and no session
 * is started for the request.
 */
class Acknowledge implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * The endpoint's route path
     */
    public const ROUTE = 'hryvinskyi_datalayer/events/acknowledge';

    private const MAX_IDS = 100;
    private const MAX_ID_LENGTH = 64;

    /**
     * @param RequestInterface $request
     * @param StoreOriginInterface $storeOrigin
     * @param ServerEventDrainInterface $queue
     * @param JsonFactory $jsonFactory
     * @param SerializerInterface $serializer Reads the JSON body
     * @param LoggerInterface $logger
     * @param SessionPresenceInterface $sessionPresence
     * @param ServerEventSignalInterface $signal
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreOriginInterface $storeOrigin,
        private readonly ServerEventDrainInterface $queue,
        private readonly JsonFactory $jsonFactory,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly SessionPresenceInterface $sessionPresence,
        private readonly ServerEventSignalInterface $signal
    ) {
    }

    /**
     * Remove the acknowledged events, for a request from the store's own pages
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setData(['acknowledged' => 0]);
        if (!$this->storeOrigin->isStoreOrigin($this->header('Origin'), $this->header('Referer'))) {
            $result->setHttpResponseCode(403);

            return $result;
        }
        $ids = $this->ids();
        if ($ids === null) {
            $result->setHttpResponseCode(400);

            return $result;
        }
        $result->setHttpResponseCode(200);
        if (!$this->sessionPresence->exists()) {
            $this->signal->clear();

            return $result;
        }

        try {
            $this->queue->acknowledge($ids);
            $result->setData(['acknowledged' => count($ids)]);
        } catch (\Throwable $exception) {
            $this->logger->error('The data layer events the browser pushed could not be removed from its queue.', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
            $result->setHttpResponseCode(503);
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Read the ids from the request's body
     *
     * @return list<string>|null Null when the body is not an object with a list of ids
     */
    private function ids(): ?array
    {
        $content = $this->request instanceof Request ? $this->request->getContent() : '';
        try {
            $body = $content !== '' ? $this->serializer->unserialize($content) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
        $listed = is_array($body) ? $body['ids'] ?? null : null;
        if (!is_array($listed) || !array_is_list($listed)) {
            return null;
        }

        $ids = [];
        foreach (array_slice($listed, 0, self::MAX_IDS) as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= self::MAX_ID_LENGTH) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Read a request header
     *
     * @param string $name
     * @return string|null Null when the request does not carry it
     */
    private function header(string $name): ?string
    {
        $value = $this->request instanceof Request ? $this->request->getHeader($name) : false;

        return is_string($value) ? $value : null;
    }
}
