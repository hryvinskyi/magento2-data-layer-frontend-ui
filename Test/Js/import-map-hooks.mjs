/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Node module resolution hooks: resolve the browser module's bare specifiers to its source files, as the import map
 * the page prints resolves them to the published files in the browser.
 */

const PREFIX = '@hryvinskyi/data-layer/';
const SOURCE = new URL('../../view/frontend/web/js/data-layer/', import.meta.url);

/**
 * @param {string} specifier
 * @param {Object} context
 * @param {Function} nextResolve
 * @returns {Promise<{url: string, shortCircuit?: boolean}>}
 */
export async function resolve(specifier, context, nextResolve) {
    if (specifier.startsWith(PREFIX)) {
        return {url: new URL(specifier.slice(PREFIX.length), SOURCE).href, shortCircuit: true};
    }

    return nextResolve(specifier, context);
}
