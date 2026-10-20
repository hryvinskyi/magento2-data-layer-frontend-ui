/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Reading list and promotion data scripts and building their events.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import * as listData from '../../view/frontend/web/js/data-layer/list-data.js';

const LIST = {
    list: {
        item_list_id: 'category_12',
        item_list_name: 'Laptops',
        items: [
            {item_id: 'SKU1', item_name: 'Laptop A', index: 0, price: 99.5},
            {item_id: 'SKU2', item_name: 'Laptop B', index: 1, price: 120}
        ]
    },
    impression: true,
    links: {'/laptop-a.html': 0, '/laptop-b.html': 1}
};

const PROMOTIONS = {
    promotions: [
        {promotion_id: 'summer', promotion_name: 'Summer sale', creative_slot: 'home_1'},
        {promotion_id: 'docks', promotion_name: 'Docks', creative_slot: 'home_2'}
    ],
    impression: true,
    links: {'/sale.html': 0, '/docks.html': 1}
};

/**
 * A copy of a fixture with some keys replaced
 *
 * @param {Object} fixture
 * @param {Object} changes
 * @returns {Object}
 */
const changed = (fixture, changes) => JSON.stringify({...fixture, ...changes});

test('a list script is read into its list, impression flag and links by path', () => {
    const data = listData.parseList(JSON.stringify(LIST));

    assert.deepEqual(data.list, LIST.list);
    assert.equal(data.impression, true);
    assert.deepEqual(data.links, new Map([['/laptop-a.html', 0], ['/laptop-b.html', 1]]));
});

test('a list with no links is still read, so impressions work without clicks', () => {
    assert.deepEqual(listData.parseList(changed(LIST, {links: {}})).links, new Map());
});

test('a list script of the wrong shape reads as nothing and never throws', () => {
    const broken = {
        'not JSON': '{"list":',
        'not an object': '[1,2]',
        'empty text': '',
        'no list': changed(LIST, {list: undefined}),
        'list not an object': changed(LIST, {list: []}),
        'no items': changed(LIST, {list: {item_list_id: 'x', item_list_name: 'y'}}),
        'empty items': changed(LIST, {list: {...LIST.list, items: []}}),
        'an item not an object': changed(LIST, {list: {...LIST.list, items: [{item_id: 'A'}, 'B']}}),
        'list id not text': changed(LIST, {list: {...LIST.list, item_list_id: 12}}),
        'impression not a boolean': changed(LIST, {impression: 'yes'}),
        'no links': changed(LIST, {links: undefined}),
        'links a list': changed(LIST, {links: [0, 1]}),
        'fractional position': changed(LIST, {links: {'/a.html': 0.5}}),
        'position as text': changed(LIST, {links: {'/a.html': '0'}}),
        'negative position': changed(LIST, {links: {'/a.html': -1}}),
        'position past the end': changed(LIST, {links: {'/a.html': 2}})
    };

    for (const [name, text] of Object.entries(broken)) {
        assert.equal(listData.parseList(text), null, name);
    }
    assert.equal(listData.parseList(null), null);
    assert.equal(listData.parseList(undefined), null);
});

test('a list impression is view_item_list carrying the list as its ecommerce object', () => {
    assert.deepEqual(listData.listImpression(listData.parseList(JSON.stringify(LIST))), {
        event: 'view_item_list',
        ecommerce: LIST.list
    });
});

test('selecting an item gives select_item with the list id, name and that one item', () => {
    const data = listData.parseList(JSON.stringify(LIST));

    assert.deepEqual(listData.selectItemEvent(data, 1), {
        event: 'select_item',
        ecommerce: {
            item_list_id: 'category_12',
            item_list_name: 'Laptops',
            items: [{item_id: 'SKU2', item_name: 'Laptop B', index: 1, price: 120}]
        }
    });
});

test('a pushed select_item does not share its item with the list it came from', () => {
    const data = listData.parseList(JSON.stringify(LIST));

    listData.selectItemEvent(data, 0).ecommerce.items[0].price = 1;
    assert.equal(data.list.items[0].price, 99.5);
});

test('a list without an id or name selects the item alone', () => {
    const data = listData.parseList(changed(LIST, {list: {items: LIST.list.items}}));

    assert.deepEqual(listData.selectItemEvent(data, 0), {event: 'select_item', ecommerce: {items: [LIST.list.items[0]]}});
});

