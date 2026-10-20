<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model;

use Hryvinskyi\DataLayer\Api\ServerEventSignalInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Signal\PendingSignalInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Signals waiting server events with a cookie the browser module reads: `hdl_events=1`
 *
 * A session cookie for the whole site, readable by scripts, `SameSite=Lax`, secure on a secure request. Signalling and
 * clearing during a request only record what the response should say (the last of them wins); the cookie is written
 * once the response is complete and known not to be stored by the full-page cache, because a stored response is
 * served to every visitor with the cookie in it. A response the cache stores carries nothing: the events then wait
 * for the next response that can tell. Each state is written at most once per request. A cookie that cannot be
 * written is logged and never fails the shopper's action.
 */
class ServerEventCookie implements ServerEventSignalInterface, PendingSignalInterface, ResetAfterRequestInterface
{
    public const NAME = 'hdl_events';

    /**
     * @var bool|null What this request asked for: true signalled, false cleared, null nothing
     */
    private ?bool $requested = null;

    /**
     * @var bool|null The state written in this request: true signalled, false cleared, null nothing written yet
     */
    private ?bool $written = null;

    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * Recorded, and written when the response is sent.
     */
    public function signal(): void
    {
        if ($this->canSignal()) {
            $this->requested = true;
        }
    }

    /**
     * @inheritDoc
     *
     * Recorded, and written when the response is sent.
     */
    public function clear(): void
    {
        if ($this->canSignal()) {
            $this->requested = false;
        }
    }

    /**
     * @inheritDoc
     *
     * Any HTTP request's response may: whether it actually carries the signal is decided once it is complete.
     */
    public function canSignal(): bool
    {
        return $this->request instanceof Request;
    }

    /**
     * @inheritDoc
     */
    public function send(): void
    {
        if ($this->requested === null || $this->requested === $this->written) {
            return;
        }

        try {
            if ($this->requested) {
                $this->cookieManager->setPublicCookie(self::NAME, '1', $this->metadata());
            } else {
                $this->cookieManager->deleteCookie(self::NAME, $this->metadata());
            }
            $this->written = $this->requested;
        } catch (\Throwable $exception) {
            $this->logFailure(
                $this->requested
                    ? 'The browser could not be told that data layer events wait.'
                    : 'The browser could not be told that no data layer events wait.',
                $exception
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function discard(): void
    {
        $this->requested = null;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->requested = null;
        $this->written = null;
    }

    /**
     * Build the cookie's attributes
     *
     * @return PublicCookieMetadata
     */
    private function metadata(): PublicCookieMetadata
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
        $metadata->setPath('/');
        $metadata->setHttpOnly(false);
        $metadata->setSecure($this->request instanceof Request && $this->request->isSecure());
        $metadata->setSameSite('Lax');

        return $metadata;
    }

    /**
     * Log a cookie that could not be written, with the failure's class and message
     *
     * @param string $message
     * @param \Throwable $exception
     * @return void
     */
    private function logFailure(string $message, \Throwable $exception): void
    {
        $this->logger->warning($message, [
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);
    }
}
