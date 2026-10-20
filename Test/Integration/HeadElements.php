<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayerFrontendUi\ViewModel\Bootstrap as BootstrapViewModel;
use Hryvinskyi\HeadTagManager\Api\HeadTagManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Reads what the head tag manager prints into `<head>` for the elements registered so far, and the data layer's
 * scripts among them
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait HeadElements
{
    /**
     * Get what the head tag manager prints into `<head>` now
     *
     * @return string
     */
    private function headHtml(): string
    {
        $headTagManager = Bootstrap::getObjectManager()->get(HeadTagManagerInterface::class);
        self::assertInstanceOf(HeadTagManagerInterface::class, $headTagManager);

        return $headTagManager->render();
    }

    /**
     * Get the elements of a fragment of HTML, in document order
     *
     * @param string $html
     * @return list<\DOMElement>
     */
    private function elementsOf(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $html . '</div>',
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $elements = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->tagName !== 'div') {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Get the one script of the fragment that carries the given attribute value
     *
     * @param string $html
     * @param string $attribute
     * @param string $value
     * @return \DOMElement
     */
    private function onlyScript(string $html, string $attribute, string $value): \DOMElement
    {
        $scripts = array_values(array_filter(
            $this->elementsOf($html),
            static fn (\DOMElement $element): bool => $element->tagName === 'script'
                && $element->getAttribute($attribute) === $value
        ));
        self::assertCount(1, $scripts, sprintf('One script with %s="%s".', $attribute, $value));

        return $scripts[0];
    }

    /**
     * Get the settings printed for the browser module, from the script with the fixed id
     *
     * @param string $html
     * @return array<mixed>
     */
    private function printedConfig(string $html): array
    {
        $script = $this->onlyScript($html, 'id', BootstrapViewModel::CONFIG_ID);
        self::assertSame('application/json', $script->getAttribute('type'));
        self::assertSame('config', $script->getAttribute('data-hryvinskyi-datalayer'));
        $config = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * Get the page's events printed for the browser module
     *
     * @param string $html
     * @return list<array<string, mixed>> Each event's named fields
     */
    private function printedEvents(string $html): array
    {
        $script = $this->onlyScript($html, 'data-hryvinskyi-datalayer', 'page');
        self::assertSame('application/json', $script->getAttribute('type'));
        $events = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($events);

        $printed = [];
        foreach ($events as $event) {
            self::assertIsArray($event);
            $printed[] = array_filter($event, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return $printed;
    }

    /**
     * Get the import map printed for the browser module's ES modules
     *
     * @param string $html
     * @return array<mixed> The `imports` object
     */
    private function printedImportMap(string $html): array
    {
        $map = json_decode($this->onlyScript($html, 'type', 'importmap')->textContent, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertIsArray($map['imports'] ?? null);

        return $map['imports'];
    }

    /**
     * Get the addresses announced for module preloading, in order
     *
     * @param string $html
     * @return list<string>
     */
    private function preloadedModules(string $html): array
    {
        $urls = [];
        foreach ($this->elementsOf($html) as $element) {
            if ($element->tagName === 'link' && $element->getAttribute('rel') === 'modulepreload') {
                $urls[] = $element->getAttribute('href');
            }
        }

        return $urls;
    }
}
