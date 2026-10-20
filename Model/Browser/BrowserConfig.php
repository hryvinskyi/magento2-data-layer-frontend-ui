<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Api\Browser\BrowserConfigInterface;
use Hryvinskyi\DataLayerApi\Api\BrowserConfigProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Composes the browser settings from the `providers` pool: `{<provider key>: <its part>}`
 *
 * The pool is checked when it is built: every entry must be a provider, and its key must be a lower-case letter
 * followed by letters and digits and unique in the pool; anything else fails loudly, because two providers writing
 * the same key would silently replace one another in the page. A provider whose part is empty is left out, so the
 * browser can tell "nothing to say" from an empty setting. A provider that fails is logged and left out: the page and
 * the other parts still render.
 */
class BrowserConfig implements BrowserConfigInterface
{
    private const KEY = '/^[a-z][a-zA-Z0-9]*$/D';

    /**
     * @var list<BrowserConfigProviderInterface>
     */
    private readonly array $providers;

    /**
     * @param LoggerInterface $logger
     * @param array<string|int,mixed> $providers
     * @throws \InvalidArgumentException When an entry is not a provider, or a key is malformed or taken twice
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        array $providers = []
    ) {
        $valid = [];
        $keys = [];
        foreach ($providers as $name => $provider) {
            if (!$provider instanceof BrowserConfigProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The browser settings provider "%s" must implement %s.',
                    $name,
                    BrowserConfigProviderInterface::class
                ));
            }
            $key = $provider->getKey();
            if (preg_match(self::KEY, $key) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The browser settings provider "%s" has the key "%s"; a key is a lower-case letter followed by '
                    . 'letters and digits.',
                    $name,
                    $key
                ));
            }
            if (isset($keys[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'The browser settings providers "%s" and "%s" both use the key "%s".',
                    $keys[$key],
                    $name,
                    $key
                ));
            }
            $keys[$key] = (string) $name;
            $valid[] = $provider;
        }
        $this->providers = $valid;
    }

    /**
     * @inheritDoc
     */
    public function forStore(int $storeId): array
    {
        $config = [];
        foreach ($this->providers as $provider) {
            try {
                $part = $provider->provide($storeId);
            } catch (\Throwable $exception) {
                $this->logger->error('A part of the data layer browser settings could not be built.', [
                    'provider' => $provider->getKey(),
                    'store_id' => $storeId,
                    'exception' => $exception::class,
                    'reason' => $exception->getMessage(),
                ]);
                continue;
            }
            if ($part !== []) {
                $config[$provider->getKey()] = $part;
            }
        }

        return $config;
    }
}
