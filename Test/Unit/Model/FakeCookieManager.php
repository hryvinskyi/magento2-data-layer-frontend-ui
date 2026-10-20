<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model;

use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\Cookie\SensitiveCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Records every cookie written or deleted, with its attributes, or fails every write when told to
 */
class FakeCookieManager implements CookieManagerInterface
{
    /**
     * @var list<array{action: string, name: string, value: string|null, metadata: array<mixed>}>
     */
    public array $writes = [];

    /**
     * @param \Throwable|null $failure Thrown by every write instead of recording it
     */
    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function setSensitiveCookie($name, $value, ?SensitiveCookieMetadata $metadata = null)
    {
        throw new \LogicException('No sensitive cookie is expected.');
    }

    /**
     * @inheritDoc
     */
    public function setPublicCookie($name, $value, ?PublicCookieMetadata $metadata = null)
    {
        $this->record('set', (string) $name, (string) $value, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function deleteCookie($name, ?CookieMetadata $metadata = null)
    {
        $this->record('delete', (string) $name, null, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function getCookie($name, $default = null)
    {
        return $default;
    }

    /**
     * Record a write, or fail it
     *
     * @param string $action
     * @param string $name
     * @param string|null $value
     * @param CookieMetadata|null $metadata
     * @return void
     */
    private function record(string $action, string $name, ?string $value, ?CookieMetadata $metadata): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->writes[] = [
            'action' => $action,
            'name' => $name,
            'value' => $value,
            'metadata' => $metadata !== null ? $metadata->__toArray() : [],
        ];
    }
}
