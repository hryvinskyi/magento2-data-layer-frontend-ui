<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Hryvinskyi\HeadTagManager\Model\HeadElement\Factory\LinkElementFactory;
use Hryvinskyi\HeadTagManager\Model\HeadElement\Factory\ScriptElementFactory;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Hryvinskyi\HeadTagManager\Model\Registry\HeadElementFactoryRegistry;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * The head tag manager the storefront uses, with its script and link elements, over a secure renderer that prints
 * each tag as <tag attr="value">content</tag> in the given attribute order
 *
 * Its `render()` is what the page prints into `<head>`: every registered element, by sort order.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait HeadTags
{
    /**
     * Get an empty head tag manager
     *
     * @return HeadTagManager
     */
    private function headTagManager(): HeadTagManager
    {
        $secureRenderer = $this->createMock(SecureHtmlRenderer::class);
        $secureRenderer->method('renderTag')->willReturnCallback(
            static function (string $tag, array $attributes = [], ?string $content = null): string {
                $html = '<' . $tag;
                foreach ($attributes as $name => $value) {
                    $html .= ' ' . $name . '="'
                        . htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8') . '"';
                }

                return $html . '>' . $content . '</' . $tag . '>';
            }
        );

        return new HeadTagManager(new HeadElementFactoryRegistry([
            new ScriptElementFactory($secureRenderer),
            new LinkElementFactory($secureRenderer),
        ]));
    }

    /**
     * Get the lines the head tag manager prints into `<head>`
     *
     * @param HeadTagManager $headTagManager
     * @return list<string>
     */
    private function headLines(HeadTagManager $headTagManager): array
    {
        $html = trim($headTagManager->render());

        return $html === '' ? [] : explode(PHP_EOL, $html);
    }
}
