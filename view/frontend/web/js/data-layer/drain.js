/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * When the page takes the events the server detected (a product added to the cart, a sign-in) from the drain
 * endpoint, and how it confirms them. Pure: reading the cookie, the requests, the delivered ids and the delivery are
 * ports the caller passes in.
 *
 * The server sets the cookie `hdl_events=1` on a response when events wait for the browser, and deletes it once
 * nothing waits. So a page asks only while the cookie is there: no request without it, and no polling. Only a page
 * the shopper can see asks: the cookie is shared by every tab of the store, so every open tab learns of the same
 * events, and the one being looked at takes them; a hidden tab asks once it is shown again. One request is in flight
 * at a time; an occasion that comes while one is in flight is kept and acted on once it ends.
 *
 * Delivery is at least once, in two steps. The endpoint hands the events over with their ids and keeps them queued;
 * the page pushes each event whose id it has not delivered before, then acknowledges every id it was handed, and only
 * that removes them from the queue. Whatever goes wrong in between loses nothing: a failed request, an answer the page
 * never used, or a lost acknowledgement leaves the events queued, and the next request hands them over again (an id
 * already delivered is acknowledged again but not pushed twice). So a page that is being left uses no answer: once a
 * navigation away has started, the page neither asks nor pushes, and the events wait for the next page. If the page is
 * still there after a grace period (the navigation was abandoned: a download, a refused form), it asks again. A
 * request still in flight when the page is hidden for good is aborted; its events stay queued.
 */
import {parseEvents} from '@hryvinskyi/data-layer/push-sequence.js';

export const FLAG_COOKIE = 'hdl_events';

/**
 * The browser's own requests that may have queued server events: the page's fetches and XHRs
 */
const REQUEST_TYPES = Object.freeze({fetch: true, xmlhttprequest: true});

/**
 * @param {*} value
 * @returns {boolean} Whether a value is an object (not an array, not null)
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * @param {*} cookieText `document.cookie`
 * @returns {boolean} Whether the cookies say that events wait
 */
export function hasFlag(cookieText) {
    if (typeof cookieText !== 'string') {
        return false;
    }

    return cookieText.split(';').some((pair) => {
        const separator = pair.indexOf('=');

        return separator > -1 && pair.slice(0, separator).trim() === FLAG_COOKIE
            && pair.slice(separator + 1).trim() === '1';
    });
}

/**
 * Whether a finished resource request is one of the page's own fetches or XHRs, not the module's own requests
 *
 * @param {{initiatorType: string, name: string}} entry A resource timing entry
 * @param {Array<string>} ownUrls The addresses the module itself posts to
 * @returns {boolean}
 */
export function isPageRequest(entry, ownUrls) {
    return entry !== null && typeof entry === 'object' && REQUEST_TYPES[entry.initiatorType] === true
        && typeof entry.name === 'string'
        && !ownUrls.some((url) => url !== '' && entry.name.startsWith(url));
}

/**
 * Read the endpoint's answer: `{"events": [{"id": "…", "event": {data-layer object}}]}`
 *
 * @param {*} body The decoded JSON answer
 * @returns {Array<{id: string, event: Object}>|null} The entries with an id and an event, in order; null for an
 *     answer of another shape
 */
export function parseDrained(body) {
    if (!isRecord(body) || !Array.isArray(body.events)) {
        return null;
    }

    return body.events.filter((entry) => isRecord(entry) && typeof entry.id === 'string' && entry.id !== ''
        && parseEvents([entry.event]).length === 1);
}

/**
 * Create the drainer
 *
 * @param {Object} ports
 * @param {function(): boolean} ports.isVisible Whether the shopper can see the page
 * @param {function(): boolean} ports.hasFlag Whether the cookie says that events wait
 * @param {function(): boolean} ports.isLeaving Whether a navigation away from the page has started
 * @param {function(AbortSignal): Promise<*>} ports.request Posts to the drain endpoint, aborted by the signal, and
 *     resolves with the decoded answer
 * @param {function(Array<string>): Promise<*>} ports.acknowledge Posts the ids of the events handed over
 * @param {{has: function(string): boolean, add: function(string): void}} ports.delivered The ids already pushed
 * @param {function(Array<Object>): void} ports.deliver Pushes the events not delivered before
 * @param {function(function(): void): void} ports.later Calls back once the grace period of a navigation is over
 * @param {function(string): void} ports.failed Told why a request gave nothing
 * @returns {{trigger: function(): Promise<void>, abort: function(): void}} `trigger` resolves once its requests (if
 *     any) are done; `abort` aborts the request in flight, whose events then stay queued
 */
export function createDrainer(ports) {
    let inFlight = null;
    let controller = null;
    let again = false;
    let waitingOutNavigation = false;

    /**
     * Ask again once a navigation that has started had the time to take the page away
     *
     * @returns {void}
     */
    function retryAfterNavigation() {
        if (waitingOutNavigation) {
            return;
        }
        waitingOutNavigation = true;
        ports.later(() => {
            waitingOutNavigation = false;
            void trigger();
        });
    }

    /**
     * Push the events not delivered before, then acknowledge every id handed over
     *
     * @param {Array<{id: string, event: Object}>} entries
     * @returns {Promise<void>|undefined}
     */
    function deliver(entries) {
        if (ports.isLeaving()) {
            retryAfterNavigation();

            return undefined;
        }
        const fresh = entries.filter((entry) => !ports.delivered.has(entry.id));

        fresh.forEach((entry) => ports.delivered.add(entry.id));
        if (fresh.length > 0) {
            ports.deliver(fresh.map((entry) => entry.event));
        }
        if (entries.length === 0) {
            return undefined;
        }

        return Promise.resolve()
            .then(() => ports.acknowledge(entries.map((entry) => entry.id)))
            .then(() => undefined, () => {
                ports.failed('the server events pushed could not be acknowledged.');
            });
    }

    /**
     * Take the waiting events, if the page is visible, stays, and the cookie says there are any
     *
     * @returns {Promise<void>}
     */
    function trigger() {
        if (inFlight !== null) {
            again = true;

            return inFlight;
        }
        if (!ports.isVisible() || !ports.hasFlag()) {
            return Promise.resolve();
        }
        if (ports.isLeaving()) {
            retryAfterNavigation();

            return Promise.resolve();
        }
        const current = new AbortController();
        let request;

        controller = current;
        try {
            request = Promise.resolve(ports.request(current.signal));
        } catch (error) {
            request = Promise.reject(error);
        }
        inFlight = request.then((body) => {
            if (current.signal.aborted) {
                return undefined;
            }
            const entries = parseDrained(body);

            if (entries === null) {
                throw new TypeError('An answer without a list of events.');
            }

            return deliver(entries);
        }).catch(() => {
            if (!current.signal.aborted) {
                ports.failed('the waiting server events could not be taken.');
            }
        }).then(() => {
            inFlight = null;
            controller = null;
            if (again) {
                again = false;

                return trigger();
            }

            return undefined;
        });

        return inFlight;
    }

    /**
     * Abort the request in flight, if any: its answer is not used and its events stay queued
     *
     * @returns {void}
     */
    function abort() {
        again = false;
        if (controller !== null) {
            controller.abort();
        }
    }

    return Object.freeze({trigger, abort});
}
