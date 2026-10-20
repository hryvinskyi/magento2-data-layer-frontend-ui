/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What gtag.js is told for each object the data layer pushes. Pure: no DOM, no globals.
 *
 * A pushed object becomes a list of gtag commands, each an array of `gtag()` arguments:
 * - `ads_conversion` (`send_to`, `value`, `currency`, `transaction_id`) becomes Google Ads' own
 *   `['event', 'conversion', {send_to, value, currency, transaction_id}]`, whatever else the store reports;
 * - any other event becomes `['event', <event>, <parameters>]` for the Google Analytics 4 stream: an ecommerce event's
 *   parameters are its `ecommerce` object (items included), any other event's are its own top-level values. Each is
 *   sent to the measurement id alone (`send_to`), so it never reaches another tag gtag.js configures. Without a
 *   measurement id such events are not sent: gtag.js is then loaded for Google Ads only;
 * - the `{ecommerce: null}` resets, objects without a GA4 event name and anything that is not an object become
 *   nothing.
 */

/**
 * A GA4 event name; also keeps Tag Manager's own `gtm.*` events out
 */
const EVENT_NAME = /^[a-z][a-z0-9_]{0,39}$/;

/**
 * The parameters Google Ads reads from a conversion
 */
const CONVERSION_PARAMETERS = Object.freeze(['send_to', 'value', 'currency', 'transaction_id']);

/**
 * @param {*} value
 * @returns {boolean} Whether a value is a plain object
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * The Google Ads conversion command
 *
 * @param {Object} object An `ads_conversion` push
 * @returns {Array<Array>}
 */
function conversion(object) {
    if (typeof object.send_to !== 'string' || object.send_to === '') {
        return [];
    }
    const parameters = {};

    CONVERSION_PARAMETERS.forEach((name) => {
        if (Object.prototype.hasOwnProperty.call(object, name)) {
            parameters[name] = object[name];
        }
    });

    return [['event', 'conversion', parameters]];
}

/**
 * The Google Analytics 4 event command
 *
 * @param {Object} object A push with a GA4 event name
 * @param {{measurementId: string}} settings
 * @returns {Array<Array>}
 */
function analyticsEvent(object, settings) {
    if (settings.measurementId === '') {
        return [];
    }
    const {event, ecommerce, ...topLevel} = object;
    const hasEcommerce = Object.prototype.hasOwnProperty.call(object, 'ecommerce');

    if (hasEcommerce && !isRecord(ecommerce)) {
        return [];
    }

    return [['event', event, {...(hasEcommerce ? ecommerce : topLevel), send_to: settings.measurementId}]];
}

/**
 * The events gtag.js sends its own way, by event name; every other event is a Google Analytics 4 event
 */
const OWN_COMMANDS = Object.freeze({
    ads_conversion: conversion
});

/**
 * Read the destination's part of the page's settings
 *
 * @param {*} config The page's settings, keyed by provider
 * @returns {{measurementId: string}} An empty measurement id when the page sends no Google Analytics 4 events
 */
export function parseGtagSettings(config) {
    const part = isRecord(config) && isRecord(config.gtag) ? config.gtag : {};
    const measurementId = typeof part.measurementId === 'string' ? part.measurementId.trim() : '';

    return Object.freeze({measurementId});
}

/**
 * Turn one pushed object into the gtag commands it stands for
 *
 * @param {*} object
 * @param {{measurementId: string}} settings As read by `parseGtagSettings`
 * @returns {Array<Array>} Each entry is the argument list of one `gtag()` call
 */
export function toGtagCommands(object, settings) {
    if (!isRecord(object) || typeof object.event !== 'string' || !EVENT_NAME.test(object.event)) {
        return [];
    }
    const own = Object.prototype.hasOwnProperty.call(OWN_COMMANDS, object.event) ? OWN_COMMANDS[object.event] : null;

    return own !== null ? own(object) : analyticsEvent(object, settings);
}
