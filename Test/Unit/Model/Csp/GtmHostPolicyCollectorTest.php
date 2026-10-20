<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Csp;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Model\Csp\GtmHostPolicyCollector;
use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Csp\Model\Policy\FetchPolicyFactory;
use PHPUnit\Framework\TestCase;

/**
 * A store view's own Tag Manager host is allowed in the four directives the loader needs, and only when it differs
 * from Google's
 */
class GtmHostPolicyCollectorTest extends TestCase
{
    /**
     * Google's host is already allowed by the module's whitelist, so the policies come back unchanged
     *
     * @return void
     */
    public function testDefaultHostLeavesPoliciesUnchanged(): void
    {
        $existing = [new FetchPolicy('script-src', false, ['cdn.example.com'])];

        self::assertSame(
            $existing,
            $this->collector(true, ConfigInterface::DEFAULT_SCRIPT_HOST)->collect($existing)
        );
    }

    /**
     * A module that is off for the store view adds nothing, whatever host is stored
     *
     * @return void
     */
    public function testDisabledLeavesPoliciesUnchanged(): void
    {
        $existing = [new FetchPolicy('img-src', false, ['cdn.example.com'])];

        self::assertSame($existing, $this->collector(false, 'metrics.example.com')->collect($existing));
        self::assertSame([], $this->collector(false, 'metrics.example.com')->collect());
    }

    /**
     * A custom host is added to the script, image, connect and frame directives, after the policies already collected
     *
     * @return void
     */
    public function testCustomHostJoinsTheFourDirectives(): void
    {
        $existing = new FetchPolicy('script-src', false, ['cdn.example.com']);

        $collected = $this->collector(true, 'metrics.example.com')->collect([$existing]);

        self::assertSame($existing, $collected[0]);
        self::assertSame(
            ['script-src', 'img-src', 'connect-src', 'frame-src'],
            array_map(static fn (PolicyInterface $policy): string => $policy->getId(), array_slice($collected, 1))
        );
        foreach (array_slice($collected, 1) as $policy) {
            self::assertInstanceOf(FetchPolicy::class, $policy);
            self::assertSame(['metrics.example.com'], $policy->getHostSources());
            self::assertFalse($policy->isNoneAllowed());
        }
    }

    /**
     * Get a collector over fixed settings and a factory that builds fetch policies from its data
     *
     * @param bool $enabled
     * @param string $host
     * @return GtmHostPolicyCollector
     */
    private function collector(bool $enabled, string $host): GtmHostPolicyCollector
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getScriptHost')->willReturn($host);

        $policies = $this->createMock(FetchPolicyFactory::class);
        $policies->method('create')->willReturnCallback(
            static fn (array $data): FetchPolicy => new FetchPolicy(
                is_string($data['id']) ? $data['id'] : '',
                $data['noneAllowed'] === true,
                is_array($data['hostSources']) ? array_values(array_filter($data['hostSources'], 'is_string')) : []
            )
        );

        return new GtmHostPolicyCollector($config, $policies);
    }
}
