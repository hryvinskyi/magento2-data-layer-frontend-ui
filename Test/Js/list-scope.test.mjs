/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The elements a list's data script stands for. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {closestList, scopeOf} from '../../view/frontend/web/js/data-layer/list-scope.js';

const element = (tagName) => ({tagName, isDataScript: false, isStart: false, pair: null});
const dataScript = (pair = null) => ({tagName: 'SCRIPT', isDataScript: true, isStart: false, pair});
const start = (pair = null) => ({tagName: 'SCRIPT', isDataScript: false, isStart: true, pair});

test('a category list: toolbar, products, toolbar and the template\'s own init script before the data script', () => {
    const siblings = [element('DIV'), element('DIV'), element('DIV'), element('SCRIPT'), dataScript()];

    assert.deepEqual(scopeOf(siblings, 4), [0, 1, 2]);
});

test('script, style, template and noscript elements are never part of a scope, whatever their case', () => {
    const siblings = [
        element('div'),
        element('style'),
        element('TEMPLATE'),
        element('NoScript'),
        element('ol'),
        element('script'),
        dataScript()
    ];

    assert.deepEqual(scopeOf(siblings, 6), [0, 4]);
});

test('two lists in one parent have disjoint scopes, each ending at the other\'s data script', () => {
    const siblings = [
        element('DIV'),
        element('OL'),
        dataScript(),
        element('DIV'),
        element('SCRIPT'),
        dataScript()
    ];

    assert.deepEqual(scopeOf(siblings, 2), [0, 1]);
    assert.deepEqual(scopeOf(siblings, 5), [3]);
});

test('nothing shown before the script: the scope is the parent, given as no positions', () => {
    assert.deepEqual(scopeOf([dataScript()], 0), []);
    assert.deepEqual(scopeOf([element('SCRIPT'), element('STYLE'), dataScript()], 2), []);
    assert.deepEqual(scopeOf([start(), dataScript()], 1), []);
});

test('with a start marker the scope is what lies between the marker and the script, never what precedes it', () => {
    const siblings = [
        element('DIV'),
        element('ASIDE'),
        start(),
        element('DIV'),
        element('SCRIPT'),
        element('OL'),
        dataScript()
    ];

    assert.deepEqual(scopeOf(siblings, 6), [3, 5]);
});

test('two marked lists in one parent each keep their own scope', () => {
    const siblings = [start(), element('DIV'), dataScript(), element('P'), start(), element('OL'), dataScript()];

    assert.deepEqual(scopeOf(siblings, 2), [1]);
    assert.deepEqual(scopeOf(siblings, 6), [5]);
});

test('data scripts printed together after one block share its scope, marked or not', () => {
    const marked = [element('P'), start(), element('DIV'), dataScript(), dataScript()];
    const unmarked = [element('DIV'), dataScript(), dataScript()];

    assert.deepEqual(scopeOf(marked, 3), [2]);
    assert.deepEqual(scopeOf(marked, 4), [2]);
    assert.deepEqual(scopeOf(unmarked, 2), [0]);
});

test('elements after the data script are never in its scope', () => {
    assert.deepEqual(scopeOf([element('DIV'), dataScript(), element('DIV')], 1), [0]);
});

test('malformed input gives the parent, never an exception', () => {
    assert.deepEqual(scopeOf(null, 1), []);
    assert.deepEqual(scopeOf([element('DIV')], 5), []);
    assert.deepEqual(scopeOf([element('DIV')], 0.5), []);
    assert.deepEqual(scopeOf([null, 7, {isDataScript: false}, element('DIV'), dataScript()], 4), [3]);
});

test('an outer list is scoped by its own marker, the inner list printed inside its block included', () => {
    const siblings = [
        element('ASIDE'),
        start('outer'),
        element('H2'),
        start('inner'),
        element('OL'),
        dataScript('inner'),
        element('DIV'),
        dataScript('outer')
    ];

    assert.deepEqual(scopeOf(siblings, 7), [2, 4, 6]);
    assert.deepEqual(scopeOf(siblings, 5), [4]);
});

test('lists printed together share their block\'s pairing id and scope', () => {
    const siblings = [element('P'), start('a1'), element('DIV'), dataScript('a1'), dataScript('a1'), start('b2'),
        element('OL'), dataScript('b2')];

    assert.deepEqual(scopeOf(siblings, 3), [2]);
    assert.deepEqual(scopeOf(siblings, 4), [2]);
    assert.deepEqual(scopeOf(siblings, 7), [6]);
});

test('two lists with the same pairing id each reach the nearest marker carrying it', () => {
    const siblings = [start('same'), element('DIV'), dataScript('same'), start('same'), element('OL'), dataScript('same')];

    assert.deepEqual(scopeOf(siblings, 2), [1]);
    assert.deepEqual(scopeOf(siblings, 5), [4]);
});

test('a paired script whose marker is missing falls back to the rule for unpaired HTML', () => {
    const siblings = [element('DIV'), dataScript('gone'), element('OL'), dataScript('lost')];

    assert.deepEqual(scopeOf(siblings, 3), [2]);
});

test('an unpaired script (HTML cached before pairing) stops at a paired list it follows', () => {
    const siblings = [start('p'), element('DIV'), dataScript('p'), element('OL'), dataScript()];

    assert.deepEqual(scopeOf(siblings, 4), [3]);
});

/**
 * A tiny element tree: each node names its parent
 *
 * @type {Object<string, string|null>}
 */
const TREE = {page: null, outer: 'page', inner: 'outer', card: 'inner'};
const contains = (ancestor, node) => {
    for (let current = node; current !== null && current !== undefined; current = TREE[current]) {
        if (current === ancestor) {
            return true;
        }
    }

    return false;
};
const ORDER = ['s1', 's2', 's3'];
const precedes = (one, other) => ORDER.indexOf(one) < ORDER.indexOf(other);

test('a click inside nested lists goes to the list closest to the link', () => {
    const outer = {list: 'outer list', holder: 'outer', script: 's3'};
    const inner = {list: 'inner list', holder: 'inner', script: 's2'};

    assert.equal(closestList([outer, inner], contains, precedes), 'inner list');
    assert.equal(closestList([inner, outer], contains, precedes), 'inner list');
});

test('lists holding the link through the same element: the one whose data script comes first wins', () => {
    const outer = {list: 'outer list', holder: 'card', script: 's3'};
    const inner = {list: 'inner list', holder: 'card', script: 's1'};

    assert.equal(closestList([outer, inner], contains, precedes), 'inner list');
    assert.equal(closestList([inner, outer], contains, precedes), 'inner list');
});

test('no candidate, no list', () => {
    assert.equal(closestList([], contains, precedes), null);
    assert.equal(closestList(null, contains, precedes), null);
});
