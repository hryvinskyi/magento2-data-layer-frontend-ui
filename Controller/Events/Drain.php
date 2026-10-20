<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Controller\Events;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\StoreOriginInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayer\Api\ServerEventSignalInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Psr\Log\LoggerInterface;

/**
 * Hands the events waiting in the visitor's queue to the browser: `{"events": [{"id": …, "event": {…}}]}`
 *
 * Each `event` is the object the browser pushes to the data layer, and `id` names it in the queue.
 *
 * The answer leaves the queue as it is: the browser pushes the events, then acknowledges their ids at the acknowledge
 * endpoint, and only that removes them. An answer the page never used (it was left while the request ran) loses
 * nothing; the next request returns the same events, and the browser skips the ids it already pushed. A queue found
 * empty tells the browser that nothing waits. The answer is never cached. The browser module posts here without the
 * store's form key; the request is accepted only from the store's own pages instead (its `Origin`, or else its
 * `Referer`). Any other request is refused with 403, and a failure is answered with 503; neither changes the queue.
 *
 * The queue lives in the visitor's session. A visitor without a session cookie has no queue: the answer is an empty
 * list and the browser is told that nothing waits, without the session ever being opened, so the endpoint never
 * creates a session (the session start is refused for such a request before the action runs).
 */
class Drain implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * The endpoint's route path
     */
    public const ROUTE = 'hryvinskyi_datalayer/events/drain';

    /**
     * @param RequestInterface $request
     * @param StoreOriginInterface $storeOrigin
     * @param ServerEventDrainInterface $queue
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger
     * @param SessionPresenceInterface $sessionPresence
     * @param ServerEventSignalInterface $signal
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreOriginInterface $storeOrigin,
        private readonly ServerEventDrainInterface $queue,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger,
        private readonly SessionPresenceInterface $sessionPresence,
        private readonly ServerEventSignalInterface $signal
    ) {
    }

    /**
     * Answer with the waiting events and their ids, for a request from the store's own pages
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        if (!$this->storeOrigin->isStoreOrigin($this->header('Origin'), $this->header('Referer'))) {
            $result->setHttpResponseCode(403);
            $result->setData(['events' => []]);

            return $result;
        }
        if (!$this->sessionPresence->exists()) {
            $this->signal->clear();
            $result->setHttpResponseCode(200);
            $result->setData(['events' => []]);

            return $result;
        }

        try {
            $result->setHttpResponseCode(200);
            $result->setData(['events' => $this->queue->pending()]);
        } catch (\Throwable $exception) {
            $this->logger->error('The waiting data layer events could not be handed to the browser.', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
            $result->setHttpResponseCode(503);
            $result->setData(['events' => []]);
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
