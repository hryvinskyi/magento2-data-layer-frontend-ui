/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * When the page takes the server's waiting events and how it acknowledges them, with fakes for the cookie, the
 * endpoints, the delivered ids and the clock of a navigation.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import './import-map.mjs';

const {
    FLAG_COOKIE,
    createDrainer,
    hasFlag,
    isPageRequest,
    parseDrained
} = await import('../../view/frontend/web/js/data-layer/drain.js');
const {createDeliveredIds} = await import('../../view/frontend/web/js/data-layer/delivered-ids.js');

const ADD_TO_CART = {event: 'add_to_cart', ecommerce: {currency: 'GBP', items: [{item_id: 'A', quantity: 1}]}};
const LOGIN = {event: 'login', method: 'website'};
const LEAD = {event: 'generate_lead', ecommerce: {currency: 'GBP', value: 30}};

/**
 * Let every settled promise run its callbacks
 *
 * @returns {Promise<void>}
 */
const settle = () => new Promise((resolve) => setImmediate(resolve));

/**
 * A server queue as the endpoints keep it: reading returns every entry, acknowledging removes ids and deletes the
 * cookie once nothing is left
 */
class FakeQueue {
    /**
     * @param {Object} jar The cookie jar the queue's signal writes to
     */
    constructor(jar) {
        this.jar = jar;
        this.entries = [];
        this.acknowledged = [];
    }

    /**
     * @param {string} id
     * @param {Object} event
     * @returns {void}
     */
    add(id, event) {
        this.entries.push({id, event});
        this.jar.cookie = `${FLAG_COOKIE}=1`;
    }

    /**
     * @returns {{events: Array<{id: string, event: Object}>}}
     */
    answer() {
        return {events: this.entries.map((entry) => ({...entry}))};
    }

    /**
     * @param {Array<string>} ids
     * @returns {void}
     */
    acknowledge(ids) {
        this.acknowledged.push([...ids]);
        this.entries = this.entries.filter((entry) => !ids.includes(entry.id));
        if (this.entries.length === 0) {
            this.jar.cookie = 'a=b';
        }
    }
}

/**
 * A drainer wired to fakes: requests the test settles by hand, a queue that answers them, and the tab's session
 * storage
 *
 * @param {Object} [options]
 * @param {string} [options.cookie] The starting cookie text
 * @param {Object} [options.storage] The tab's session storage, shared between pages of one tab
 * @returns {Object}
 */
function harness({cookie = `a=b; ${FLAG_COOKIE}=1`, storage = {}} = {}) {
    const jar = {cookie};
    const page = {visible: true, leaving: false};
    const queue = new FakeQueue(jar);
    const requests = [];
    const acknowledgements = [];
    const delivered = [];
    const failures = [];
    const timers = [];
    const ports = {
        isVisible: () => page.visible,
        hasFlag: () => hasFlag(jar.cookie),
        isLeaving: () => page.leaving,
        request: (signal) => new Promise((resolve, reject) => {
            requests.push({resolve, reject, signal});
        }),
        acknowledge: (ids) => new Promise((resolve, reject) => {
            acknowledgements.push({ids, resolve, reject});
        }),
        delivered: createDeliveredIds({
            read: () => storage.text ?? null,
            write: (text) => {
                storage.text = text;
            }
        }),
        deliver: (events) => delivered.push(...events),
        later: (callback) => timers.push(callback),
        failed: (reason) => failures.push(reason)
    };
    const drainer = createDrainer(ports);

    /**
     * Answer the oldest open request with what the queue holds
     *
     * @returns {void}
     */
    const answer = () => requests.shift().resolve(queue.answer());

    /**
     * Let the oldest acknowledgement reach the queue
     *
     * @returns {void}
     */
    const confirm = () => {
        const acknowledgement = acknowledgements.shift();

        queue.acknowledge(acknowledgement.ids);
        acknowledgement.resolve({acknowledged: acknowledgement.ids.length});
    };

    return {jar, page, queue, requests, acknowledgements, delivered, failures, timers, drainer, answer, confirm};
}

test('the cookie is recognised only with its own name and the value 1', () => {
    assert.equal(hasFlag(`${FLAG_COOKIE}=1`), true);
    assert.equal(hasFlag(`x=1; ${FLAG_COOKIE}=1; y=2`), true);
    assert.equal(hasFlag(`x=1;${FLAG_COOKIE} = 1 `), true);
    for (const text of ['', 'x=1', `${FLAG_COOKIE}=0`, `${FLAG_COOKIE}=`, `x${FLAG_COOKIE}=1`, `${FLAG_COOKIE}x=1`,
        FLAG_COOKIE, undefined, null, 1]) {
        assert.equal(hasFlag(text), false, String(text));
    }
});

test('without the cookie no request is made', async () => {
    const {drainer, requests} = harness({cookie: 'a=b'});

    await drainer.trigger();
    assert.equal(requests.length, 0);
});

