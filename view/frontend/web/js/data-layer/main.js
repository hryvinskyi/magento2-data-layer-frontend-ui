/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The page side of the GA4 data layer: binds the page, the cookie and the network to the pure modules beside it
 * and decides nothing itself. The entry point: it exports nothing.
 *
 * Loaded as an external ES module from `<head>`, it runs once the document is parsed, before `DOMContentLoaded`, and
 * at once
 * - reads its settings from the script with the fixed id the page renders once, in `<head>` (a copy injected into
 *   the content is never read; HTML cached before the settings moved to `<head>` carries them at the end of the
 *   page, so without one in `<head>` the last one in the page is read);
 * - pushes the page's events (`script[data-hryvinskyi-datalayer="page"]`, in document order), an event with an
 *   `ecommerce` object right after the `{ecommerce: null}` that resets the previous one.
 * Order guarantee: the page's events are in `dataLayer` before any `DOMContentLoaded` handler of the page runs, so a
 * tag fired by an event the page pushes from such a handler reads their `ecommerce` object. Only a module run while
 * the page is still being parsed (something loaded it out of the page's order) cannot see the page's events yet and
 * pushes them at `DOMContentLoaded` instead.
 *
 * The work that needs the page settled waits until the page's own `DOMContentLoaded` handlers ran (or starts at
 * once, when the module runs after the page has loaded):
 * - it mounts every list and promotions data script, now and whenever one is added later (lists replaced through AJAX
 *   bring their own script), impressing it once its scope has a box on the page (a list hidden by the theme waits
 *   until it is shown) or, with the `visible` trigger, once its scope is first seen;
 * - reports a click on a listed link as `select_item` / `select_promotion`, preferring, of the lists whose scope holds
 *   the link, the one closest to it (a list inside another list's block wins), because the same product can sit in
 *   two lists;
 * - takes the events the server detected from the drain endpoint whenever the `hdl_events` cookie says some wait
 *   and the page is visible and not being left, pushes those this tab has not pushed before (the ids it pushed are
 *   kept in its session storage) and acknowledges them, so the server removes them only once they reached a page;
 * - loads the plugins other modules registered, after its own first pushes, from its own origin only, and keeps the
 *   pushes made so far for their replaying hooks until every plugin has started (an `async` one included), or for 10
 *   seconds at most.
 * Events that originate in the browser go to `dataLayer`, to the server's collector, or both, by their channel;
 * events detected on the server are always pushed, as the server already routed them. Events for the collector are
 * posted as the page they were queued on; when the page is hidden or left, what is still queued goes as a beacon as
 * far as the browser accepts one. Every push dispatches
 * `hryvinskyi-datalayer:push` on `document` with the pushed object as `detail`.
 *
 * `window.dataLayer` is the one global it touches: the tag manager's own documented array.
 */
import {parseEventsText, toPushes} from '@hryvinskyi/data-layer/push-sequence.js';
import {readerOf} from '@hryvinskyi/data-layer/list-data.js';
import {pathOf} from '@hryvinskyi/data-layer/url-path.js';
import {closestList, scopeOf} from '@hryvinskyi/data-layer/list-scope.js';
import {hasRenderedBox} from '@hryvinskyi/data-layer/rendered-box.js';
import {parseSettings, routeOf, sendsToServer} from '@hryvinskyi/data-layer/settings.js';
import {FLAG_COOKIE, createDrainer, hasFlag, isPageRequest} from '@hryvinskyi/data-layer/drain.js';
import {createDeliveredIds} from '@hryvinskyi/data-layer/delivered-ids.js';
import {createPluginHost, loadPlugins, pluginsOnOrigin} from '@hryvinskyi/data-layer/plugins.js';

const ATTRIBUTE = 'data-hryvinskyi-datalayer';
const CONFIG_ID = 'hryvinskyi-datalayer-config';
const CONFIG_BY_ID = 'script[type="application/json"][id="' + CONFIG_ID + '"]';
const DATA_SCRIPTS = 'script[' + ATTRIBUTE + ']';
const LIST_START = 'script[' + ATTRIBUTE + '="list-start"]';
const PAIR_ATTRIBUTE = 'data-hryvinskyi-datalayer-list';
const PUSH_EVENT = 'hryvinskyi-datalayer:push';
const REQUEST_TIMEOUT_MS = 10000;
const PLUGIN_START_LIMIT_MS = 10000;
const LEAVE_GRACE_MS = 10000;
const DELIVERED_KEY = 'hryvinskyi-datalayer:delivered';

const warned = new Set();
let started = false;

/**
 * Tell the developer, once per reason, that something could not be used
 *
 * @param {string} reason
 * @returns {void}
 */
function warn(reason) {
    if (warned.has(reason) || typeof console === 'undefined' || typeof console.warn !== 'function') {
        return;
    }
    warned.add(reason);
    console.warn('Hryvinskyi_DataLayerFrontendUi: ' + reason);
}

/**
 * @returns {AbortSignal|undefined} A deadline for one request, where the browser has one
 */
function deadline() {
    return typeof AbortSignal === 'function' && typeof AbortSignal.timeout === 'function'
        ? AbortSignal.timeout(REQUEST_TIMEOUT_MS)
        : undefined;
}

/**
 * @returns {boolean} Whether the shopper can see the page
 */
function isVisible() {
    return document.visibilityState !== 'hidden';
}

/**
 * The page's settings script: the one with the fixed id in `<head>`, where only the page itself prints; else (HTML
 * cached before the settings moved to `<head>`) the last one with the id, else (cached before the id was printed) the
 * last settings script
 *
 * @returns {Element|null}
 */
function configScript() {
    const inHead = document.head ? document.head.querySelector(CONFIG_BY_ID) : null;

    if (inHead !== null) {
        return inHead;
    }
    const byId = document.querySelectorAll(CONFIG_BY_ID);
    const scripts = byId.length > 0 ? byId : document.querySelectorAll('script[' + ATTRIBUTE + '="config"]');

    return scripts.length > 0 ? scripts[scripts.length - 1] : null;
}

/**
 * The elements a data script's list occupies
 *
 * @param {HTMLScriptElement} script
 * @returns {Array<Element>}
 */
function scopeElements(script) {
    const parent = script.parentElement;
    const siblings = [];

    if (parent === null) {
        return [];
    }
    for (let element = parent.firstElementChild; element !== null; element = element.nextElementSibling) {
        siblings.push(element);
        if (element === script) {
            break;
        }
    }
    const positions = scopeOf(siblings.map((element) => {
        const isStart = element.matches(LIST_START);

        return {
            tagName: element.tagName,
            isStart,
            isDataScript: !isStart && element.matches(DATA_SCRIPTS),
            pair: element.getAttribute(PAIR_ATTRIBUTE)
        };
    }), siblings.length - 1);

    return positions.length === 0 ? [parent] : positions.map((position) => siblings[position]);
}

/**
 * @param {Array<Element>} elements
 * @returns {boolean} Whether any of the elements has a box with an area on the page
 */
function isRendered(elements) {
    return hasRenderedBox(elements.map((element) => element.getBoundingClientRect()));
}

/**
 * @param {Node} one
 * @param {Node} other
 * @returns {boolean} Whether one node comes before the other in the page
 */
function precedes(one, other) {
    return (one.compareDocumentPosition(other) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

/**
 * @param {Node} ancestor
 * @param {Node} node
 * @returns {boolean} Whether a node is the other or inside it
 */
function contains(ancestor, node) {
    return ancestor.contains(node);
}

/**
 * @param {Array<{script: HTMLScriptElement}>} blocks
 * @returns {{script: HTMLScriptElement}} Of several data scripts, the one that comes first in the page
 */
function firstInPage(blocks) {
    return blocks.reduce((first, block) => (precedes(block.script, first.script) ? block : first));
}

/**
 * Create the channel to the server's collector: events queue at once and are posted once the batching module has
 * loaded, which happens only on a page that sends events to the server
 *
 * @param {string} collectUrl
 * @returns {{add: function(Object): void}}
 */
function createServerChannel(collectUrl) {
    let queue = null;
    const waiting = [];

    /**
     * Post one request body as a beacon. When the browser refuses the beacon (its requests that outlive the page are
     * full), a page that stays posts an ordinary request instead; a leaving page cannot, as a request that outlives it
     * meets the same limit. Without beacons at all, a request that outlives the page is posted.
     * Fire-and-forget: the server side owns retries.
     *
     * @param {string} body
     * @param {boolean} leaving Whether the page is being hidden or left
     * @returns {boolean} Whether the request was handed to the browser
     */
    function post(body, leaving) {
        const beacons = typeof navigator.sendBeacon === 'function';

        if (beacons) {
            try {
                if (navigator.sendBeacon(collectUrl, new Blob([body], {type: 'application/json'}))) {
                    return true;
                }
            } catch {
                // a refused beacon is answered below like one the browser returned false for
            }
            if (leaving) {
                return false;
            }
        }
        fetch(collectUrl, {
            method: 'POST',
            body,
            headers: {'Content-Type': 'application/json'},
            keepalive: leaving,
            credentials: 'same-origin'
        }).catch(() => warn('a batch of events could not be posted to the server.'));

        return true;
    }

    /**
     * @param {function(Uint8Array): string} hexId Writes random bytes as an id
     * @returns {string} A random id for an event posted to the server
     */
    function newEventId(hexId) {
        if (typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }

        return hexId(crypto.getRandomValues(new Uint8Array(16)));
    }

    import('@hryvinskyi/data-layer/beacon-batch.js').then((batch) => {
        queue = batch.createBeaconQueue({
            send: post,
            startTimer: (callback, delay) => setTimeout(callback, delay),
            stopTimer: (handle) => clearTimeout(handle),
            newEventId: () => newEventId(batch.hexId),
            page: () => ({page_location: location.href, page_referrer: document.referrer}),
            dropped: () => warn('an event too large to post, even split, was not sent to the server.'),
            unsent: () => warn('events still queued when the page was hidden or left could not be sent to the server.')
        });
        document.addEventListener('visibilitychange', () => {
            if (!isVisible()) {
                queue.leave();
            }
        });
        addEventListener('pagehide', queue.leave);
        waiting.splice(0).forEach(queue.add);
    }, () => warn('events for the server could not be prepared.'));

    return Object.freeze({
        add: (event) => {
            if (queue === null) {
                waiting.push(event);
            } else {
                queue.add(event);
            }
        }
    });
}

/**
 * Whether this browser can post events to the server
 *
 * @returns {boolean}
 */
function canPostEvents() {
    return typeof crypto === 'object' && crypto !== null && typeof crypto.getRandomValues === 'function'
        && typeof Blob === 'function' && typeof fetch === 'function';
}

/**
 * Remember, for this tab, the ids of the server events it pushed: in its session storage, where the browser keeps one,
 * so the next page of the tab knows them; in memory only when that storage is missing or refuses
 *
 * @returns {{has: function(string): boolean, add: function(string): void}}
 */
function deliveredIds() {
    return createDeliveredIds({
        read: () => {
            try {
                return sessionStorage.getItem(DELIVERED_KEY);
            } catch {
                return null;
            }
        },
        write: (text) => {
            try {
                sessionStorage.setItem(DELIVERED_KEY, text);
            } catch {
                // a storage that refuses (private mode, quota) leaves the ids in memory, which still covers this page
            }
        }
    });
}

/**
 * Watch for a navigation away from the page: through the navigation API where the browser has one (a navigation to
 * another document that is not a download), else through `beforeunload`. A page shown again from the back/forward
 * cache is no longer being left, and nor is one still there once the grace period is over.
 *
 * @returns {function(): boolean} Whether a navigation away has started within the grace period
 */
function watchLeaving() {
    let since = null;
    const mark = () => {
        since = Date.now();
    };

    if (typeof navigation === 'object' && navigation !== null && typeof navigation.addEventListener === 'function') {
        navigation.addEventListener('navigate', (event) => {
            if (event.destination.sameDocument !== true && event.downloadRequest === null) {
                mark();
            }
        });
    } else {
        addEventListener('beforeunload', mark);
    }
    addEventListener('pageshow', () => {
        since = null;
    });

    return () => since !== null && Date.now() - since < LEAVE_GRACE_MS;
}

/**
 * Post to one of the module's own endpoints of the server event queue
 *
 * @param {string} url
 * @param {Object} init What the request adds to a same-origin JSON POST
 * @returns {Promise<Response>} Rejects for an answer that is not a success
 */
function postToQueue(url, init) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {Accept: 'application/json', 'Content-Type': 'application/json'},
        ...init
    }).then((response) => {
        if (!response.ok) {
            throw new Error('The endpoint answered ' + response.status + '.');
        }

        return response;
    });
}

