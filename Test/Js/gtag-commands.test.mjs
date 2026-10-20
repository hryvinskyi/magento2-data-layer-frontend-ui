/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What gtag.js is told for each data layer push. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {parseGtagSettings, toGtagCommands} from '../../view/frontend/web/js/data-layer/gtag-commands.js';

const GA4 = parseGtagSettings({gtag: {measurementId: 'G-ABC123'}});
const ADS_ONLY = parseGtagSettings({core: {impressionTrigger: 'load'}});

test('an ecommerce event sends its ecommerce object, items included, to the measurement id alone', () => {
    const items = [{item_id: 'SKU-1', price: 10, quantity: 2}];

    assert.deepEqual(
        toGtagCommands({event: 'purchase', ecommerce: {transaction_id: '100', value: 20, currency: 'EUR', items}}, GA4),
        [['event', 'purchase', {transaction_id: '100', value: 20, currency: 'EUR', items, send_to: 'G-ABC123'}]]
    );
});

test('a non-ecommerce event sends its own top-level values', () => {
    assert.deepEqual(
        toGtagCommands({event: 'login', method: 'password'}, GA4),
        [['event', 'login', {method: 'password', send_to: 'G-ABC123'}]]
    );
    assert.deepEqual(toGtagCommands({event: 'sign_up'}, GA4), [['event', 'sign_up', {send_to: 'G-ABC123'}]]);
});

test('the ecommerce reset, Tag Manager\'s own events and what is not an event become nothing', () => {
    [
        {ecommerce: null},
        {event: 'view_item', ecommerce: null},
        {event: 'gtm.js', 'gtm.start': 1},
        {event: 'Purchase'},
        {event: ''},
        {value: 1},
        null,
        'purchase',
        ['event', 'purchase']
    ].forEach((object) => assert.deepEqual(toGtagCommands(object, GA4), [], JSON.stringify(object)));
});

test('the Google Ads conversion becomes gtag\'s conversion event with its four parameters only', () => {
    const conversion = {
        event: 'ads_conversion',
        send_to: 'AW-123/Label_1',
        value: 99.5,
        currency: 'DKK',
        transaction_id: '000000042',
        extra: 'dropped'
    };

    assert.deepEqual(toGtagCommands(conversion, GA4), [['event', 'conversion', {
        send_to: 'AW-123/Label_1',
        value: 99.5,
        currency: 'DKK',
        transaction_id: '000000042'
    }]]);
    assert.deepEqual(
        toGtagCommands({event: 'ads_conversion', send_to: 'AW-123/Label_1', transaction_id: '7'}, ADS_ONLY),
        [['event', 'conversion', {send_to: 'AW-123/Label_1', transaction_id: '7'}]]
    );
    assert.deepEqual(toGtagCommands({event: 'ads_conversion', value: 1}, GA4), []);
});

test('without a measurement id only conversions are sent', () => {
    assert.deepEqual(ADS_ONLY, {measurementId: ''});
    assert.deepEqual(toGtagCommands({event: 'purchase', ecommerce: {value: 1}}, ADS_ONLY), []);
    assert.deepEqual(toGtagCommands({event: 'login'}, ADS_ONLY), []);
});

test('the settings are read defensively and frozen', () => {
    assert.ok(Object.isFrozen(GA4));
    assert.deepEqual(parseGtagSettings({gtag: {measurementId: ' G-X1 '}}), {measurementId: 'G-X1'});
    [undefined, null, [], {gtag: []}, {gtag: {measurementId: 5}}].forEach(
        (config) => assert.deepEqual(parseGtagSettings(config), {measurementId: ''})
    );
});
