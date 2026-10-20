/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The gtag destination plugin over the real push pipeline, with the page's data layer as a plain array.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createPluginHost} from '../../view/frontend/web/js/data-layer/plugins.js';
import './import-map.mjs';

const {default: start} = await import('../../view/frontend/web/js/data-layer/gtag-destination.js');

/**
 * The gtag commands queued on the page's data layer, as plain arrays
 *
 * @param {Array} dataLayer
 * @returns {Array<Array>}
 */
function commands(dataLayer) {
    return dataLayer
        .filter((entry) => Object.prototype.toString.call(entry) === '[object Arguments]')
        .map((entry) => Array.from(entry));
}

test('pushes made before and after the plugin starts reach gtag.js as commands, in order', () => {
    const dataLayer = [];
    globalThis.window = {dataLayer};
    const host = createPluginHost({
        config: Object.freeze({gtag: Object.freeze({measurementId: 'G-ABC123'})}),
        commit: (object) => dataLayer.push(object),
        warn: (reason) => assert.fail(reason)
    });

    host.push({event: 'purchase', ecommerce: {transaction_id: '100', value: 20}});
    host.push({event: 'ads_conversion', send_to: 'AW-1/L', transaction_id: '100', value: 20, currency: 'EUR'});
    host.run({default: start}, 'gtag-destination.js');
    host.push({event: 'login', method: 'password'});

    assert.deepEqual(commands(window.dataLayer), [
        ['event', 'purchase', {transaction_id: '100', value: 20, send_to: 'G-ABC123'}],
        ['event', 'conversion', {send_to: 'AW-1/L', transaction_id: '100', value: 20, currency: 'EUR'}],
        ['event', 'login', {method: 'password', send_to: 'G-ABC123'}]
    ]);
    assert.equal(dataLayer.filter((entry) => entry.event === 'purchase').length, 1, 'the push itself is kept');
    delete globalThis.window;
});

test('a page without a data layer gets one for the commands', () => {
    globalThis.window = {};
    const host = createPluginHost({config: Object.freeze({}), commit: () => undefined, warn: () => undefined});

    host.push({event: 'ads_conversion', send_to: 'AW-1/L', transaction_id: '9'});
    host.run({default: start}, 'gtag-destination.js');

    assert.deepEqual(commands(window.dataLayer), [['event', 'conversion', {send_to: 'AW-1/L', transaction_id: '9'}]]);
    delete globalThis.window;
});
