<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Gtag;

use Hryvinskyi\DataLayer\Model\Config;
use Hryvinskyi\DataLayer\Model\Config\DraftSettings;
use Hryvinskyi\DataLayer\Test\Unit\Model\FakeScopeConfig;
use Hryvinskyi\DataLayerFrontendUi\Model\Browser\GtagConfigProvider;
use Hryvinskyi\DataLayerFrontendUi\Model\Gtag\GtagDestinations;
use Hryvinskyi\DataLayerFrontendUi\Model\Gtag\GtagLoadedCondition;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * gtag.js is configured for the measurement id, and for Google Ads only where gtag.js is the page's way of reporting
 * conversions; its destination plugin and its browser settings follow
 */
class GtagDestinationsTest extends TestCase
{
    private const ON = 'hryvinskyi_datalayer/general/enabled';
    private const CONTAINER = 'hryvinskyi_datalayer/gtm/container_id';
    private const MEASUREMENT = 'hryvinskyi_datalayer/gtag/measurement_id';
    private const ADS_ON = 'hryvinskyi_datalayer/ads/enabled';
    private const ADS_ID = 'hryvinskyi_datalayer/ads/conversion_id';
    private const ADS_LABEL = 'hryvinskyi_datalayer/ads/conversion_label';

    private const ADS = [self::ADS_ON => '1', self::ADS_ID => 'AW-123', self::ADS_LABEL => 'AbC_1-x'];

    /**
     * The destinations for each combination of settings
     *
     * @param array<string, string> $settings
     * @param list<string> $expected
     * @return void
     * @dataProvider settingsProvider
     */
    #[DataProvider('settingsProvider')]
    public function testDestinations(array $settings, array $expected): void
    {
        $destinations = $this->destinations($settings);

        self::assertSame($expected, $destinations->forStore(4));
        self::assertSame($expected !== [], (new GtagLoadedCondition($destinations))->isMet(4));
    }

    /**
     * Get settings and the destinations they give
     *
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function settingsProvider(): array
    {
        $on = [self::ON => '1'];

        return [
            'data layer off' => [[self::MEASUREMENT => 'G-ABC123'] + self::ADS, []],
            'nothing set' => [$on, []],
            'Tag Manager only' => [$on + [self::CONTAINER => 'GTM-AB12'], []],
            'measurement id' => [$on + [self::MEASUREMENT => 'G-ABC123'], ['G-ABC123']],
            'malformed measurement id' => [$on + [self::MEASUREMENT => 'UA-1-1'], []],
            'measurement id and Google Ads' => [$on + [self::MEASUREMENT => 'G-ABC123'] + self::ADS, [
                'G-ABC123',
                'AW-123',
            ]],
            'Google Ads without a container' => [$on + self::ADS, ['AW-123']],
            'Google Ads with a container reports through the container' => [
                $on + [self::CONTAINER => 'GTM-AB12'] + self::ADS,
                [],
            ],
            'both loaders with Google Ads' => [
                $on + [self::CONTAINER => 'GTM-AB12', self::MEASUREMENT => 'G-ABC123'] + self::ADS,
                ['G-ABC123', 'AW-123'],
            ],
            'Google Ads switched off' => [$on + [self::ADS_ON => '0'] + self::ADS, []],
            'Google Ads without a label' => [$on + [self::ADS_LABEL => ''] + self::ADS, []],
            'Google Ads with a bare number' => [$on + [self::ADS_ID => '123'] + self::ADS, []],
        ];
    }

    /**
     * The plugin's settings carry the measurement id only where gtag.js is configured with it
     *
     * @return void
     */
    public function testBrowserSettingsCarryTheMeasurementIdWhereGtagUsesIt(): void
    {
        $on = [self::ON => '1'];
        $withId = $this->provider($on + [self::MEASUREMENT => 'G-ABC123'] + self::ADS);
        self::assertSame('gtag', $withId->getKey());
        self::assertSame(['measurementId' => 'G-ABC123'], $withId->provide(4));

        self::assertSame([], $this->provider($on + self::ADS)->provide(4));
        self::assertSame([], $this->provider([self::MEASUREMENT => 'G-ABC123'])->provide(4));
    }

    /**
     * Get the destinations over the real settings reader
     *
     * @param array<string, string> $settings
     * @return GtagDestinations
     */
    private function destinations(array $settings): GtagDestinations
    {
        return new GtagDestinations(new Config(new FakeScopeConfig($settings), new Json(), new DraftSettings()));
    }

    /**
     * Get the browser settings provider over the real settings reader
     *
     * @param array<string, string> $settings
     * @return GtagConfigProvider
     */
    private function provider(array $settings): GtagConfigProvider
    {
        $config = new Config(new FakeScopeConfig($settings), new Json(), new DraftSettings());

        return new GtagConfigProvider($config, new GtagDestinations($config));
    }
}
