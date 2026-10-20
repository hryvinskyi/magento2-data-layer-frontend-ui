<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Model\Csp;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicyFactory;

/**
 * Allows the store view's own Tag Manager host where the loader and its fallback frame need it
 *
 * Google's host is allowed for every store view by the module's whitelist, so this collector adds a policy only when
 * the data layer is on and the store view loads Tag Manager from another host. The policies it adds are merged with
 * the ones collected before it.
 */
class GtmHostPolicyCollector implements PolicyCollectorInterface
{
    /**
     * The directives Tag Manager's loader, its requests and its fallback frame fall under
     */
    private const DIRECTIVES = ['script-src', 'img-src', 'connect-src', 'frame-src'];

    /**
     * @param ConfigInterface $config
     * @param FetchPolicyFactory $policies
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly FetchPolicyFactory $policies
    ) {
    }

    /**
     * @inheritDoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        if (!$this->config->isEnabled()) {
            return $defaultPolicies;
        }
        $host = $this->config->getScriptHost();
        if ($host === ConfigInterface::DEFAULT_SCRIPT_HOST) {
            return $defaultPolicies;
        }

        $collected = $defaultPolicies;
        foreach (self::DIRECTIVES as $directive) {
            $collected[] = $this->policies->create([
                'id' => $directive,
                'noneAllowed' => false,
                'hostSources' => [$host],
            ]);
        }

        return $collected;
    }
}
