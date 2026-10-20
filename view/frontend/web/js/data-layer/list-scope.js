/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Where in the page a list's data script belongs. Pure: works on plain descriptions of elements, not on the DOM.
 *
 * The server prints a list's data script right after the list block's own HTML, as a sibling of the block's
 * top-level elements, and an empty start marker right before that HTML; the marker and the block's data scripts carry
 * the same pairing id. So the list's scope is the run of element siblings between the nearest start marker before the
 * script that carries the script's pairing id, and the script. Whatever lies in between is the block's, including
 * the markers, elements and data scripts of a list printed inside it: an outer list is scoped by its own marker, not
 * cut short by an inner one. Several data scripts printed together after one block (one block showing several lists)
 * share the block's pairing id, and so its scope.
 *
 * HTML cached before the server paired them has data scripts without a pairing id (or a marker without one, which no
 * script then reaches). There the run ends at the nearest start marker, or at the previous data script that follows
 * something shown, or at the parent's first element; a data script met right after the script, with nothing shown
 * between them, does not end the run. Elements that are never shown (`script`, `style`, `template`, `noscript`) are
 * left out of the scope: those never enter the viewport and never contain a link. When the run holds nothing shown
 * the scope is the parent element.
 */

const NEVER_SHOWN = Object.freeze({SCRIPT: true, STYLE: true, TEMPLATE: true, NOSCRIPT: true});

/**
 * @param {*} value
 * @returns {boolean} Whether a value describes an element
 */
function isElement(value) {
    return value !== null && typeof value === 'object';
}

/**
 * @param {*} sibling
 * @returns {boolean} Whether a described element is one that can be shown
 */
function isShown(sibling) {
    return isElement(sibling) && sibling.isStart !== true && sibling.isDataScript !== true
        && typeof sibling.tagName === 'string' && NEVER_SHOWN[sibling.tagName.toUpperCase()] !== true;
}

/**
 * @param {*} sibling
 * @returns {string|null} A described element's pairing id, when it has one
 */
function pairOf(sibling) {
    return isElement(sibling) && typeof sibling.pair === 'string' && sibling.pair !== '' ? sibling.pair : null;
}

/**
 * The scope of a paired data script: what is shown between its own start marker and it
 *
 * @param {Array<*>} siblings
 * @param {number} scriptIndex
 * @param {string} pair
 * @returns {Array<number>|null} Null when no start marker before the script carries its pairing id
 */
function pairedScope(siblings, scriptIndex, pair) {
    for (let i = scriptIndex - 1; i >= 0; i--) {
        if (isElement(siblings[i]) && siblings[i].isStart === true && pairOf(siblings[i]) === pair) {
            const positions = [];

            for (let position = i + 1; position < scriptIndex; position++) {
                if (isShown(siblings[position])) {
                    positions.push(position);
                }
            }

            return positions;
        }
    }

    return null;
}

/**
 * The scope of a data script without a pairing id (or whose marker is missing), as HTML cached before the pairing
 * describes it
 *
 * @param {Array<*>} siblings
 * @param {number} scriptIndex
 * @returns {Array<number>}
 */
function unpairedScope(siblings, scriptIndex) {
    const positions = [];

    for (let i = scriptIndex - 1; i >= 0; i--) {
        const sibling = siblings[i];

        if (!isElement(sibling)) {
            continue;
        }
        if (sibling.isStart === true) {
            break;
        }
        if (sibling.isDataScript === true) {
            if (positions.length > 0) {
                break;
            }
            continue;
        }
        if (isShown(sibling)) {
            positions.push(i);
        }
    }

    return positions.reverse();
}

/**
 * The positions, among a parent's element children, that form the scope of the data script at `scriptIndex`
 *
 * @param {Array<{tagName: string, isDataScript: boolean, isStart: boolean, pair: (string|null)}>} siblings The
 *     parent's element children, in order; `isStart` marks a list's start marker, `isDataScript` any other data
 *     script, `pair` the pairing id a marker or a data script carries
 * @param {number} scriptIndex The data script's position among them
 * @returns {Array<number>} In document order; empty when the scope is the parent itself
 */
export function scopeOf(siblings, scriptIndex) {
    if (!Array.isArray(siblings) || !Number.isInteger(scriptIndex) || scriptIndex < 0
        || scriptIndex >= siblings.length
    ) {
        return [];
    }
    const pair = pairOf(siblings[scriptIndex]);
    const paired = pair === null ? null : pairedScope(siblings, scriptIndex, pair);

    return paired !== null ? paired : unpairedScope(siblings, scriptIndex);
}

/**
 * Of the lists whose scope holds a clicked link, the one closest to it: the list whose scope element holding the link
 * lies inside the others' (a list inside another list's block); between lists whose scopes hold it through the same
 * element (several lists of one block, or a list printed at the top of another's block), the one whose data script
 * comes first in the page, which is the inner one
 *
 * @template T
 * @param {Array<{list: T, holder: *, script: *}>} candidates Each list with the element of its scope that holds the
 *     link and its data script
 * @param {function(*, *): boolean} contains Whether the first element contains the second, itself included
 * @param {function(*, *): boolean} precedes Whether the first data script comes before the second in the page
 * @returns {T|null}
 */
export function closestList(candidates, contains, precedes) {
    if (!Array.isArray(candidates) || candidates.length === 0) {
        return null;
    }

    return candidates.reduce((closest, candidate) => {
        if (candidate.holder !== closest.holder) {
            return contains(closest.holder, candidate.holder) ? candidate : closest;
        }

        return precedes(candidate.script, closest.script) ? candidate : closest;
    }).list;
}
