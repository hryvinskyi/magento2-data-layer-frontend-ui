<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Plugin\Framework;

use Hryvinskyi\DataLayerFrontendUi\Api\Response\PublicResponseRecorderInterface;
use Magento\Framework\App\Response\Http;

/**
 * Remembers that the response was declared public when the page sets its public cache headers
 */
class RecordPublicResponse
{
    /**
     * @param PublicResponseRecorderInterface $recorder
     */
    public function __construct(private readonly PublicResponseRecorderInterface $recorder)
    {
    }

    /**
     * Remember the declaration once the headers are set
     *
     * @param Http $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterSetPublicHeaders(Http $subject, mixed $result): mixed
    {
        $this->recorder->recordPublic();

        return $result;
    }
}
