/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which objects reach `dataLayer`, and in which order. Pure: no DOM, no globals.
 *
 * Events arrive as untrusted JSON (the page's event script, the drain endpoint's answer), so they are read as
 * unknown values: only plain objects carrying a non-empty string `event` are events. Every object with an `ecommerce`
 * key is preceded by `{ecommerce: null}`, which clears the ecommerce object the previous event left in the tag
 * manager's data model.
 */

/**
 * @param {*} value
 * @returns {boolean} Whether a decoded JSON value is an object (not an array, not null)
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * @param {*} value
 * @returns {boolean} Whether a value is a data-layer event
 */
function isEvent(value) {
    return isRecord(value) && typeof value.event === 'string' && value.event !== '';
}

/**
 * Read a list of events, keeping only the entries that are events
 *
 * @param {*} value A decoded JSON value
 * @returns {Array<Object>|null} Null when the value is not a list at all
 */
export function parseEvents(value) {
    return Array.isArray(value) ? value.filter(isEvent) : null;
}

/**
 * Read the JSON text of a page's event script
 *
 * @param {*} text
 * @returns {Array<Object>|null} Null for text that is not a JSON list
 */
export function parseEventsText(text) {
    if (typeof text !== 'string') {
        return null;
    }
    try {
        return parseEvents(JSON.parse(text));
    } catch {
        return null;
    }
}

/**
 * Turn objects into what is pushed, in order, each ecommerce object preceded by a reset
 *
 * @param {*} objects
 * @returns {Array<Object>}
 */
export function toPushes(objects) {
    const pushes = [];

    if (!Array.isArray(objects)) {
        return pushes;
    }
    objects.forEach((object) => {
        if (!isRecord(object)) {
            return;
        }
        if (Object.prototype.hasOwnProperty.call(object, 'ecommerce')) {
            pushes.push({ecommerce: null});
        }
        pushes.push(object);
    });

    return pushes;
}
