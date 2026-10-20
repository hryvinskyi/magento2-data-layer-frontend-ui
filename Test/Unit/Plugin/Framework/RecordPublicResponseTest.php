<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Test\Unit\Plugin\Framework;

use Hryvinskyi\DataLayerFrontendUi\Api\Response\PublicResponseRecorderInterface;
use Hryvinskyi\DataLayerFrontendUi\Plugin\Framework\RecordPublicResponse;
use Magento\Framework\App\Response\Http;
use PHPUnit\Framework\TestCase;

/**
 * Setting a response's public headers is remembered, and the method's result passes through
 */
class RecordPublicResponseTest extends TestCase
{
    /**
     * @return void
     */
    public function testPublicHeadersAreRemembered(): void
    {
        $recorder = $this->createMock(PublicResponseRecorderInterface::class);
        $recorder->expects(self::once())->method('recordPublic');

        $result = (new RecordPublicResponse($recorder))->afterSetPublicHeaders($this->createMock(Http::class), null);

        self::assertNull($result);
    }
}
