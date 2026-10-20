/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The page's settings and the channel each event takes. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {parseSettings, routeOf, sendsToServer} from '../../view/frontend/web/js/data-layer/settings.js';

const CONFIG = {
    core: {
        impressionTrigger: 'visible',
        drainUrl: 'https://shop.test/hryvinskyi_datalayer/events/drain/',
        acknowledgeUrl: 'https://shop.test/hryvinskyi_datalayer/events/acknowledge/',
        plugins: ['https://shop.test/static/frontend/A/b/en_GB/Vendor_Bridge/js/plugin.js']
    },
    server: {
        channels: {view_item: 'browser', view_item_list: 'server', select_item: 'both'},
        collectUrl: 'https://shop.test/hryvinskyi_datalayer/collect/'
    },
    bridge: {mode: 'b2b'}
};

const FALLBACK = {
    impressionTrigger: 'load',
    drainUrl: '',
    acknowledgeUrl: '',
    plugins: [],
    channels: {},
    collectUrl: ''
};

/**
 * The settings the module reads, without the raw object kept for plugins
 *
 * @param {Object} settings
 * @returns {Object}
 */
const readable = (settings) => Object.fromEntries(Object.entries(settings).filter(([key]) => key !== 'config'));

test('the page\'s settings are read from the core and server parts', () => {
    assert.deepEqual(readable(parseSettings(JSON.stringify(CONFIG))), {
        impressionTrigger: 'visible',
        drainUrl: CONFIG.core.drainUrl,
        acknowledgeUrl: CONFIG.core.acknowledgeUrl,
        plugins: CONFIG.core.plugins,
        channels: CONFIG.server.channels,
        collectUrl: CONFIG.server.collectUrl
    });
});

test('the whole object, every other provider\'s part included, is kept and frozen for plugins', () => {
    const {config} = parseSettings(JSON.stringify(CONFIG));

    assert.deepEqual(config, CONFIG);
    assert.ok(Object.isFrozen(config));
    assert.ok(Object.isFrozen(config.bridge));
    assert.ok(Object.isFrozen(config.core.plugins));
    assert.throws(() => {
        config.bridge.mode = 'b2c';
    }, TypeError);
});

test('missing or malformed settings fall back to load impressions, no drain, no plugins, browser only', () => {
    for (const text of [undefined, '', 'not json', '[]', '{"core":[]}', '{}']) {
        assert.deepEqual(readable(parseSettings(text)), FALLBACK, String(text));
    }
    assert.deepEqual(readable(parseSettings(JSON.stringify({
        core: {impressionTrigger: 'scroll', drainUrl: 3, acknowledgeUrl: [], plugins: 'x.js'},
        server: {channels: [], collectUrl: null}
    }))), FALLBACK);
});

test('plugins that are not addresses are dropped', () => {
    assert.deepEqual(parseSettings(JSON.stringify({core: {plugins: ['', 3, ' /a.js ', null]}})).plugins, ['/a.js']);
});

test('an unknown channel is dropped from the settings', () => {
    const settings = parseSettings(JSON.stringify({server: {channels: {view_item: 'cloud', select_item: 2, view_cart: 'both'}}}));

    assert.deepEqual(settings.channels, {view_cart: 'both'});
});

test('each channel routes an event to the browser, the server or both', () => {
    const settings = parseSettings(JSON.stringify(CONFIG));

    assert.deepEqual(routeOf(settings, 'view_item', true), {browser: true, server: false});
    assert.deepEqual(routeOf(settings, 'view_item_list', true), {browser: false, server: true});
    assert.deepEqual(routeOf(settings, 'select_item', true), {browser: true, server: true});
});

test('an event missing from the channels goes to the browser', () => {
    const settings = parseSettings(JSON.stringify(CONFIG));

    assert.deepEqual(routeOf(settings, 'view_promotion', true), {browser: true, server: false});
    assert.deepEqual(routeOf(settings, 'constructor', true), {browser: true, server: false});
});

test('while events cannot be posted, every channel goes to the browser', () => {
    const settings = parseSettings(JSON.stringify(CONFIG));

    for (const eventName of ['view_item', 'view_item_list', 'select_item']) {
        assert.deepEqual(routeOf(settings, eventName, false), {browser: true, server: false}, eventName);
    }
});

test('without the server part every event goes to the browser and nothing is posted', () => {
    const settings = parseSettings(JSON.stringify({core: CONFIG.core}));

    assert.equal(sendsToServer(settings), false);
    assert.deepEqual(routeOf(settings, 'view_item_list', sendsToServer(settings)), {browser: true, server: false});
});

test('the page needs the means to post only when an address is set and some channel reaches the server', () => {
    assert.equal(sendsToServer(parseSettings(JSON.stringify(CONFIG))), true);
    assert.equal(sendsToServer(parseSettings(JSON.stringify({
        server: {channels: {view_item: 'browser'}, collectUrl: '/collect'}
    }))), false);
    assert.equal(sendsToServer(parseSettings(JSON.stringify({
        server: {channels: {view_item: 'both'}, collectUrl: ''}
    }))), false);
});
