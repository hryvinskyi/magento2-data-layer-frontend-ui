<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Integration;

use Hryvinskyi\DataLayer\Api\PageEventSourceInterface;

/**
 * A page event source that reports nothing and counts how often the page asked it, so a test can tell whether the
 * block holding it ran
 */
class CountingPageEventSource implements PageEventSourceInterface
{
    /**
     * @var int How often the page asked for the events
     */
    public int $calls = 0;

    /**
     * @inheritDoc
     */
    public function events(): array
    {
        $this->calls++;

        return [];
    }
}
