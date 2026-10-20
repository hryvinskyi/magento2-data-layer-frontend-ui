/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The ids of the server events this tab has already pushed, so an event handed over again is not pushed twice. Pure:
 * reading and writing the stored copy are ports the caller passes in.
 *
 * The server hands an event over until the page acknowledges it, so the same event can come back: its
 * acknowledgement was lost, or another page of the tab read the queue at the same time. The ids are kept in memory
 * and, through the ports, in a store that outlives the page (the tab's session storage), so the next page of the tab
 * knows them too. Only the most recent ids are kept: an event comes back only while it waits in the queue, which is
 * short and small.
 */

/**
 * How many ids are kept by default
 */
export const DEFAULT_LIMIT = 200;

/**
 * @param {*} text The stored copy: a JSON list of ids
 * @returns {Array<string>} The ids in it, oldest first; none when it is not such a list
 */
export function parseStored(text) {
    let decoded;

    try {
        decoded = typeof text === 'string' ? JSON.parse(text) : undefined;
    } catch {
        decoded = undefined;
    }

    return Array.isArray(decoded) ? decoded.filter((id) => typeof id === 'string' && id !== '') : [];
}

/**
 * Create the set of delivered ids
 *
 * @param {Object} ports
 * @param {function(): (string|null)} ports.read Reads the stored copy; null when there is none or it cannot be read
 * @param {function(string): void} ports.write Stores the copy; a store that refuses it keeps the ids in memory only
 * @param {number} [limit] How many of the most recent ids are kept
 * @returns {{has: function(string): boolean, add: function(string): void}}
 */
export function createDeliveredIds(ports, limit = DEFAULT_LIMIT) {
    const ids = parseStored(ports.read()).slice(-limit);
    const known = new Set(ids);

    return Object.freeze({
        has: (id) => known.has(id),
        add: (id) => {
            if (known.has(id)) {
                return;
            }
            known.add(id);
            ids.push(id);
            while (ids.length > limit) {
                known.delete(ids.shift());
            }
            ports.write(JSON.stringify(ids));
        }
    });
}
