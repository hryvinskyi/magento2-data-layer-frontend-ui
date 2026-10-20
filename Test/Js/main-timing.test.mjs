/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * When the browser module pushes the page's events, and which settings it reads: the entry runs against a synthetic
 * page whose own script registered a `DOMContentLoaded` handler before the module ran, as an inline script of the page
 * does. Plain Node:
 * node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import './import-map.mjs';
import {test} from 'node:test';
import assert from 'node:assert/strict';

const CONFIG_SELECTOR = 'script[type="application/json"][id="hryvinskyi-datalayer-config"]';
const PAGE_SELECTOR = 'script[data-hryvinskyi-datalayer="page"]';
const DATA_SCRIPTS = 'script[data-hryvinskyi-datalayer]';
const SETTINGS = JSON.stringify({core: {impressionTrigger: 'load', drainUrl: '', plugins: []}});
const PURCHASE = {
    event: 'purchase',
    ecommerce: {transaction_id: '000000042', value: 99.5, currency: 'EUR', items: []}
};

let instance = 0;

/**
 * Install a synthetic page as the module's globals
 *
 * @param {string} readyState The document's state when the module runs
 * @returns {{
 *     listeners: Array<{target: string, type: string, listener: Function}>,
 *     queried: Array<string>,
 *     observed: Array<Object>,
 *     fire: function(string): void
 * }}
 */
function installPage(readyState) {
    const listeners = [];
    const queried = [];
    const observed = [];
    const scripts = {
        [CONFIG_SELECTOR]: [{textContent: SETTINGS}],
        [PAGE_SELECTOR]: [{textContent: JSON.stringify([PURCHASE])}]
    };
    const listen = (target) => (type, listener) => listeners.push({target, type, listener});

    globalThis.window = globalThis;
    delete globalThis.dataLayer;
    globalThis.addEventListener = listen('window');
    globalThis.document = {
        readyState,
        cookie: '',
        visibilityState: 'visible',
        body: {},
        querySelectorAll: (selector) => {
            queried.push(selector);

            return scripts[selector] || [];
        },
        addEventListener: listen('document'),
        dispatchEvent: () => true
    };
    globalThis.MutationObserver = class {
        /**
         * @param {Object} target
         * @returns {void}
         */
        observe(target) {
            observed.push(target);
        }
    };
    if (typeof globalThis.CustomEvent !== 'function') {
        globalThis.CustomEvent = class {
            /**
             * @param {string} type
             * @param {Object} init
             */
            constructor(type, init) {
                this.type = type;
                this.detail = init.detail;
            }
        };
    }

    return {
        listeners,
        queried,
        observed,
        fire: (type) => listeners.filter((entry) => entry.type === type).forEach((entry) => entry.listener())
    };
}

/**
 * Run the browser module's entry, a fresh instance each time
 *
 * @returns {Promise<void>}
 */
async function runModule() {
    instance += 1;
    await import('@hryvinskyi/data-layer/main.js?page=' + instance);
}

/**
 * @param {*} pushes
 * @returns {Array<string>} The transaction ids of the purchase pushes
 */
function purchasesIn(pushes) {
    return (Array.isArray(pushes) ? pushes : [])
        .filter((push) => push.event === 'purchase')
        .map((push) => push.ecommerce.transaction_id);
}

test('a DOMContentLoaded handler of the page finds the page\'s events in dataLayer', async () => {
    const page = installPage('interactive');
    const seenByPage = [];

    document.addEventListener('DOMContentLoaded', () => {
        seenByPage.push(...purchasesIn(window.dataLayer));
    });
    await runModule();

    assert.deepEqual(window.dataLayer, [{ecommerce: null}, PURCHASE], 'the page events are pushed as the module runs');
    assert.ok(!page.queried.includes(DATA_SCRIPTS), 'lists wait for DOMContentLoaded');

    page.fire('DOMContentLoaded');

    assert.deepEqual(seenByPage, ['000000042']);
    assert.ok(page.queried.includes(DATA_SCRIPTS), 'lists are mounted after the page\'s own handlers');
    assert.deepEqual(page.observed, [document.body]);
});

test('the rest of the page starts once, whether DOMContentLoaded or load comes first', async () => {
    const page = installPage('interactive');

    await runModule();
    page.fire('DOMContentLoaded');
    page.fire('load');

    assert.equal(page.observed.length, 1);
    assert.deepEqual(purchasesIn(window.dataLayer), ['000000042']);
});

test('a module run after the page has loaded pushes the events and starts the rest at once', async () => {
    const page = installPage('complete');

    await runModule();

    assert.deepEqual(purchasesIn(window.dataLayer), ['000000042']);
    assert.deepEqual(page.observed, [document.body]);
});

test('a module run while the page is still being parsed waits for DOMContentLoaded to read the page', async () => {
    const page = installPage('loading');

    await runModule();

    assert.equal(window.dataLayer, undefined);
    assert.deepEqual(page.queried, []);

    page.fire('DOMContentLoaded');

    assert.deepEqual(purchasesIn(window.dataLayer), ['000000042']);
    assert.deepEqual(page.observed, [document.body]);
});

/**
 * Run the module on a loaded page whose content carries a planted settings script after the page's own, and report
 * the plugin addresses the module refused: the planted settings name a plugin on a foreign origin
 *
 * @param {boolean} ownInHead Whether the page's own settings are in `<head>`
 * @returns {Promise<Array<string>>}
 */
async function refusedPluginsWith(ownInHead) {
    const own = {textContent: SETTINGS};
    const planted = {
        textContent: JSON.stringify({core: {impressionTrigger: 'load', drainUrl: '', plugins: ['https://evil.test/p.js']}})
    };
    const warnings = [];
    const consoleWarn = console.warn;

    installPage('complete');
    const querySelectorAll = document.querySelectorAll;
    document.querySelectorAll = (selector) => (
        selector === CONFIG_SELECTOR ? [own, planted] : querySelectorAll(selector)
    );
    if (ownInHead) {
        document.head = {querySelector: (selector) => (selector === CONFIG_SELECTOR ? own : null)};
    }
    console.warn = (message) => warnings.push(message);
    try {
        await runModule();
        await new Promise((resolve) => setImmediate(resolve));
    } finally {
        console.warn = consoleWarn;
    }

    return warnings.filter((message) => message.includes('evil.test'));
}

test('the settings the page prints in the head win over a copy planted later in the content', async () => {
    assert.deepEqual(await refusedPluginsWith(true), []);
});

test('without settings in the head (a page cached before they moved there) the last copy is read', async () => {
    const refused = await refusedPluginsWith(false);

    assert.equal(refused.length, 1, 'the planted copy was read, and its foreign plugin refused');
});
