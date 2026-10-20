/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * When events meant for the server leave the page, and how they are split.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import * as batch from '../../view/frontend/web/js/data-layer/beacon-batch.js';

const PAGE = {page_location: 'https://shop.test/laptops.html', page_referrer: 'https://search.test/'};

/**
 * An event whose JSON is padded to roughly the given number of bytes
 *
 * @param {Number} number
 * @param {Number} [padding]
 * @returns {Object}
 */
const event = (number, padding = 0) => ({event: 'view_item_list', ecommerce: {item_list_id: `l${number}`, pad: 'x'.repeat(padding)}, event_id: `id-${number}`});

/**
 * A queue wired to recording fakes
 *
 * @param {number} [accepted] How many requests sent while leaving the browser accepts
 * @returns {{
 *     queue: Object,
 *     sent: Array.<Object>,
 *     timers: Array.<Object>,
 *     dropped: Array.<Number>,
 *     unsent: Array.<Number>,
 *     leaving: Array.<boolean>,
 *     goTo: function(Object): void
 * }}
 */
function harness(accepted = Infinity) {
    const sent = [];
    const timers = [];
    const dropped = [];
    const unsent = [];
    const leaving = [];
    let ids = 0;
    let page = PAGE;
    let acceptedLeft = accepted;
    const queue = batch.createBeaconQueue({
        send: (body, isLeaving) => {
            if (isLeaving && acceptedLeft <= 0) {
                return false;
            }
            acceptedLeft -= isLeaving ? 1 : 0;
            leaving.push(isLeaving);
            sent.push(JSON.parse(body));

            return true;
        },
        startTimer: (callback, delay) => {
            const timer = {callback, delay, stopped: false};

            timers.push(timer);

            return timer;
        },
        stopTimer: (timer) => {
            timer.stopped = true;
        },
        newEventId: () => `event-id-${++ids}`,
        page: () => page,
        dropped: (count) => dropped.push(count),
        unsent: (count) => unsent.push(count)
    });

    return {queue, sent, timers, dropped, unsent, leaving, goTo: (next) => {
        page = next;
    }};
}

test('a body is the events plus where the visitor is, exactly as JSON would write it', () => {
    const events = [event(1), event(2)];
    const {bodies, counts, dropped} = batch.toBodies(events, PAGE);

    assert.equal(dropped, 0);
    assert.deepEqual(bodies, [JSON.stringify({events, ...PAGE})]);
    assert.deepEqual(counts, [2]);
});

test('more than 25 events are split into requests of at most 25, in order', () => {
    const events = Array.from({length: 60}, (_, i) => event(i));
    const split = batch.toBodies(events, PAGE);
    const bodies = split.bodies.map((body) => JSON.parse(body));

    assert.deepEqual(bodies.map((body) => body.events.length), [25, 25, 10]);
    assert.deepEqual(split.counts, [25, 25, 10]);
    assert.deepEqual(bodies.flatMap((body) => body.events), events);
    bodies.forEach((body) => assert.equal(body.page_location, PAGE.page_location));
});

test('events are split so that no request passes 16 KB', () => {
    const events = Array.from({length: 6}, (_, i) => event(i, 5000));
    const {bodies} = batch.toBodies(events, PAGE);

    assert.ok(bodies.length >= 2);
    bodies.forEach((body) => assert.ok(batch.byteLength(body) <= 16384, `${batch.byteLength(body)} bytes`));
    assert.deepEqual(bodies.flatMap((body) => JSON.parse(body).events), events);
});

test('a request filled to exactly 16 KB is still sent whole', () => {
    const frame = batch.byteLength(JSON.stringify({events: [], ...PAGE}));
    const one = event(1);
    const fill = 16384 - frame - batch.byteLength(JSON.stringify(one));
    const exact = event(1, fill);
    const {bodies, dropped} = batch.toBodies([exact], PAGE);

    assert.equal(dropped, 0);
    assert.equal(batch.byteLength(bodies[0]), 16384);
    assert.equal(batch.toBodies([event(1, fill + 1)], PAGE).dropped, 1);
});

test('an event too large for a request of its own is dropped; the others still go', () => {
    const {bodies, dropped} = batch.toBodies([event(1), event(2, 20000), event(3)], PAGE);

    assert.equal(dropped, 1);
    assert.deepEqual(bodies.flatMap((body) => JSON.parse(body).events), [event(1), event(3)]);
});

test('sizes are counted in UTF-8 bytes, not characters', () => {
    assert.equal(batch.byteLength('aé€😀'), 1 + 2 + 3 + 4);
    assert.equal(batch.byteLength(''), 0);
});

