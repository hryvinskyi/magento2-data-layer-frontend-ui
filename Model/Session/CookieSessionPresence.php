<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Session;

use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;

/**
 * Knows a visitor's session by its cookie in the request
 *
 * Only the cookie can tell without opening anything: building a session object opens the session, and opening it
 * for a visitor who has none would create one.
 */
class CookieSessionPresence implements SessionPresenceInterface
{
    /**
     * @param CookieReaderInterface $cookies
     * @param SessionConfigInterface $sessionConfig Names the session cookie
     */
    public function __construct(
        private readonly CookieReaderInterface $cookies,
        private readonly SessionConfigInterface $sessionConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exists(): bool
    {
        $id = $this->cookies->getCookie((string) $this->sessionConfig->getName());

        return is_string($id) && $id !== '';
    }
}
