<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Signal;

/**
 * The signal about waiting server events asked for during the current request, held until the response is known
 *
 * Whether a response may carry the signal is known only once it is complete: a redirect or a page the cache will not
 * store may, a page the full-page cache stores never does.
 */
interface PendingSignalInterface
{
    /**
     * Write what was asked for in this request (events wait, or nothing waits any more) on the response
     *
     * Nothing is written when nothing was asked for, and each state is written at most once per request.
     *
     * @return void
     */
    public function send(): void;

    /**
     * Forget what was asked for in this request: the response cannot carry it
     *
     * @return void
     */
    public function discard(): void;
}
