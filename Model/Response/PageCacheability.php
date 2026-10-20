<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Response;

use Hryvinskyi\DataLayerFrontendUi\Api\Response\PageCacheabilityInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Response\PublicResponseRecorderInterface;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Applies the full-page cache's own rule: it stores the response to a GET or HEAD request whose status is 200 or 404
 * and whose page was declared public (`Cache-Control: public … s-maxage=…`)
 *
 * The built-in cache replaces the public headers of a response it stores with no-cache headers before the response is
 * sent, so a declaration made during the request counts as well as the header still on the response. Any other
 * response (a redirect, a POST, a private or uncacheable page, a response with no HTTP request behind it) is never
 * stored.
 */
class PageCacheability implements PageCacheabilityInterface, PublicResponseRecorderInterface, ResetAfterRequestInterface
{
    private const CACHEABLE_METHODS = ['GET', 'HEAD'];
    private const CACHEABLE_STATUSES = [200, 404];
    private const PUBLIC_CACHE_CONTROL = '/public.*s-maxage=\d+/';

    /**
     * @var bool Whether the response was declared public in this request
     */
    private bool $declaredPublic = false;

    /**
     * @param RequestInterface $request
     */
    public function __construct(private readonly RequestInterface $request)
    {
    }

    /**
     * @inheritDoc
     */
    public function isCacheable(HttpInterface $response): bool
    {
        if (!$this->request instanceof Request
            || !in_array(strtoupper((string) $this->request->getMethod()), self::CACHEABLE_METHODS, true)
            || !in_array((int) $response->getHttpResponseCode(), self::CACHEABLE_STATUSES, true)
        ) {
            return false;
        }
        $cacheControl = $response->getHeader('Cache-Control');

        return $this->declaredPublic
            || ($cacheControl instanceof HeaderInterface
                && preg_match(self::PUBLIC_CACHE_CONTROL, $cacheControl->getFieldValue()) === 1);
    }

    /**
     * @inheritDoc
     */
    public function recordPublic(): void
    {
        $this->declaredPublic = true;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->declaredPublic = false;
    }
}