test('random bytes become a lower-case hex id the collector accepts', () => {
    const id = batch.hexId(Uint8Array.from([0, 1, 15, 16, 171, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 9]));

    assert.equal(id, '00010f10abff00000000000000000009');
    assert.match(id, /^[A-Za-z0-9_-]{8,40}$/);
});

test('a queued event waits for a 2 second timer, started once, and then goes with an event id', () => {
    const {queue, sent, timers} = harness();

    queue.add({event: 'view_item', ecommerce: {}});
    queue.add({event: 'select_item', ecommerce: {}});
    assert.equal(sent.length, 0);
    assert.equal(timers.length, 1);
    assert.equal(timers[0].delay, 2000);

    timers[0].callback();
    assert.deepEqual(sent, [{
        events: [
            {event: 'view_item', ecommerce: {}, event_id: 'event-id-1'},
            {event: 'select_item', ecommerce: {}, event_id: 'event-id-2'}
        ],
        ...PAGE
    }]);
});

test('queuing does not change the caller\'s event', () => {
    const {queue} = harness();
    const original = {event: 'view_item', ecommerce: {}};

    queue.add(original);
    assert.deepEqual(original, {event: 'view_item', ecommerce: {}});
});

test('the tenth queued event sends the queue at once and stops the timer', () => {
    const {queue, sent, timers} = harness();

    for (let i = 0; i < 9; i++) {
        queue.add({event: 'view_item_list'});
    }
    assert.equal(sent.length, 0);
    queue.add({event: 'view_item_list'});
    assert.equal(sent.length, 1);
    assert.equal(sent[0].events.length, 10);
    assert.equal(timers[0].stopped, true);
});

test('a flush takes the whole queue, so a second flush right after it sends nothing', () => {
    const {queue, sent} = harness();

    queue.add({event: 'select_item'});
    queue.flush();
    queue.flush();
    assert.equal(sent.length, 1);
});

test('a flush of an empty queue sends nothing', () => {
    const {queue, sent} = harness();

    queue.flush();
    assert.equal(sent.length, 0);
});

test('after a flush the next event starts a new timer', () => {
    const {queue, timers} = harness();

    queue.add({event: 'select_item'});
    queue.flush();
    queue.add({event: 'select_item'});
    assert.equal(timers.length, 2);
    assert.equal(timers[0].stopped, true);
    assert.equal(timers[1].stopped, false);
});

/**
 * A product list event with the given number of items, each padded to about the given number of bytes
 *
 * @param {number} count
 * @param {number} padding
 * @returns {Object}
 */
const longList = (count, padding) => ({
    event: 'view_item_list',
    ecommerce: {
        item_list_id: 'category_12',
        item_list_name: 'Laptops',
        items: Array.from({length: count}, (unused, index) => ({item_id: `SKU${index}`, index, pad: 'x'.repeat(padding)}))
    }
});

test('an event that fits is kept whole; one too large is cut into runs of its items that each fit, in order', () => {
    const small = longList(3, 10);

    assert.deepEqual(batch.fitToRequest(small, PAGE), [small]);

    const large = longList(60, 600);
    const parts = batch.fitToRequest(large, PAGE);

    assert.ok(parts.length > 1);
    assert.deepEqual(parts.flatMap((part) => part.ecommerce.items), large.ecommerce.items);
    parts.forEach((part) => {
        assert.equal(part.event, 'view_item_list');
        assert.equal(part.ecommerce.item_list_id, 'category_12');
        assert.equal(part.ecommerce.item_list_name, 'Laptops');
        const withId = {...part, event_id: 'x'.repeat(batch.LIMITS.maxEventIdLength)};

        assert.equal(batch.toBodies([withId], PAGE).dropped, 0);
    });
    assert.equal(large.ecommerce.items.length, 60, 'the caller\'s event is not changed');
});

test('an event without items to cut, or with one item too large alone, cannot be made to fit', () => {
    assert.deepEqual(batch.fitToRequest({event: 'view_item_list', ecommerce: {pad: 'x'.repeat(20000)}}, PAGE), []);
    assert.deepEqual(batch.fitToRequest(longList(1, 20000), PAGE), []);
    assert.deepEqual(batch.fitToRequest(longList(3, 20000), PAGE), []);
});

test('a queued list too large for one request goes as several events, each with an id of its own', () => {
    const {queue, sent, dropped} = harness();

    queue.add(longList(60, 600));
    queue.flush();
    const events = sent.flatMap((body) => body.events);

    assert.deepEqual(dropped, []);
    assert.ok(events.length > 1);
    assert.equal(new Set(events.map((sentEvent) => sentEvent.event_id)).size, events.length);
    assert.equal(events.flatMap((sentEvent) => sentEvent.ecommerce.items).length, 60);
});

