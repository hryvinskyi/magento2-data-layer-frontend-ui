/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The data a product list or a set of promotions carries in its own HTML, and the events built from it.
 * Pure: no DOM, no globals.
 *
 * A product list's script holds `{"list": {item_list_id, item_list_name, items: [...]}, "impression": bool,
 * "clicks": bool, "links": {"/path": position}}`; a promotions script holds `{"promotions": [...], "impression": bool,
 * "clicks": bool, "links": {"/path": position}}`. A position points into `list.items` (or `promotions`). `impression`
 * says whether the block is impressed and `clicks` whether a click on one of its links is reported; a script without
 * `clicks` (HTML cached before the server printed it) reports clicks on the links it carries. The text is untrusted:
 * anything of another shape, a position that is not a whole number inside the list, or text that is not JSON reads
 * as nothing, and nothing here throws.
 *
 * `readerOf(kind)` answers for the value of the script's `data-hryvinskyi-datalayer` attribute; a kind no reader
 * is registered for gets none, so a script of another kind is left alone.
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
 * @param {number} length
 * @returns {boolean} Whether a value is a position inside a list of the given length
 */
function isPosition(value, length) {
    return Number.isInteger(value) && value >= 0 && value < length;
}

/**
 * @param {*} text
 * @returns {*} The decoded JSON, undefined when the text is not JSON
 */
function decode(text) {
    if (typeof text !== 'string') {
        return undefined;
    }
    try {
        return JSON.parse(text);
    } catch {
        return undefined;
    }
}

/**
 * @param {*} value
 * @returns {Array<Object>|null} A non-empty list whose every entry is an object, or nothing
 */
function recordList(value) {
    return Array.isArray(value) && value.length > 0 && value.every(isRecord) ? value : null;
}

/**
 * Read the links of a list: URL path to a position in the list
 *
 * @param {*} value
 * @param {number} length How many entries the list has
 * @returns {Map<string, number>|null} Null when any position does not point into the list
 */
function parseLinks(value, length) {
    if (!isRecord(value)) {
        return null;
    }
    const links = new Map();

    for (const [path, position] of Object.entries(value)) {
        if (!isPosition(position, length)) {
            return null;
        }
        links.set(path, position);
    }

    return links;
}

/**
 * @param {*} value The script's `clicks`
 * @returns {boolean|null} Whether clicks are reported; null for a value that is not a flag
 */
function clicksOf(value) {
    if (value === undefined) {
        return true;
    }

    return typeof value === 'boolean' ? value : null;
}

/**
 * Read a product list's script
 *
 * @param {*} text
 * @returns {{list: Object, impression: boolean, clicks: boolean, links: Map<string, number>}|null}
 */
export function parseList(text) {
    const data = decode(text);

    if (!isRecord(data) || !isRecord(data.list) || typeof data.impression !== 'boolean') {
        return null;
    }
    const clicks = clicksOf(data.clicks);
    const items = recordList(data.list.items);
    const optionalText = ['item_list_id', 'item_list_name'].every(
        (name) => data.list[name] === undefined || typeof data.list[name] === 'string'
    );

    if (items === null || !optionalText || clicks === null) {
        return null;
    }
    const links = parseLinks(data.links, items.length);

    return links === null ? null : {list: data.list, impression: data.impression, clicks, links};
}

/**
 * Read a promotions script
 *
 * @param {*} text
 * @returns {{promotions: Array<Object>, impression: boolean, clicks: boolean, links: Map<string, number>}|null}
 */
export function parsePromotions(text) {
    const data = decode(text);

    if (!isRecord(data) || typeof data.impression !== 'boolean') {
        return null;
    }
    const promotions = recordList(data.promotions);
    const clicks = clicksOf(data.clicks);

    if (promotions === null || clicks === null) {
        return null;
    }
    const links = parseLinks(data.links, promotions.length);

    return links === null ? null : {promotions, impression: data.impression, clicks, links};
}

/**
 * @param {{list: Object}} listData As read by `parseList`
 * @returns {{event: string, ecommerce: Object}} The `view_item_list` event of a list
 */
export function listImpression(listData) {
    return {event: 'view_item_list', ecommerce: {...listData.list, items: listData.list.items.slice()}};
}

/**
 * The `select_item` event of the item at a position of a list
 *
 * @param {{list: Object, clicks: boolean}} listData As read by `parseList`
 * @param {*} index
 * @returns {{event: string, ecommerce: Object}|null} Null for a position outside the list, and for a list whose
 *     clicks are not reported
 */
export function selectItemEvent(listData, index) {
    const items = listData.list.items;

    if (listData.clicks !== true || !isPosition(index, items.length)) {
        return null;
    }
    const ecommerce = {};

    ['item_list_id', 'item_list_name'].forEach((name) => {
        if (typeof listData.list[name] === 'string') {
            ecommerce[name] = listData.list[name];
        }
    });
    ecommerce.items = [{...items[index]}];

    return {event: 'select_item', ecommerce};
}

/**
 * One `view_promotion` event per promotion, in order: GA4 reads one promotion per event
 *
 * @param {{promotions: Array<Object>}} promotionData As read by `parsePromotions`
 * @returns {Array<{event: string, ecommerce: Object}>}
 */
export function promotionImpressions(promotionData) {
    return promotionData.promotions.map((promotion) => ({event: 'view_promotion', ecommerce: {...promotion}}));
}

/**
 * The `select_promotion` event of the promotion at a position
 *
 * @param {{promotions: Array<Object>, clicks: boolean}} promotionData As read by `parsePromotions`
 * @param {*} index
 * @returns {{event: string, ecommerce: Object}|null} Null for a position outside the promotions, and for promotions
 *     whose clicks are not reported
 */
export function selectPromotionEvent(promotionData, index) {
    if (promotionData.clicks !== true || !isPosition(index, promotionData.promotions.length)) {
        return null;
    }

    return {event: 'select_promotion', ecommerce: {...promotionData.promotions[index]}};
}

/**
 * What to do with each kind of data script: read it, list its impressions, build the event of a click.
 * `impressions` is empty when the server said the block is not to be impressed.
 */
const readers = Object.freeze({
    list: Object.freeze({
        parse: parseList,
        impressions: (listData) => (listData.impression ? [listImpression(listData)] : []),
        select: selectItemEvent
    }),
    promotions: Object.freeze({
        parse: parsePromotions,
        impressions: (promotionData) => (promotionData.impression ? promotionImpressions(promotionData) : []),
        select: selectPromotionEvent
    })
});

/**
 * @param {*} kind
 * @returns {{parse: Function, impressions: Function, select: Function}|null} The reader of a kind of data script
 */
export function readerOf(kind) {
    return typeof kind === 'string' && Object.prototype.hasOwnProperty.call(readers, kind) ? readers[kind] : null;
}