/**
 * Combine a signal with the request deadline, where the browser can
 *
 * @param {AbortSignal} signal
 * @returns {AbortSignal}
 */
function withDeadline(signal) {
    const limit = deadline();

    return limit !== undefined && typeof AbortSignal.any === 'function' ? AbortSignal.any([signal, limit]) : signal;
}

/**
 * Take waiting server events now, and whenever they may have appeared: the cookie changed (where the browser
 * reports cookie changes), else a request of the page ended; and when the page is shown again. A request in flight
 * when the page is hidden for good is aborted.
 *
 * @param {{drainUrl: string, acknowledgeUrl: string, collectUrl: string}} settings
 * @param {function(Object): void} push
 * @returns {void}
 */
function startDrain(settings, push) {
    if (settings.drainUrl === '' || typeof fetch !== 'function' || typeof AbortController !== 'function') {
        return;
    }
    const drainer = createDrainer({
        isVisible,
        hasFlag: () => hasFlag(document.cookie),
        isLeaving: watchLeaving(),
        request: (signal) => postToQueue(settings.drainUrl, {signal: withDeadline(signal)})
            .then((response) => response.json()),
        acknowledge: (ids) => (settings.acknowledgeUrl === ''
            ? Promise.resolve()
            : postToQueue(settings.acknowledgeUrl, {body: JSON.stringify({ids}), keepalive: true, signal: deadline()})),
        delivered: deliveredIds(),
        deliver: (events) => events.forEach(push),
        later: (callback) => setTimeout(callback, LEAVE_GRACE_MS),
        failed: warn
    });

    void drainer.trigger();
    if (typeof cookieStore === 'object' && cookieStore !== null && typeof cookieStore.addEventListener === 'function') {
        cookieStore.addEventListener('change', (event) => {
            if (event.changed.some((cookie) => cookie.name === FLAG_COOKIE)) {
                void drainer.trigger();
            }
        });
    } else if (typeof PerformanceObserver === 'function'
        && (PerformanceObserver.supportedEntryTypes || []).includes('resource')
    ) {
        const ownUrls = [settings.drainUrl, settings.acknowledgeUrl, settings.collectUrl];

        new PerformanceObserver((list) => {
            if (list.getEntries().some((entry) => isPageRequest(entry, ownUrls))) {
                void drainer.trigger();
            }
        }).observe({type: 'resource'});
    }
    addEventListener('pageshow', (event) => {
        if (event.persisted) {
            void drainer.trigger();
        }
    });
    addEventListener('pagehide', drainer.abort);
    document.addEventListener('visibilitychange', () => {
        if (isVisible()) {
            void drainer.trigger();
        }
    });
}

