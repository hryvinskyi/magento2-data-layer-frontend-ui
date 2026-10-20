<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Model\Browser;

use Hryvinskyi\DataLayerApi\Api\BrowserConfigProviderInterface;

/**
 * A settings provider with a fixed key and part, or one that fails, recording the store views it was asked for
 */
class FakeProvider implements BrowserConfigProviderInterface
{
    /**
     * @var list<int>
     */
    public array $askedFor = [];

    /**
     * @param string $key
     * @param array<string, scalar|array<mixed>|null> $part
     * @param \Throwable|null $failure Thrown instead of answering
     */
    public function __construct(
        private readonly string $key,
        private readonly array $part = [],
        private readonly ?\Throwable $failure = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @inheritDoc
     */
    public function provide(int $storeId): array
    {
        $this->askedFor[] = $storeId;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->part;
    }
}
