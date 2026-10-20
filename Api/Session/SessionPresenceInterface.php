<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Session;

/**
 * Tells whether the visitor already has a session, without opening one
 */
interface SessionPresenceInterface
{
    /**
     * Whether the request belongs to a visitor who already has a session
     *
     * @return bool
     */
    public function exists(): bool;
}
