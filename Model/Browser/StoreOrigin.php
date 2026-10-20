<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\StoreOriginInterface;
use Magento\Framework\UrlInterface;

/**
 * Compares the host a request was sent from with the host of the current store's secure and unsecure base URLs
 *
 * The `Origin` header decides; a request without one is judged by its `Referer`. An opaque origin (`null`) names no
 * host and is refused, as is a request with neither header. A browser always sends `Origin` on a cross-site POST, so
 * a page elsewhere cannot act in a visitor's name.
 */
class StoreOrigin implements StoreOriginInterface
{
    /**
     * A scheme, `://`, optional credentials, then the host (a bracketed IPv6 address or a name), which ends at a
     * port, a path, a query, a fragment or the end of the text
     */
    private const HOST_PATTERN = '~^[a-z][a-z0-9+.\-]*://(?:[^/?#@]*@)?'
        . '(\[[0-9a-f:.]+\]|[^/?#:@\[\]\s]+)(?::\d*)?(?:[/?#]|$)~iD';

    /**
     * @param UrlInterface $url Builds the current store's base URLs
     */
    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isStoreOrigin(?string $origin, ?string $referer): bool
    {
        $source = $origin !== null && trim($origin) !== '' ? $origin : $referer;
        $host = $source !== null ? $this->host($source) : null;
        if ($host === null) {
            return false;
        }

        foreach ([false, true] as $secure) {
            $baseUrl = $this->url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_LINK, '_secure' => $secure]);
            if ($this->host($baseUrl) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the host an absolute URL or an origin names, in lower case
     *
     * @param string $url
     * @return string|null Null when the text is not an absolute URL with a host
     */
    private function host(string $url): ?string
    {
        if (preg_match(self::HOST_PATTERN, trim($url), $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }
}
