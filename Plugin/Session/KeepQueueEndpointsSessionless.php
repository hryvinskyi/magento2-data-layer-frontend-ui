<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Session;

use Hryvinskyi\DataLayerFrontendUi\Api\Session\SessionPresenceInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps a request to the endpoints of the visitor's event queue from starting a session for a visitor without one
 *
 * Every storefront action reads the customer session on its way in, and reading it starts a session, so without this
 * each page that asks for waiting events, or acknowledges them, would hand a cache-only visitor a new session. Such a
 * visitor has no queue: the endpoints answer that nothing waits. A visitor with a session is left alone. The endpoints
 * are the route paths listed in `routes`; a path matches with or without a store code before it and a trailing slash
 * after it.
 */
class KeepQueueEndpointsSessionless
{
    /**
     * @var list<string>
     */
    private readonly array $routes;

    /**
     * @param RequestInterface $request
     * @param SessionPresenceInterface $sessionPresence
     * @param array<mixed> $routes Route paths (`frontName/controller/action`) that never start a session
     * @throws \InvalidArgumentException When a listed route is not a non-empty path
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly SessionPresenceInterface $sessionPresence,
        array $routes = []
    ) {
        $accepted = [];
        foreach ($routes as $name => $route) {
            if (!is_string($route) || trim($route, '/') === '') {
                throw new \InvalidArgumentException(sprintf('The sessionless route "%s" is not a path.', $name));
            }
            $accepted[] = trim($route, '/');
        }
        $this->routes = $accepted;
    }

    /**
     * Refuse to start a session for a listed endpoint when the visitor has none
     *
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result || $this->routes === [] || !$this->request instanceof Request
            || $this->sessionPresence->exists()
        ) {
            return $result;
        }

        $routes = implode('|', array_map(static fn (string $route): string => preg_quote($route, '~'), $this->routes));

        return preg_match('~(?:^|/)(?:' . $routes . ')(?:/|$)~D', (string) $this->request->getPathInfo()) !== 1;
    }
}
