<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Observer;

use Hryvinskyi\DataLayer\Api\ServerEventDrainInterface;
use Hryvinskyi\DataLayer\Api\ServerEventSignalInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Response\PageCacheabilityInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Signal\PendingSignalInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Right before a response is sent, tells the browser about waiting server events when the response can carry it
 *
 * A response the full-page cache may store carries nothing: what the request asked for is dropped, and the events
 * wait. Any other response (a redirect, a POST, an uncacheable page, a REST answer) carries what the request asked
 * for; after a storefront action of a visitor with a session (an action reads the session on its way in, so it is
 * already open) the queue is looked into as well, so events that waited through cached pages are signalled by the next
 * response that can. The queue is never looked into for a visitor without a session (it would create one), for a
 * response no action produced (a page served from the cache), nor on the routes listed in `ignoredRoutes`, the
 * endpoints that set the queue's signal themselves. A failure is logged and never breaks the response.
 */
class SendServerEventSignal implements ObserverInterface
{
    /**
     * @param RequestInterface $request
     * @param PageCacheabilityInterface $cacheability
     * @param PendingSignalInterface $pendingSignal
     * @param ServerEventSignalInterface $signal
     * @param SessionPresenceInterface $sessionPresence
     * @param ServerEventDrainInterface $queue
     * @param LoggerInterface $logger
     * @param array<string,bool> $ignoredRoutes Route names whose actions set the queue's signal themselves
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageCacheabilityInterface $cacheability,
        private readonly PendingSignalInterface $pendingSignal,
        private readonly ServerEventSignalInterface $signal,
        private readonly SessionPresenceInterface $sessionPresence,
        private readonly ServerEventDrainInterface $queue,
        private readonly LoggerInterface $logger,
        private readonly array $ignoredRoutes = []
    ) {
    }

    /**
     * Write the signal on a response that can carry it, or drop it
     *
     * @param Observer $observer Carries the `response` about to be sent
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $response = $observer->getEvent()->getData('response');
        if (!$response instanceof HttpInterface || $this->cacheability->isCacheable($response)) {
            $this->pendingSignal->discard();

            return;
        }

        $this->signalWaitingEvents();
        $this->pendingSignal->send();
    }

    /**
     * Ask for the signal when an action ran and the visitor's session holds waiting events
     *
     * @return void
     */
    private function signalWaitingEvents(): void
    {
        if (!$this->request instanceof Http
            || !$this->request->isDispatched()
            || ($this->ignoredRoutes[(string) $this->request->getRouteName()] ?? false)
            || !$this->sessionPresence->exists()
        ) {
            return;
        }

        try {
            if ($this->queue->hasEvents()) {
                $this->signal->signal();
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Waiting data layer events could not be signalled to the browser.', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
