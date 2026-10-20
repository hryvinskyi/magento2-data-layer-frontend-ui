<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\DataLayerFrontendUi\Api\Head;

/**
 * Where the data layer's elements sit among the elements the head tag manager prints into `<head>`
 *
 * The head tag manager renders its elements by ascending sort order, insertion order breaking ties, and gives an
 * element registered without one the order 100. These come after that default, so a script another module registers
 * without a sort order (a consent manager's, for one) still runs before the Google tags; a module that must run
 * between them names an order of its own.
 *
 * Their order among themselves is required:
 * - the Google tag loaders first (the Tag Manager container, then gtag.js and its stub), so the tags start as early
 *   as the head allows;
 * - then the browser module's settings and the page's events, JSON data the module reads once the page is parsed;
 * - then the import map, which must come before every module script and module preload that resolves through it;
 * - then the preloads of the modules the entry imports and of the plugins it loads;
 * - then the entry module itself, which the browser runs once the page is parsed, before `DOMContentLoaded`.
 *
 * @api
 */
interface HeadSortOrderInterface
{
    /**
     * The Google Tag Manager container's loader
     */
    public const GTM_LOADER = 200;

    /**
     * gtag.js itself, loaded asynchronously
     */
    public const GTAG_LIBRARY = 201;

    /**
     * The inline gtag.js stub: `gtag()`, its start and one `config` per destination
     */
    public const GTAG_STUB = 202;

    /**
     * The browser module's settings
     */
    public const SETTINGS = 210;

    /**
     * The page's events
     */
    public const PAGE_EVENTS = 211;

    /**
     * The import map the browser module's ES modules resolve their named imports through
     */
    public const IMPORT_MAP = 220;

    /**
     * The `modulepreload` links, in the order the browser module needs them
     */
    public const MODULE_PRELOAD = 230;

    /**
     * The browser module's entry
     */
    public const ENTRY = 240;
}
