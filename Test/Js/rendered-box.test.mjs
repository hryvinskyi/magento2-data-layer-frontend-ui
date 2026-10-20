/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Whether a list takes up room on the page. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {hasRenderedBox} from '../../view/frontend/web/js/data-layer/rendered-box.js';

test('a list with any box that has both a width and a height is rendered', () => {
    assert.equal(hasRenderedBox([{width: 0, height: 0}, {width: 320, height: 1}]), true);
});

test('a list hidden with display none, or squeezed to a line, is not rendered', () => {
    assert.equal(hasRenderedBox([{width: 0, height: 0}]), false);
    assert.equal(hasRenderedBox([{width: 1200, height: 0}, {width: 0, height: 40}]), false);
    assert.equal(hasRenderedBox([]), false);
});

test('what is not a list of boxes is never rendered, and nothing throws', () => {
    for (const boxes of [null, undefined, 'box', [null], [{width: '10', height: '10'}], [{width: NaN, height: 5}]]) {
        assert.equal(hasRenderedBox(boxes), false, String(boxes));
    }
});
