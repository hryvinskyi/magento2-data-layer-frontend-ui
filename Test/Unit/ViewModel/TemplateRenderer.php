<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\ViewModel;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Runs one of the module's frontend templates the way a Template block does, with a secure renderer that prints each
 * tag as <tag attr="value">content</tag> and an escaper that escapes a URL or a text for an attribute
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait TemplateRenderer
{
    /**
     * Get the output of a template for a block holding the given data
     *
     * @param string $template Path under view/frontend/templates
     * @param array<string, mixed> $data
     * @return string
     */
    private function render(string $template, array $data): string
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn (string $url): string => htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        );
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        );
        $block = $this->createMock(Template::class);
        $block->method('getData')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);

        $secureRenderer = $this->createMock(SecureHtmlRenderer::class);
        $secureRenderer->method('renderTag')->willReturnCallback(
            static function (string $tag, array $attributes = [], ?string $content = null): string {
                $html = '<' . $tag;
                foreach ($attributes as $name => $value) {
                    $html .= ' ' . $name . '="' . (is_scalar($value) ? (string) $value : '') . '"';
                }

                return $html . '>' . $content . '</' . $tag . '>';
            }
        );

        $file = dirname(__DIR__, 3) . '/view/frontend/templates/' . $template;
        ob_start();
        (static function (Template $block, SecureHtmlRenderer $secureRenderer, Escaper $escaper, string $file): void {
            include $file;
        })($block, $secureRenderer, $escaper, $file);

        return trim((string) ob_get_clean());
    }
}
