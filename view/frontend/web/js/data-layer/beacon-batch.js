/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * When and how events meant for the server leave the page. Pure: the sending, the timer and the random ids are
 * ports the caller passes in.
 *
 * Each queued event gets an `event_id`, by which the server recognises a batch delivered twice, and keeps the page it
 * was queued on (`page_location`, `page_referrer`): a request carries the events of one page only, framed with that
 * page, so an event queued before the address changed (a script changing it without loading a page) keeps its own
 * address. The queue is sent when 10 events wait, when the queued events reach the size of a full request, 2 seconds
 * after the first of them was queued, when an event of another page is queued (the earlier page's events go first),
 * and whenever the caller flushes. A flush takes the whole queue out before sending, so a second flush right after it
 * finds nothing to send. A request carries at most 25 events and 16 KB:
 * `{"events": [...], "page_location": "...", "page_referrer": "..."}`; more is split over several requests. An event
 * too large to fit in a request on its own (a long product list) is split, when it is queued, into events of the same
 * name and parameters that each carry a run of its items, in order, and each get an `event_id` of their own; an event
 * that cannot be split to fit (it has fewer than two items, or one item alone is too large) is dropped.
 *
 * A page being hidden or left calls `leave()` instead. Browsers let the requests that outlive a page (beacons and
 * `keepalive` requests) carry about 64 KB in flight together, shared with every other script of the page, and refuse
 * one beyond that. Sending whenever a full request waits keeps what is left for that moment below one request, and
 * `leave()` sends the requests in order until the browser refuses one, then reports once how many events it could not
 * send. Nothing is retried here: delivery is fire-and-forget.
 */

export const LIMITS = Object.freeze({
    flushCount: 10,
    flushDelayMs: 2000,
    maxEvents: 25,
    maxBytes: 16384,
    maxEventIdLength: 40
});

/**
 * @param {string} text
 * @returns {number} How many bytes a string takes in UTF-8
 */
export function byteLength(text) {
    let bytes = 0;

    for (let i = 0; i < text.length; i++) {
        const code = text.charCodeAt(i);

        if (code < 0x80) {
            bytes += 1;
        } else if (code < 0x800) {
            bytes += 2;
        } else if (code >= 0xD800 && code <= 0xDBFF && i + 1 < text.length
            && text.charCodeAt(i + 1) >= 0xDC00 && text.charCodeAt(i + 1) <= 0xDFFF
        ) {
            bytes += 4;
            i++;
        } else {
            bytes += 3;
        }
    }

    return bytes;
}

/**
 * @param {*} value
 * @returns {string|null} The value as JSON, or nothing
 */
function encode(value) {
    try {
        const json = JSON.stringify(value);

        return typeof json === 'string' ? json : null;
    } catch {
        return null;
    }
}

/**
 * @param {*} value
 * @returns {boolean} Whether a value is a plain object
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * @param {{page_location: string, page_referrer: string}} page
 * @returns {{head: string, tail: string, bytes: number}} What wraps the events of one request body
 */
function frameOf(page) {
    const head = '{"events":[';
    const tail = '],' + encode({page_location: page.page_location, page_referrer: page.page_referrer}).slice(1);

    return {head, tail, bytes: byteLength(head) + byteLength(tail)};
}

/**
 * Make an event fit in one request body: the event itself, or its parts when it is too large
 *
 * The `event_id` the queue adds is counted at its longest. A part is the event with `ecommerce.items` cut down to a
 * run of the original items; the runs are as long as the limit allows and keep the items' order.
 *
 * @param {Object} event
 * @param {{page_location: string, page_referrer: string}} page
 * @returns {Array<Object>} Empty when the event cannot be made to fit
 */
export function fitToRequest(event, page) {
    const room = LIMITS.maxBytes - frameOf(page).bytes - byteLength(',"event_id":""') - LIMITS.maxEventIdLength;
    const json = encode(event);

    if (json !== null && byteLength(json) <= room) {
        return [event];
    }
    const ecommerce = isRecord(event) && isRecord(event.ecommerce) ? event.ecommerce : null;
    const items = ecommerce !== null && Array.isArray(ecommerce.items) ? ecommerce.items : [];
    const withItems = (run) => ({...event, ecommerce: {...ecommerce, items: run}});
    const bare = items.length < 2 ? null : encode(withItems([]));

    if (bare === null) {
        return [];
    }
    const parts = [];
    let run = [];
    let size = byteLength(bare);

    for (const item of items) {
        const itemJson = encode(item);
        const bytes = itemJson === null ? Infinity : byteLength(itemJson);

        if (byteLength(bare) + bytes > room) {
            return [];
        }
        if (size + bytes + (run.length > 0 ? 1 : 0) > room) {
            parts.push(withItems(run));
            run = [];
            size = byteLength(bare);
        }
        size += bytes + (run.length > 0 ? 1 : 0);
        run.push(item);
    }
    parts.push(withItems(run));

    return parts;
}

/**
 * Split events into request bodies within the count and size limits, keeping their order
 *
 * @param {Array<Object>} events
 * @param {{page_location: string, page_referrer: string}} page
 * @returns {{bodies: Array<string>, counts: Array<number>, dropped: number}} `counts` holds how many events each body
 *     carries; `dropped` counts the events that fit in no request
 */
export function toBodies(events, page) {
    const {head, tail, bytes: frame} = frameOf(page);
    const bodies = [];
    const counts = [];
    let parts = [];
    let size = frame;
    let dropped = 0;

    events.forEach((event) => {
        const json = encode(event);
        const bytes = json === null ? 0 : byteLength(json);

        if (json === null || frame + bytes > LIMITS.maxBytes) {
            dropped++;

            return;
        }
        if (parts.length === LIMITS.maxEvents || size + bytes + (parts.length > 0 ? 1 : 0) > LIMITS.maxBytes) {
            bodies.push(head + parts.join(',') + tail);
            counts.push(parts.length);
            parts = [];
            size = frame;
        }
        size += bytes + (parts.length > 0 ? 1 : 0);
        parts.push(json);
    });
    if (parts.length > 0) {
        bodies.push(head + parts.join(',') + tail);
        counts.push(parts.length);
    }

    return {bodies, counts, dropped};
}

/**
 * @param {ArrayLike<number>} bytes
 * @returns {string} Random bytes as a lower-case hexadecimal id
 */
export function hexId(bytes) {
    let id = '';

    for (let i = 0; i < bytes.length; i++) {
        id += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
    }

    return id;
}


/**
 * @param {{page_location: string, page_referrer: string}} one
 * @param {{page_location: string, page_referrer: string}} other
 * @returns {boolean} Whether two descriptions name the same page
 */
function samePage(one, other) {
    return one.page_location === other.page_location && one.page_referrer === other.page_referrer;
}

/**
 * Create the queue of events waiting to be posted
 *
 * @param {Object} ports
 * @param {function(string, boolean): boolean} ports.send Posts one request body; told whether the page is leaving, it
 *     answers whether the browser accepted the request
 * @param {function(Function, number): *} ports.startTimer Calls back once after a delay; returns a handle
 * @param {function(*): void} ports.stopTimer Cancels a timer by its handle
 * @param {function(): string} ports.newEventId A random id for one event
 * @param {function(): {page_location: string, page_referrer: string}} ports.page Where the visitor is now
 * @param {function(number): void} ports.dropped Told how many events fit in no request, even split
 * @param {function(number): void} ports.unsent Told, once per leave, how many events a leaving page could not send
 * @returns {{add: function(Object): void, flush: function(): void, leave: function(): void}}
 */
export function createBeaconQueue(ports) {
    let queued = [];
    let queuedBytes = 0;
    let timer = null;

    /**
     * Take every queued event out of the queue
     *
     * @returns {Array<{event: Object, page: Object}>}
     */
    function take() {
        if (timer !== null) {
            ports.stopTimer(timer);
            timer = null;
        }
        const taken = queued;

        queued = [];
        queuedBytes = 0;

        return taken;
    }

    /**
     * Turn queued events into request bodies, one page's run of events at a time, in order
     *
     * @param {Array<{event: Object, page: Object}>} taken
     * @returns {Array<{body: string, count: number}>}
     */
    function requestsOf(taken) {
        const requests = [];
        let dropped = 0;

        for (let first = 0; first < taken.length;) {
            let end = first + 1;

            while (end < taken.length && samePage(taken[end].page, taken[first].page)) {
                end++;
            }
            const batch = toBodies(taken.slice(first, end).map((entry) => entry.event), taken[first].page);

            dropped += batch.dropped;
            batch.bodies.forEach((body, index) => requests.push({body, count: batch.counts[index]}));
            first = end;
        }
        if (dropped > 0) {
            ports.dropped(dropped);
        }

        return requests;
    }

    /**
     * Send everything queued
     *
     * @returns {void}
     */
    function flush() {
        requestsOf(take()).forEach((request) => ports.send(request.body, false));
    }

    /**
     * Send everything queued while the browser accepts requests that outlive the page, in order, and report the rest
     * once
     *
     * @returns {void}
     */
    function leave() {
        let unsent = 0;

        requestsOf(take()).forEach((request) => {
            if (unsent === 0 && ports.send(request.body, true)) {
                return;
            }
            unsent += request.count;
        });
        if (unsent > 0) {
            ports.unsent(unsent);
        }
    }

    /**
     * Queue an event, split into parts when it is too large for one request, with the page it was queued on
     *
     * @param {Object} event
     * @returns {void}
     */
    function add(event) {
        const page = ports.page();
        const parts = fitToRequest(event, page);

        if (parts.length === 0) {
            ports.dropped(1);

            return;
        }
        if (queued.length > 0 && !samePage(queued[queued.length - 1].page, page)) {
            flush();
        }
        parts.forEach((part) => {
            const queuedEvent = {...part, event_id: ports.newEventId()};
            const json = encode(queuedEvent);

            queued.push({event: queuedEvent, page});
            queuedBytes += json === null ? 0 : byteLength(json);
        });
        if (queued.length >= LIMITS.flushCount || queuedBytes >= LIMITS.maxBytes) {
            flush();
        } else if (timer === null) {
            timer = ports.startTimer(flush, LIMITS.flushDelayMs);
        }
    }

    return Object.freeze({add, flush, leave});
}