/**
 * Start the data layer on this page: push the page's events now, and hand back what starts the rest
 *
 * @returns {(function(): void)|null} Mounts the lists, reports clicks, takes the server's events and loads the
 *     plugins; null when the page carries no settings or the data layer started already
 */
function start() {
    const config = started ? null : configScript();

    if (config === null) {
        return null;
    }
    started = true;
    const settings = parseSettings(config.textContent);
    const dataLayer = window.dataLayer = window.dataLayer || [];
    const host = createPluginHost({
        config: settings.config,
        commit: (object) => {
            toPushes([object]).forEach((entry) => dataLayer.push(entry));
            document.dispatchEvent(new CustomEvent(PUSH_EVENT, {detail: object}));
        },
        warn
    });
    const server = sendsToServer(settings) && canPostEvents() ? createServerChannel(settings.collectUrl) : null;
    const mounted = new WeakSet();
    const watching = [];
    let blocks = [];

    /**
     * Send an event that originated in the browser where its channel says
     *
     * @param {Object} event
     * @returns {void}
     */
    function emit(event) {
        const route = routeOf(settings, event.event, server !== null);

        if (route.browser) {
            host.push(event);
        }
        if (route.server) {
            server.add(event);
        }
    }

    /**
     * Impress a list the first time any element of its scope with a box enters the viewport
     *
     * @param {HTMLScriptElement} script
     * @param {Array<Object>} impressions
     * @returns {void}
     */
    function impressWhenVisible(script, impressions) {
        const observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting && hasRenderedBox([entry.boundingClientRect]))) {
                return;
            }
            observer.disconnect();
            impressions.forEach(emit);
        });

        scopeElements(script).forEach((element) => observer.observe(element));
        watching.push({script, observer});
    }

    /**
     * Impress a list now when its scope has a box on the page, else once it gets one: watched for changes of size
     * where the browser reports them, else for entering the viewport
     *
     * @param {HTMLScriptElement} script
     * @param {Array<Object>} impressions
     * @returns {void}
     */
    function impressWhenRendered(script, impressions) {
        const elements = scopeElements(script);

        if (isRendered(elements)) {
            impressions.forEach(emit);

            return;
        }
        if (typeof ResizeObserver !== 'function') {
            if (typeof IntersectionObserver === 'function') {
                impressWhenVisible(script, impressions);
            }

            return;
        }
        const observer = new ResizeObserver(() => {
            if (!isRendered(elements)) {
                return;
            }
            observer.disconnect();
            impressions.forEach(emit);
        });

        elements.forEach((element) => observer.observe(element));
        watching.push({script, observer});
    }

    /**
     * Mount a data script once: read it, remember it for clicks, impress it
     *
     * @param {HTMLScriptElement} script
     * @returns {void}
     */
    function mount(script) {
        if (mounted.has(script)) {
            return;
        }
        mounted.add(script);
        const reader = readerOf(script.getAttribute(ATTRIBUTE));

        if (reader === null) {
            return;
        }
        const data = reader.parse(script.textContent);

        if (data === null) {
            warn('a list data script could not be read.');

            return;
        }
        blocks.push({script, reader, data});
        const impressions = reader.impressions(data);

        if (impressions.length === 0) {
            return;
        }
        if (settings.impressionTrigger === 'visible' && typeof IntersectionObserver === 'function') {
            impressWhenVisible(script, impressions);
        } else {
            impressWhenRendered(script, impressions);
        }
    }

    /**
     * Mount the data scripts among nodes added to the page, and stop watching lists that left it unseen
     *
     * @param {Array<MutationRecord>} records
     * @returns {void}
     */
    function mountAdded(records) {
        let removed = false;

        records.forEach((record) => {
            removed = removed || record.removedNodes.length > 0;
            record.addedNodes.forEach((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }
                if (node.matches(DATA_SCRIPTS)) {
                    mount(node);
                } else if (node.firstElementChild !== null) {
                    node.querySelectorAll(DATA_SCRIPTS).forEach(mount);
                }
            });
        });
        for (let i = watching.length - 1; removed && i >= 0; i--) {
            if (!watching[i].script.isConnected) {
                watching[i].observer.disconnect();
                watching.splice(i, 1);
            }
        }
    }

    /**
     * Report a click on a listed link
     *
     * @param {MouseEvent} event
     * @returns {void}
     */
    function reportSelection(event) {
        const target = event.target;
        const anchor = target !== null && typeof target.closest === 'function' ? target.closest('a[href]') : null;
        const path = anchor === null ? null : pathOf(anchor.getAttribute('href'), document.baseURI);

        if (path === null) {
            return;
        }
        blocks = blocks.filter((block) => block.script.isConnected);
        const candidates = blocks.filter((block) => block.data.links.has(path));

        if (candidates.length === 0) {
            return;
        }
        const holding = [];

        candidates.forEach((block) => {
            const holder = scopeElements(block.script).find((element) => element.contains(anchor));

            if (holder !== undefined) {
                holding.push({list: block, holder, script: block.script});
            }
        });
        const chosen = holding.length > 0 ? closestList(holding, contains, precedes) : firstInPage(candidates);
        const selection = chosen.reader.select(chosen.data, chosen.data.links.get(path));

        if (selection !== null) {
            emit(selection);
        }
    }

    document.querySelectorAll('script[' + ATTRIBUTE + '="page"]').forEach((script) => {
        const events = parseEventsText(script.textContent);

        if (events === null) {
            warn('the page events could not be read.');

            return;
        }
        events.forEach(emit);
    });

    return () => {
        document.querySelectorAll(DATA_SCRIPTS).forEach(mount);
        new MutationObserver(mountAdded).observe(document.body, {childList: true, subtree: true});
        document.addEventListener('click', reportSelection, {capture: true, passive: true});
        startDrain(settings, host.push);
        const plugins = pluginsOnOrigin(settings.plugins, new URL(import.meta.url).origin);

        plugins.refused.forEach((url) => warn('the data layer plugin ' + url + ' is not on the store\'s own origin.'));
        void loadPlugins(
            plugins.accepted,
            (url) => import(url),
            host.run,
            warn,
            () => new Promise((resolve) => setTimeout(resolve, PLUGIN_START_LIMIT_MS))
        ).then(host.release);
    };
}

/**
 * Call back once the page's own `DOMContentLoaded` handlers ran: at once when the page has loaded, else on
 * `DOMContentLoaded`, or on `load` when the module ran after `DOMContentLoaded` had fired
 *
 * @param {function(): void} callback
 * @returns {void}
 */
function afterContentLoaded(callback) {
    if (document.readyState === 'complete') {
        callback();

        return;
    }
    let called = false;
    const once = () => {
        if (!called) {
            called = true;
            callback();
        }
    };

    document.addEventListener('DOMContentLoaded', once, {once: true});
    addEventListener('load', once, {once: true});
}

if (document.readyState === 'loading') {
    afterContentLoaded(() => {
        const startPage = start();

        if (startPage !== null) {
            startPage();
        }
    });
} else {
    const startPage = start();

    if (startPage !== null) {
        afterContentLoaded(startPage);
    }
}
