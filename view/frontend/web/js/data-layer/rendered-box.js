/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Whether a list takes up room on the page. Pure: works on plain box sizes, not on the DOM.
 *
 * A list hidden with `display: none` (a zone the theme shows only above some viewport width) or squeezed to nothing
 * has no box with both a width and a height. Such a list cannot be seen, so it is impressed only once it gets one.
 */

/**
 * @param {*} value
 * @returns {boolean} Whether a value is an object
 */
function isObject(value) {
    return value !== null && typeof value === 'object';
}

/**
 * Whether any of a list's elements has a box with an area
 *
 * @param {*} boxes The boxes of the elements the list occupies, each with `width` and `height`
 * @returns {boolean}
 */
export function hasRenderedBox(boxes) {
    return Array.isArray(boxes) && boxes.some((box) => isObject(box)
        && typeof box.width === 'number' && typeof box.height === 'number' && box.width > 0 && box.height > 0);
}
