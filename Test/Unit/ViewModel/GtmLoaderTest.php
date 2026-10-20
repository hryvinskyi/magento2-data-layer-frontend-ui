<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Hryvinskyi\DataLayer\Api\ConfigInterface;
use Hryvinskyi\DataLayerFrontendUi\Api\Gtag\GtagDestinationsInterface;
use Hryvinskyi\DataLayerFrontendUi\ViewModel\GtmLoader;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use PHPUnit\Framework\TestCase;

/**
 * The loaders go into the head and the fallback frame into the body only for a store view with a container or a gtag
 * destination, with the container, the host and the destinations written so they cannot leave their literals
 */
class GtmLoaderTest extends TestCase
{
    use TemplateRenderer;
    use HeadTags;

    /**
     * A store view without the data layer, or without a container and a gtag destination, registers no loader and
     * prints no frame
     *
     * @return void
     */
    public function testDisabledRegistersNothing(): void
    {
        foreach ([[false, 'GTM-AB12'], [true, null]] as [$enabled, $containerId]) {
            $head = $this->headTagManager();
            $loader = $this->loader($head, $enabled, $containerId);

            self::assertSame('', $this->render('gtm/loader.phtml', ['view_model' => $loader]));
            self::assertSame('', $this->render('gtm/noscript.phtml', ['view_model' => $loader]));
            self::assertSame([], $this->headLines($head));
            self::assertSame('', $loader->getNoscriptUrl());
        }
    }

    /**
     * A template without a loader view model prints and registers nothing
     *
     * @return void
     */
    public function testForeignViewModelPrintsNothing(): void
    {
        self::assertSame('', $this->render('gtm/loader.phtml', ['view_model' => new \stdClass()]));
        self::assertSame('', $this->render('gtm/noscript.phtml', []));
    }

    /**
     * An enabled store view registers one script that loads the container from the configured host; the template
     * prints nothing in place
     *
     * @return void
     */
    public function testEnabledRegistersOneLoaderForTheContainer(): void
    {
        $head = $this->headTagManager();

        $html = $this->render(
            'gtm/loader.phtml',
            ['view_model' => $this->loader($head, true, 'GTM-AB12', 'metrics.example.com')]
        );

        self::assertSame('', $html);
        $lines = $this->headLines($head);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('<script>(function(w,d,s,l,i,h){', $lines[0]);
        self::assertStringContainsString("j.src='https://'+h+'/gtm.js?id='+i+dl;", $lines[0]);
        self::assertStringEndsWith(
            '(window,document,\'script\',\'dataLayer\',"GTM-AB12","metrics.example.com");</script>',
            $lines[0]
        );
    }

    /**
     * A host that tries to close the tag stays inside its literal
     *
     * @return void
     */
    public function testHostCannotCloseTheScriptTag(): void
    {
        $head = $this->headTagManager();

        $this->loader($head, true, 'GTM-AB12', 'x.test/</script><b>')->registerHeadElements();
        $html = $head->render();

        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('x.test/</script>', $html);
        self::assertStringContainsString(chr(92) . 'u003C', $html);
    }

