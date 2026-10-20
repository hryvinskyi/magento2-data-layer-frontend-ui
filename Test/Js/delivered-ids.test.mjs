/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The ids of the server events a tab has pushed: kept in memory and in a store that outlives the page, bounded.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    DEFAULT_LIMIT,
    createDeliveredIds,
    parseStored
} from '../../view/frontend/web/js/data-layer/delivered-ids.js';

/**
 * A store of one text, as the tab's session storage keeps it
 *
 * @param {string|null} text
 * @returns {{storage: {text: (string|null)}, ports: Object}}
 */
function store(text = null) {
    const storage = {text};

    return {
        storage,
        ports: {
            read: () => storage.text,
            write: (written) => {
                storage.text = written;
            }
        }
    };
}

test('an id added is known, to this page and to the next one that reads the same store', () => {
    const {storage, ports} = store();
    const ids = createDeliveredIds(ports);

    assert.equal(ids.has('a'), false);
    ids.add('a');
    assert.equal(ids.has('a'), true);
    assert.deepEqual(JSON.parse(storage.text), ['a']);

    assert.equal(createDeliveredIds(ports).has('a'), true);
});

test('adding a known id writes nothing', () => {
    const {ports} = store('["a"]');
    let writes = 0;
    const ids = createDeliveredIds({read: ports.read, write: () => writes++});

    ids.add('a');
    assert.equal(writes, 0);
});

test('only the most recent ids are kept', () => {
    const {storage, ports} = store();
    const ids = createDeliveredIds(ports, 3);

    ['a', 'b', 'c', 'd'].forEach(ids.add);
    assert.equal(ids.has('a'), false);
    assert.deepEqual(['b', 'c', 'd'].map(ids.has), [true, true, true]);
    assert.deepEqual(JSON.parse(storage.text), ['b', 'c', 'd']);

    const next = createDeliveredIds(store('["a","b","c","d","e"]').ports, 3);

    assert.deepEqual(['a', 'b', 'c', 'd', 'e'].map(next.has), [false, false, true, true, true]);
    assert.ok(DEFAULT_LIMIT >= 50, 'the default keeps more ids than the server queues');
});

test('a store that cannot be read, or holds something else, starts empty', () => {
    for (const text of [null, '', 'not json', '{"a":1}', '3']) {
        assert.equal(createDeliveredIds(store(text).ports).has('a'), false, String(text));
    }
    assert.deepEqual(parseStored('["a", 3, "", null, "b"]'), ['a', 'b']);
});