test('selecting a position outside the list gives nothing', () => {
    const data = listData.parseList(JSON.stringify(LIST));

    for (const index of [-1, 2, 0.5, '0', null, undefined]) {
        assert.equal(listData.selectItemEvent(data, index), null, String(index));
    }
});

test('a promotions script is read into its promotions, impression flag and links', () => {
    const data = listData.parsePromotions(JSON.stringify(PROMOTIONS));

    assert.deepEqual(data.promotions, PROMOTIONS.promotions);
    assert.equal(data.impression, true);
    assert.deepEqual(data.links, new Map([['/sale.html', 0], ['/docks.html', 1]]));
});

test('a promotions script of the wrong shape reads as nothing', () => {
    const broken = {
        'not JSON': 'promotions',
        'no promotions': changed(PROMOTIONS, {promotions: undefined}),
        'empty promotions': changed(PROMOTIONS, {promotions: []}),
        'a promotion not an object': changed(PROMOTIONS, {promotions: ['summer']}),
        'impression missing': changed(PROMOTIONS, {impression: undefined}),
        'position past the end': changed(PROMOTIONS, {links: {'/sale.html': 2}}),
        'fractional position': changed(PROMOTIONS, {links: {'/sale.html': 1.5}})
    };

    for (const [name, text] of Object.entries(broken)) {
        assert.equal(listData.parsePromotions(text), null, name);
    }
});

test('promotions are impressed one view_promotion per promotion, in order', () => {
    assert.deepEqual(listData.promotionImpressions(listData.parsePromotions(JSON.stringify(PROMOTIONS))), [
        {event: 'view_promotion', ecommerce: PROMOTIONS.promotions[0]},
        {event: 'view_promotion', ecommerce: PROMOTIONS.promotions[1]}
    ]);
});

test('selecting a promotion gives select_promotion with its parameters; outside the list gives nothing', () => {
    const data = listData.parsePromotions(JSON.stringify(PROMOTIONS));

    assert.deepEqual(listData.selectPromotionEvent(data, 1), {
        event: 'select_promotion',
        ecommerce: PROMOTIONS.promotions[1]
    });
    assert.equal(listData.selectPromotionEvent(data, 2), null);
    assert.equal(listData.selectPromotionEvent(data, '1'), null);
});

test('a list says whether its clicks are reported; a script without the flag (cached before it) reports them', () => {
    assert.equal(listData.parseList(JSON.stringify(LIST)).clicks, true);
    assert.equal(listData.parseList(changed(LIST, {clicks: true})).clicks, true);
    assert.equal(listData.parseList(changed(LIST, {clicks: false})).clicks, false);
    assert.equal(listData.parseList(changed(LIST, {clicks: 'no'})), null);
    assert.equal(listData.parsePromotions(changed(PROMOTIONS, {clicks: false})).clicks, false);
    assert.equal(listData.parsePromotions(changed(PROMOTIONS, {clicks: 0})), null);
});

test('a block whose clicks are not reported gives no select event, whatever its links', () => {
    const list = listData.parseList(changed(LIST, {clicks: false}));
    const promotions = listData.parsePromotions(changed(PROMOTIONS, {clicks: false}));

    assert.equal(listData.selectItemEvent(list, 0), null);
    assert.equal(listData.selectPromotionEvent(promotions, 0), null);
    assert.equal(listData.readerOf('list').impressions(list).length, 1, 'impressions do not depend on clicks');
});

test('each kind of data script has a reader; other kinds have none', () => {
    const list = listData.readerOf('list');
    const promotions = listData.readerOf('promotions');

    assert.equal(list.parse, listData.parseList);
    assert.equal(list.select, listData.selectItemEvent);
    assert.equal(promotions.parse, listData.parsePromotions);
    assert.equal(promotions.select, listData.selectPromotionEvent);
    for (const kind of ['page', 'list-start', 'config', 'constructor', '__proto__', '', null, undefined]) {
        assert.equal(listData.readerOf(kind), null, String(kind));
    }
});

test('a reader impresses only what the server said to impress', () => {
    const list = listData.readerOf('list');
    const promotions = listData.readerOf('promotions');

    assert.equal(list.impressions(list.parse(JSON.stringify(LIST))).length, 1);
    assert.deepEqual(list.impressions(list.parse(changed(LIST, {impression: false}))), []);
    assert.equal(promotions.impressions(promotions.parse(JSON.stringify(PROMOTIONS))).length, 2);
    assert.deepEqual(promotions.impressions(promotions.parse(changed(PROMOTIONS, {impression: false}))), []);
});
