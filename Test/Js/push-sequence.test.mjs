/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which objects reach dataLayer and in which order. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import * as sequence from '../../view/frontend/web/js/data-layer/push-sequence.js';

test('every ecommerce event is preceded by an ecommerce reset; other events are not', () => {
    const viewItem = {event: 'view_item', ecommerce: {currency: 'GBP', items: [{item_id: 'A'}]}};
    const login = {event: 'login', method: 'website'};
    const purchase = {event: 'purchase', ecommerce: {transaction_id: '100'}};

    assert.deepEqual(sequence.toPushes([viewItem, login, purchase]), [
        {ecommerce: null},
        viewItem,
        login,
        {ecommerce: null},
        purchase
    ]);
});

test('an event whose ecommerce key is present but empty still gets the reset', () => {
    assert.deepEqual(sequence.toPushes([{event: 'x', ecommerce: null}]), [{ecommerce: null}, {event: 'x', ecommerce: null}]);
});

test('what is not an object is dropped, and a non-list gives nothing', () => {
    assert.deepEqual(sequence.toPushes([null, 3, 'view_item', [], {event: 'login'}]), [{event: 'login'}]);
    assert.deepEqual(sequence.toPushes(undefined), []);
    assert.deepEqual(sequence.toPushes({event: 'login'}), []);
    assert.deepEqual(sequence.toPushes([]), []);
});

test('page event text keeps only objects with a non-empty string event, and never throws', () => {
    assert.deepEqual(
        sequence.parseEventsText('[{"event":"view_item","ecommerce":{}},{"event":""},{"event":3},{"ecommerce":{}},5,null]'),
        [{event: 'view_item', ecommerce: {}}]
    );
    assert.deepEqual(sequence.parseEventsText('[]'), []);
    assert.equal(sequence.parseEventsText('{"event":"view_item"}'), null);
    assert.equal(sequence.parseEventsText('[{"event":'), null);
    assert.equal(sequence.parseEventsText(''), null);
    assert.equal(sequence.parseEventsText(null), null);
});

test('a decoded list keeps its events; anything else is not a list of events', () => {
    const login = {event: 'login', method: 'website'};

    assert.deepEqual(sequence.parseEvents([login, 'junk', {event: 1}]), [login]);
    assert.equal(sequence.parseEvents({events: [login]}), null);
    assert.equal(sequence.parseEvents(undefined), null);
});