test('events that fit in no request are reported, the rest are sent', () => {
    const {queue, sent, dropped} = harness();

    queue.add({event: 'view_item_list', ecommerce: {pad: 'x'.repeat(20000)}});
    queue.add({event: 'select_item'});
    queue.flush();
    assert.deepEqual(dropped, [1]);
    assert.equal(sent.length, 1);
    assert.deepEqual(sent[0].events.map((sentEvent) => sentEvent.event), ['select_item']);
});

const LATER = {page_location: 'https://shop.test/laptops.html?page=2', page_referrer: 'https://shop.test/laptops.html'};

test('each request is framed with the page its events were queued on, not the page the visitor is on now', () => {
    const {queue, sent, goTo} = harness();

    queue.add({event: 'view_item_list'});
    goTo(LATER);
    queue.flush();

    assert.equal(sent.length, 1);
    assert.equal(sent[0].page_location, PAGE.page_location);
    assert.equal(sent[0].page_referrer, PAGE.page_referrer);
});

test('an event queued on another page first sends the earlier page\'s events, each request with its own page', () => {
    const {queue, sent, timers, goTo} = harness();

    queue.add({event: 'view_item_list'});
    queue.add({event: 'select_item'});
    goTo(LATER);
    queue.add({event: 'view_item_list'});

    assert.equal(sent.length, 1, 'the earlier page\'s events left when the address changed');
    assert.deepEqual(sent[0].events.map((sentEvent) => sentEvent.event), ['view_item_list', 'select_item']);
    assert.equal(sent[0].page_location, PAGE.page_location);
    assert.equal(timers[0].stopped, true);

    timers[1].callback();
    assert.equal(sent.length, 2);
    assert.deepEqual(sent[1].events.map((sentEvent) => sentEvent.event), ['view_item_list']);
    assert.equal(sent[1].page_location, LATER.page_location);
    assert.equal(sent[1].page_referrer, LATER.page_referrer);
});

test('events queued on pages in turn go in separate requests, in order', () => {
    const {queue, sent, goTo} = harness();

    queue.add({event: 'one'});
    goTo(LATER);
    queue.add({event: 'two'});
    goTo(PAGE);
    queue.add({event: 'three'});
    queue.flush();

    assert.deepEqual(sent.map((body) => [body.page_location, body.events.map((sentEvent) => sentEvent.event)]), [
        [PAGE.page_location, ['one']],
        [LATER.page_location, ['two']],
        [PAGE.page_location, ['three']]
    ]);
});

test('the queue is sent as soon as the queued events reach the size of a full request', () => {
    const {queue, sent, timers} = harness();

    queue.add({event: 'view_item_list', ecommerce: {pad: 'x'.repeat(9000)}});
    assert.equal(sent.length, 0);
    queue.add({event: 'view_item_list', ecommerce: {pad: 'x'.repeat(9000)}});

    assert.ok(sent.length >= 1, 'sent without waiting for the timer or the tenth event');
    assert.equal(timers[0].stopped, true);
    assert.equal(sent.flatMap((body) => body.events).length, 2);
});

test('a flush sends as a page that stays; leaving sends as a page that goes', () => {
    const {queue, leaving} = harness();

    queue.add({event: 'one'});
    queue.flush();
    queue.add({event: 'two'});
    queue.leave();

    assert.deepEqual(leaving, [false, true]);
});

test('what is left for a leaving page fits one request; refused, its events are reported once', () => {
    const {queue, sent, unsent} = harness(0);

    queue.add({event: 'one'});
    queue.add({event: 'two'});
    queue.leave();
    queue.leave();

    assert.deepEqual(sent, []);
    assert.deepEqual(unsent, [2], 'one report for every event left, and nothing left for a second leave');
});

test('a leaving page sends a long list queued just before it, split, only as far as the browser accepts', () => {
    const {queue, sent, unsent, leaving} = harness(1);

    queue.add({event: 'one'});
    queue.add(longList(60, 600));
    queue.add({event: 'last'});
    queue.leave();

    assert.ok(leaving.filter((isLeaving) => !isLeaving).length >= 2, 'the full requests left before the page did');
    assert.deepEqual(leaving.slice(-1), [true]);
    assert.equal(sent.slice(-1)[0].events.slice(-1)[0].event, 'last');
    assert.deepEqual(unsent, []);
});

test('leaving with everything accepted reports nothing', () => {
    const {queue, sent, unsent} = harness();

    queue.add({event: 'one'});
    queue.leave();

    assert.equal(sent.length, 1);
    assert.deepEqual(unsent, []);
});
