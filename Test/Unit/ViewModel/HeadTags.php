<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Hryvinskyi\Base\Model\Html\AttributeRenderer;
use Hryvinskyi\Base\Model\Html\CssOptions;
use Hryvinskyi\Base\Model\Html\HtmlEncoder;
use Hryvinskyi\Base\Model\Html\TextConverter;
use Hryvinskyi\Base\Model\Json\JsonEncoder;
use Hryvinskyi\HeadTagManager\Model\HeadElement\Factory\LinkElementFactory;
use Hryvinskyi\HeadTagManager\Model\HeadElement\Factory\ScriptElementFactory;
use Hryvinskyi\HeadTagManager\Model\HeadElement\LinkElement;
use Hryvinskyi\HeadTagManager\Model\HeadElement\LinkElementFactory as LinkElementObjectFactory;
use Hryvinskyi\HeadTagManager\Model\HeadElement\ScriptElement;
use Hryvinskyi\HeadTagManager\Model\HeadElement\ScriptElementFactory as ScriptElementObjectFactory;
use Hryvinskyi\HeadTagManager\Model\HeadTagManager;
use Hryvinskyi\HeadTagManager\Model\Registry\HeadElementFactoryRegistry;
use Magento\Framework\Escaper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\ZendEscaper;
use Psr\Log\NullLogger;

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

        $textConverter = new TextConverter();
        $attributeRenderer = new AttributeRenderer(
            new HtmlEncoder(new ZendEscaper(), new Escaper()),
            new JsonEncoder(),
            new CssOptions($textConverter, true),
            $textConverter,
            [
                'type',
                'id',
                'class',
                'name',
                'value',

                'href',
                'src',
                'srcset',
                'form',
                'action',
                'method',

                'selected',
                'checked',
                'readonly',
                'disabled',
                'multiple',

                'size',
                'maxlength',
                'width',
                'height',
                'rows',
                'cols',

                'alt',
                'title',
                'rel',
                'media',
                    ],
            ['aria', 'data', 'data-ng', 'ng'],
            true
        );
        // The module's element factories create an element with no data and apply attributes, content and sort order
        // through its setters, so the generated factories answer an element built with its renderers alone.
        $scripts = $this->createMock(ScriptElementObjectFactory::class);
        $scripts->method('create')->willReturnCallback(
            static fn (): ScriptElement => new ScriptElement($attributeRenderer, $secureRenderer)
        );
        $links = $this->createMock(LinkElementObjectFactory::class);
        $links->method('create')->willReturnCallback(
            static fn (): LinkElement => new LinkElement($attributeRenderer, $secureRenderer)
        );

        return new HeadTagManager(
            new HeadElementFactoryRegistry([
                new ScriptElementFactory($scripts),
                new LinkElementFactory($links),
            ]),
            new Json(),
            new NullLogger()
        );
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
