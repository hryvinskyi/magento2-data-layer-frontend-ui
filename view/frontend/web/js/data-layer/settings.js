/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The browser module's settings, and the channel they give each event. Pure: no DOM, no globals.
 *
 * The page prints the settings as one JSON object keyed by provider. The module reads two parts: `core`
 * (`impressionTrigger` `load` or `visible`, `drainUrl` where waiting server events are taken from, `acknowledgeUrl`
 * where the ones pushed are acknowledged, `plugins` the addresses of plugin modules) and, when the store sends events
 * from the server, `server` (`channels`: event name to `browser`, `server` or `both`; `collectUrl`: where events for
 * the server are posted). Without `server` every event goes to the browser, as does an event missing from `channels`,
 * an unknown channel, and any event while events cannot be posted. The whole object, every other provider's part
 * included, is kept read-only for plugins.
 */

/**
 * Where an event goes, by channel
 */
const ROUTES = Object.freeze({
    browser: Object.freeze({browser: true, server: false}),
    server: Object.freeze({browser: false, server: true}),
    both: Object.freeze({browser: true, server: true})
});

/**
 * @param {*} value
 * @returns {boolean} Whether a value is an object (not an array, not null)
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * @param {*} value
 * @returns {string} The trimmed text, empty for anything else
 */
function text(value) {
    return typeof value === 'string' ? value.trim() : '';
}

/**
 * Freeze a decoded JSON value and everything in it
 *
 * @param {*} value
 * @returns {*} The same value
 */
function deepFreeze(value) {
    if (value !== null && typeof value === 'object') {
        Object.values(value).forEach(deepFreeze);
        Object.freeze(value);
    }

    return value;
}

/**
 * @param {*} value
 * @returns {Object<string, string>} The channels that name a known route
 */
function parseChannels(value) {
    const channels = {};

    if (isRecord(value)) {
        Object.entries(value).forEach(([eventName, channel]) => {
            if (typeof channel === 'string' && Object.prototype.hasOwnProperty.call(ROUTES, channel)) {
                channels[eventName] = channel;
            }
        });
    }

    return channels;
}

/**
 * Read the settings the page prints
 *
 * @param {*} json The text of the settings script
 * @returns {{config: Object, impressionTrigger: string, drainUrl: string, acknowledgeUrl: string,
 *     plugins: Array<string>, channels: Object<string, string>, collectUrl: string}} `config` is the whole object,
 *     frozen
 */
export function parseSettings(json) {
    let decoded;

    try {
        decoded = typeof json === 'string' ? JSON.parse(json) : undefined;
    } catch {
        decoded = undefined;
    }
    const config = deepFreeze(isRecord(decoded) ? decoded : {});
    const core = isRecord(config.core) ? config.core : {};
    const server = isRecord(config.server) ? config.server : {};

    return Object.freeze({
        config,
        impressionTrigger: core.impressionTrigger === 'visible' ? 'visible' : 'load',
        drainUrl: text(core.drainUrl),
        acknowledgeUrl: text(core.acknowledgeUrl),
        plugins: Object.freeze(Array.isArray(core.plugins) ? core.plugins.map(text).filter((url) => url !== '') : []),
        channels: Object.freeze(parseChannels(server.channels)),
        collectUrl: text(server.collectUrl)
    });
}

/**
 * Whether any event can go to the server, so the page needs the means to post it
 *
 * @param {{channels: Object<string, string>, collectUrl: string}} settings As read by `parseSettings`
 * @returns {boolean}
 */
export function sendsToServer(settings) {
    return settings.collectUrl !== '' && Object.values(settings.channels).some((channel) => ROUTES[channel].server);
}

/**
 * Where an event goes
 *
 * @param {{channels: Object<string, string>}} settings As read by `parseSettings`
 * @param {string} eventName
 * @param {boolean} canPost Whether events can be posted to the server from this page
 * @returns {{browser: boolean, server: boolean}}
 */
export function routeOf(settings, eventName, canPost) {
    const own = Object.prototype.hasOwnProperty.call(settings.channels, eventName);

    return canPost && own ? ROUTES[settings.channels[eventName]] : ROUTES.browser;
}
