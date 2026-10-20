<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Browser;

use Hryvinskyi\DataLayerFrontendUi\Model\Browser\BrowserConfig;
use Hryvinskyi\DataLayer\Test\Unit\Model\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * The browser settings are every provider's part under its key; a malformed pool fails when it is built, a failing
 * provider is logged and left out, and an empty part is omitted
 */
class BrowserConfigTest extends TestCase
{
    /**
     * Each part is printed under its provider's key, in pool order, for the store view asked for
     *
     * @return void
     */
    public function testPartsAreKeyedByProvider(): void
    {
        $core = new FakeProvider('core', ['impressionTrigger' => 'load']);
        $server = new FakeProvider('server', ['collectUrl' => '/collect', 'channels' => ['view_item' => 'both']]);
        $config = new BrowserConfig(new RecordingLogger(), ['core' => $core, 'server' => $server]);

        self::assertSame(
            [
                'core' => ['impressionTrigger' => 'load'],
                'server' => ['collectUrl' => '/collect', 'channels' => ['view_item' => 'both']],
            ],
            $config->forStore(4)
        );
        self::assertSame([4], $core->askedFor);
        self::assertSame([4], $server->askedFor);
    }

    /**
     * A provider with nothing to say leaves no key at all
     *
     * @return void
     */
    public function testEmptyPartIsLeftOut(): void
    {
        $config = new BrowserConfig(new RecordingLogger(), [
            'core' => new FakeProvider('core', ['impressionTrigger' => 'visible']),
            'server' => new FakeProvider('server'),
        ]);

        self::assertSame(['core' => ['impressionTrigger' => 'visible']], $config->forStore(1));
        self::assertSame([], (new BrowserConfig(new RecordingLogger()))->forStore(1));
    }

    /**
     * A provider that throws is logged with its key and left out; the others still contribute
     *
     * @return void
     */
    public function testFailingProviderIsLoggedAndLeftOut(): void
    {
        $logger = new RecordingLogger();
        $config = new BrowserConfig($logger, [
            'broken' => new FakeProvider('broken', [], new \RuntimeException('no store')),
            'core' => new FakeProvider('core', ['impressionTrigger' => 'load']),
        ]);

        self::assertSame(['core' => ['impressionTrigger' => 'load']], $config->forStore(2));
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('broken', $logger->records[0]['context']['provider']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    /**
     * Two providers with one key fail when the pool is built, naming both
     *
     * @return void
     */
    public function testDuplicateKeyFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"core" and "other_core" both use the key "core"');

        new BrowserConfig(new RecordingLogger(), [
            'core' => new FakeProvider('core'),
            'other_core' => new FakeProvider('core'),
        ]);
    }

    /**
     * A key that is not a lower-case letter followed by letters and digits fails
     *
     * @param string $key
     * @return void
     * @dataProvider badKeyProvider
     */
    public function testMalformedKeyFails(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has the key');

        new BrowserConfig(new RecordingLogger(), ['bad' => new FakeProvider($key)]);
    }

    /**
     * Get keys the pool refuses
     *
     * @return array<string, array{string}>
     */
    public static function badKeyProvider(): array
    {
        return [
            'empty' => [''],
            'upper-case start' => ['Core'],
            'digit start' => ['1core'],
            'underscore' => ['server_side'],
            'dash' => ['server-side'],
            'space' => ['core '],
            'trailing newline' => ["core\n"],
        ];
    }

    /**
     * An entry that is not a provider fails, naming it
     *
     * @return void
     */
    public function testEntryThatIsNotAProviderFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"mistake" must implement');

        new BrowserConfig(new RecordingLogger(), ['mistake' => new \stdClass()]);
    }

    /**
     * Keys with capitals after the first letter are allowed
     *
     * @return void
     */
    public function testCamelCaseKeyIsAllowed(): void
    {
        $config = new BrowserConfig(new RecordingLogger(), ['b' => new FakeProvider('requestQuote2', ['on' => true])]);

        self::assertSame(['requestQuote2' => ['on' => true]], $config->forStore(1));
    }
}