test('the events handed over are pushed, then their ids acknowledged; the next occasion asks nothing', async () => {
    const {queue, drainer, requests, acknowledgements, delivered, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    queue.add('id-2', LOGIN);
    const done = drainer.trigger();

    assert.equal(requests.length, 1);
    answer();
    await settle();
    assert.deepEqual(delivered, [ADD_TO_CART, LOGIN]);
    assert.deepEqual(acknowledgements.map((acknowledgement) => acknowledgement.ids), [['id-1', 'id-2']]);
    confirm();
    await done;
    assert.deepEqual(queue.entries, []);

    await drainer.trigger();
    assert.equal(requests.length, 0);
});

test('an answer the page never used leaves the events queued, and the next request pushes them once', async () => {
    const {queue, drainer, requests, acknowledgements, delivered, failures, answer, confirm} = harness();

    queue.add('id-1', LEAD);
    const first = drainer.trigger();

    requests.shift().reject(new Error('the page went away'));
    await first;
    assert.deepEqual(delivered, []);
    assert.equal(acknowledgements.length, 0);
    assert.equal(failures.length, 1);
    assert.equal(queue.entries.length, 1, 'nothing was removed from the queue');

    const second = drainer.trigger();

    answer();
    await settle();
    confirm();
    await second;
    assert.deepEqual(delivered, [LEAD]);
    assert.deepEqual(queue.entries, []);
});

test('events handed over again after a lost acknowledgement are acknowledged again but not pushed twice', async () => {
    const {queue, drainer, requests, acknowledgements, delivered, failures, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    const first = drainer.trigger();

    answer();
    await settle();
    acknowledgements.shift().reject(new Error('offline'));
    await first;
    assert.deepEqual(delivered, [ADD_TO_CART]);
    assert.equal(failures.length, 1);

    queue.add('id-2', LOGIN);
    const second = drainer.trigger();

    assert.equal(requests.length, 1);
    answer();
    await settle();
    assert.deepEqual(delivered, [ADD_TO_CART, LOGIN], 'id-1 is not pushed again');
    assert.deepEqual(acknowledgements[0].ids, ['id-1', 'id-2']);
    confirm();
    await second;
    assert.deepEqual(queue.entries, []);
});

test('the next page of the tab skips the ids an earlier page pushed', async () => {
    const storage = {};
    const earlier = harness({storage});

    earlier.queue.add('id-1', LEAD);
    const first = earlier.drainer.trigger();

    earlier.answer();
    await settle();
    assert.deepEqual(earlier.delivered, [LEAD]);

    const later = harness({storage});

    later.queue.add('id-1', LEAD);
    const second = later.drainer.trigger();

    later.answer();
    await settle();
    assert.deepEqual(later.delivered, [], 'the earlier page pushed it already');
    assert.deepEqual(later.acknowledgements[0].ids, ['id-1']);
    later.confirm();
    await second;
    earlier.acknowledgements.shift().resolve();
    await first;
});

test('a page being left neither asks nor pushes; the events wait for the next page', async () => {
    const storage = {};
    const leaving = harness({storage});

    leaving.queue.add('id-1', LEAD);
    leaving.page.leaving = true;
    await leaving.drainer.trigger();
    assert.equal(leaving.requests.length, 0, 'no request starts once the navigation started');

    leaving.page.leaving = false;
    const raced = leaving.drainer.trigger();

    assert.equal(leaving.requests.length, 1);
    leaving.page.leaving = true;
    leaving.answer();
    await raced;
    assert.deepEqual(leaving.delivered, [], 'an answer that arrives once the navigation started is not used');
    assert.equal(leaving.acknowledgements.length, 0);
    assert.equal(leaving.failures.length, 0);

    const next = harness({storage});

    next.queue.add('id-1', LEAD);
    const taken = next.drainer.trigger();

    next.answer();
    await settle();
    next.confirm();
    await taken;
    assert.deepEqual(next.delivered, [LEAD], 'the next page pushes it');
});

test('a page still there after the grace period of a navigation asks again', async () => {
    const {queue, page, drainer, requests, delivered, timers, answer, confirm} = harness();

    queue.add('id-1', LEAD);
    page.leaving = true;
    await drainer.trigger();
    await drainer.trigger();
    assert.equal(timers.length, 1, 'one retry is planned, however many occasions come');

    page.leaving = false;
    timers.shift()();
    assert.equal(requests.length, 1);
    answer();
    await settle();
    confirm();
    await settle();
    assert.deepEqual(delivered, [LEAD]);
});

test('aborting the request in flight uses no answer, reports no failure and leaves the events queued', async () => {
    const {queue, drainer, requests, delivered, failures, acknowledgements} = harness();

    queue.add('id-1', LEAD);
    const pending = drainer.trigger();
    const request = requests[0];

    drainer.abort();
    assert.equal(request.signal.aborted, true);
    request.resolve(queue.answer());
    await pending;
    assert.deepEqual(delivered, []);
    assert.equal(acknowledgements.length, 0);
    assert.deepEqual(failures, []);
    assert.equal(queue.entries.length, 1);

    drainer.abort();
});

test('only one request is in flight; an occasion during it asks again once it ends, if events still wait', async () => {
    const {queue, drainer, requests, delivered, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    const first = drainer.trigger();

    void drainer.trigger();
    void drainer.trigger();
    assert.equal(requests.length, 1);

    answer();
    await settle();
    queue.add('id-2', LOGIN);
    confirm();
    await settle();
    assert.equal(requests.length, 1, 'the cookie was set again while the first request ran');
    answer();
    await settle();
    confirm();
    await first;
    assert.deepEqual(delivered.map((event) => event.event), ['add_to_cart', 'login']);
});

test('an occasion during a request asks nothing once the acknowledgement emptied the queue', async () => {
    const {queue, drainer, requests, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    const first = drainer.trigger();

    void drainer.trigger();
    answer();
    await settle();
    confirm();
    await first;
    assert.equal(requests.length, 0);
});

test('an answer of another shape is a failure and delivers nothing', async () => {
    for (const body of [null, [], {events: 'x'}, {}, 'text']) {
        const {drainer, requests, delivered, failures} = harness();
        const done = drainer.trigger();

        requests.shift().resolve(body);
        await done;
        assert.deepEqual(delivered, [], JSON.stringify(body));
        assert.equal(failures.length, 1, JSON.stringify(body));
    }
});

test('a request port that throws at once is a failure, not an exception', async () => {
    const failures = [];
    const drainer = createDrainer({
        isVisible: () => true,
        hasFlag: () => true,
        isLeaving: () => false,
        request: () => {
            throw new Error('no fetch');
        },
        acknowledge: () => assert.fail('nothing to acknowledge'),
        delivered: {has: () => false, add: () => undefined},
        deliver: () => assert.fail('nothing to deliver'),
        later: () => assert.fail('nothing to retry'),
        failed: (reason) => failures.push(reason)
    });

    await drainer.trigger();
    assert.equal(failures.length, 1);
});

test('the answer keeps only the entries with an id and an event, in order', () => {
    assert.deepEqual(parseDrained({events: [
        {id: 'a', event: ADD_TO_CART},
        ADD_TO_CART,
        {id: '', event: LOGIN},
        {id: 3, event: LOGIN},
        {id: 'b', event: {event: ''}},
        {id: 'c', event: 'login'},
        {id: 'd', event: LOGIN}
    ]}), [{id: 'a', event: ADD_TO_CART}, {id: 'd', event: LOGIN}]);
    assert.deepEqual(parseDrained({events: []}), []);
    assert.equal(parseDrained({events: {event: 'login'}}), null);
});

test('the page\'s fetches and XHRs are occasions; the module\'s own requests and other resources are not', () => {
    const own = [
        'https://shop.test/hryvinskyi_datalayer/events/drain/',
        'https://shop.test/hryvinskyi_datalayer/events/acknowledge/',
        'https://shop.test/hryvinskyi_datalayer/collect/'
    ];

    assert.equal(isPageRequest({initiatorType: 'fetch', name: 'https://shop.test/rest/V1/carts/mine'}, own), true);
    assert.equal(isPageRequest({initiatorType: 'xmlhttprequest', name: 'https://shop.test/checkout/cart/add/'}, own), true);
    assert.equal(isPageRequest({initiatorType: 'fetch', name: own[0]}, own), false);
    assert.equal(isPageRequest({initiatorType: 'fetch', name: own[1]}, own), false);
    assert.equal(isPageRequest({initiatorType: 'beacon', name: own[2]}, own), false);
    assert.equal(isPageRequest({initiatorType: 'img', name: 'https://shop.test/a.png'}, own), false);
    assert.equal(isPageRequest({initiatorType: 'fetch', name: 'https://shop.test/x'}, ['']), true);
    assert.equal(isPageRequest(null, own), false);
});

test('a hidden page never asks, even with the cookie set; it asks once it is visible again', async () => {
    const {queue, page, requests, delivered, drainer, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    page.visible = false;
    await drainer.trigger();
    await drainer.trigger();
    assert.equal(requests.length, 0);

    page.visible = true;
    const taken = drainer.trigger();

    assert.equal(requests.length, 1);
    answer();
    await settle();
    confirm();
    await taken;
    assert.deepEqual(delivered, [ADD_TO_CART]);
});

test('an occasion kept while a request was in flight is dropped when the page was hidden meanwhile', async () => {
    const {queue, page, requests, drainer, answer, confirm} = harness();

    queue.add('id-1', ADD_TO_CART);
    const first = drainer.trigger();

    void drainer.trigger();
    page.visible = false;
    answer();
    await settle();
    queue.add('id-2', LOGIN);
    confirm();
    await first;
    assert.equal(requests.length, 0);
});