    /**
     * The configured attributes are on every loader tag
     *
     * @return void
     */
    public function testConfiguredAttributesAreOnEveryTag(): void
    {
        $head = $this->headTagManager();
        $attributes = ['data-consent' => 'necessary', 'type' => 'text/plain'];

        $this->loader($head, true, 'GTM-AB12', ConfigInterface::DEFAULT_SCRIPT_HOST, $attributes, ['G-ABC123'])
            ->registerHeadElements();

        $lines = $this->headLines($head);
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertStringStartsWith('<script data-consent="necessary" type="text/plain"', $line);
        }
    }

    /**
     * With gtag destinations and no container, gtag.js is loaded from Google's host for the first destination and
     * configured for each; no container is loaded and no fallback frame printed
     *
     * @return void
     */
    public function testGtagModeLoadsGtagJsAndConfiguresEveryDestination(): void
    {
        $head = $this->headTagManager();
        $loader = $this->loader($head, true, null, 'metrics.example.com', ['data-consent' => 'statistics'], [
            'G-ABC123',
            'AW-987',
        ]);

        $loader->registerHeadElements();

        self::assertSame('', $loader->getNoscriptUrl());
        self::assertSame('', $this->render('gtm/noscript.phtml', ['view_model' => $loader]));
        self::assertSame(
            [
                '<script data-consent="statistics" async="async" '
                . 'src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>',
                '<script data-consent="statistics">window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());'
                . 'gtag(\'config\',"G-ABC123");gtag(\'config\',"AW-987");</script>',
            ],
            $this->headLines($head)
        );
    }

    /**
     * With a container and gtag destinations both loaders are registered, the container first, with its fallback frame
     *
     * @return void
     */
    public function testBothModesRegisterBothLoaders(): void
    {
        $head = $this->headTagManager();
        $loader = $this->loader($head, true, 'GTM-AB12', ConfigInterface::DEFAULT_SCRIPT_HOST, [], ['G-ABC123']);

        $loader->registerHeadElements();
        $html = $head->render();

        self::assertSame(3, substr_count($html, '</script>'));
        self::assertLessThan(strpos($html, 'gtag/js?id=G-ABC123'), strpos($html, '"GTM-AB12"'));
        self::assertStringContainsString("gtag('config',\"G-ABC123\");", $html);
        self::assertNotSame('', $this->render('gtm/noscript.phtml', ['view_model' => $loader]));
    }

    /**
     * Registering again, as a second render of the block does, replaces the loaders instead of adding copies
     *
     * @return void
     */
    public function testRegisteringAgainKeepsOneCopyOfEachLoader(): void
    {
        $head = $this->headTagManager();
        $loader = $this->loader($head, true, 'GTM-AB12', ConfigInterface::DEFAULT_SCRIPT_HOST, [], ['G-ABC123']);

        $loader->registerHeadElements();
        $once = $this->headLines($head);
        $loader->registerHeadElements();

        self::assertCount(3, $once);
        self::assertSame($once, $this->headLines($head));
    }

    /**
     * A destination that tries to close the tag stays inside its literal and is encoded in the address
     *
     * @return void
     */
    public function testGtagDestinationCannotCloseTheScriptTag(): void
    {
        $head = $this->headTagManager();

        $this->loader($head, true, null, ConfigInterface::DEFAULT_SCRIPT_HOST, [], ['G-1</script><b>'])
            ->registerHeadElements();
        $html = $head->render();

        self::assertSame(2, substr_count($html, '</script>'));
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('G-1%3C%2Fscript%3E%3Cb%3E', $html);
    }

    /**
     * The fallback frame points at the host's `ns.html` for the container, escaped for the attribute
     *
     * @return void
     */
    public function testNoscriptFrameUrlIsEscaped(): void
    {
        $plain = $this->loader($this->headTagManager(), true, 'GTM-AB12', 'metrics.example.com');
        self::assertSame('https://metrics.example.com/ns.html?id=GTM-AB12', $plain->getNoscriptUrl());
        $html = $this->render('gtm/noscript.phtml', ['view_model' => $plain]);
        self::assertSame(
            '<noscript> <iframe src="https://metrics.example.com/ns.html?id=GTM-AB12" height="0" width="0" '
            . 'style="display:none;visibility:hidden"></iframe> </noscript>',
            preg_replace('/\s+/', ' ', $html)
        );

        $hostile = $this->loader($this->headTagManager(), true, 'GTM-AB12', 'x.test/"><script>alert(1)</script>');
        $html = $this->render('gtm/noscript.phtml', ['view_model' => $hostile]);
        self::assertStringNotContainsString('"><script>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * Get a loader over fixed settings
     *
     * @param HeadTagManager $head The head tag manager it registers with
     * @param bool $enabled
     * @param string|null $containerId
     * @param string $host
     * @param array<string, string> $attributes
     * @param list<string> $gtagDestinations The tags gtag.js configures; none loads no gtag.js
     * @return GtmLoader
     */
    private function loader(
        HeadTagManager $head,
        bool $enabled,
        ?string $containerId,
        string $host = ConfigInterface::DEFAULT_SCRIPT_HOST,
        array $attributes = [],
        array $gtagDestinations = []
    ): GtmLoader {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getContainerId')->willReturn($containerId);
        $config->method('getScriptHost')->willReturn($host);
        $destinations = $this->createMock(GtagDestinationsInterface::class);
        $destinations->method('forStore')->willReturn($gtagDestinations);

        return new GtmLoader($config, new JsonHexTag(), $destinations, $head, $attributes);
    }
}
