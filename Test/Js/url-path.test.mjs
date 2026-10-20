/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * A clicked link reduces to the same path the server wrote into the list's links.
 * Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {pathOf} from '../../view/frontend/web/js/data-layer/url-path.js';

/**
 * The server's normalisation table (its PathOfUrl unit test), row for row: without a page to resolve against, the
 * browser gives exactly what the server gives
 */
const SERVER_TABLE = {
    'absolute': ['https://shop.test/laptop-a.html', '/laptop-a.html'],
    'absolute with query and fragment': ['https://shop.test/laptop-a.html?b=1#reviews', '/laptop-a.html'],
    'plain http with port': ['http://shop.test:8080/laptops/laptop-b.html', '/laptops/laptop-b.html'],
    'trailing slash kept': ['https://shop.test/laptops/', '/laptops/'],
    'no trailing slash added': ['https://shop.test/laptops', '/laptops'],
    'host only': ['https://shop.test', '/'],
    'upper-case scheme': ['HTTPS://shop.test/Laptop-A.html', '/Laptop-A.html'],
    'percent-encoding kept': ['https://shop.test/a%20b.html', '/a%20b.html'],
    'root-relative': ['/laptop-b.html', '/laptop-b.html'],
    'root-relative with query': ['/laptop-b.html?color=red', '/laptop-b.html'],
    'protocol-relative': ['//shop.test/laptop-c.html?x=1', '/laptop-c.html'],
    'surrounding spaces': ['  https://shop.test/laptop-a.html  ', '/laptop-a.html'],
    'empty': ['', null],
    'blank': ['   ', null],
    'fragment only': ['#top', null],
    'query only': ['?page=2', null],
    'bare relative path': ['laptop-a.html', null],
    'mail link': ['mailto:sales@shop.test', null],
    'script link': ['javascript:void(0)', null],
    'unparsable': ['http:///laptop-a.html', null]
};

for (const [name, [href, expected]] of Object.entries(SERVER_TABLE)) {
    test(`without the page's URL, ${name}: ${JSON.stringify(href)} gives ${JSON.stringify(expected)}`, () => {
        assert.equal(pathOf(href), expected);
    });
}

const PAGE = 'https://shop.test/laptops/index.html?p=2#list';

/**
 * In the page: every link the server table gives a path for, on the page's own origin, gives the same path
 */
const SAME_ORIGIN = {
    'absolute': ['https://shop.test/laptop-a.html?b=1#reviews', '/laptop-a.html'],
    'trailing slash kept': ['https://shop.test/laptops/', '/laptops/'],
    'host only': ['https://shop.test', '/'],
    'upper-case scheme and host': ['HTTPS://SHOP.test/Laptop-A.html', '/Laptop-A.html'],
    'percent-encoding kept': ['https://shop.test/a%20b.html', '/a%20b.html'],
    'default port written out': ['https://shop.test:443/laptop-a.html', '/laptop-a.html'],
    'root-relative': ['/laptop-b.html?color=red', '/laptop-b.html'],
    'protocol-relative': ['//shop.test/laptop-c.html?x=1', '/laptop-c.html'],
    'surrounding spaces': ['  /laptop-a.html  ', '/laptop-a.html']
};

for (const [name, [href, expected]] of Object.entries(SAME_ORIGIN)) {
    test(`in the page, ${name}: ${JSON.stringify(href)} gives ${JSON.stringify(expected)}`, () => {
        assert.equal(pathOf(href, PAGE), expected);
    });
}

test('in the page, a link relative to the page is resolved against it first', () => {
    assert.equal(pathOf('laptop-a.html', PAGE), '/laptops/laptop-a.html');
    assert.equal(pathOf('../laptop-a.html?x=1', PAGE), '/laptop-a.html');
    assert.equal(pathOf('?page=3', PAGE), '/laptops/index.html');
    assert.equal(pathOf('#reviews', PAGE), '/laptops/index.html');
});

test('in the page, a link to another origin gives nothing', () => {
    assert.equal(pathOf('https://other.test/laptop-a.html', PAGE), null);
    assert.equal(pathOf('//other.test/laptop-a.html', PAGE), null);
    assert.equal(pathOf('http://shop.test/laptop-a.html', PAGE), null);
    assert.equal(pathOf('https://shop.test:8443/laptop-a.html', PAGE), null);
    assert.equal(pathOf('https://sub.shop.test/laptop-a.html', PAGE), null);
});

test('in the page, links that are not web pages or cannot be read give nothing', () => {
    for (const href of ['', '   ', 'mailto:sales@shop.test', 'javascript:void(0)', 'tel:+441234', 'http:///laptop-a.html',
        'https://', 'data:text/html,hi']) {
        assert.equal(pathOf(href, PAGE), null, href);
    }
});

test('what is not text gives nothing, and nothing throws', () => {
    for (const href of [null, undefined, 3, {}, []]) {
        assert.equal(pathOf(href, PAGE), null);
        assert.equal(pathOf(href), null);
    }
    assert.equal(pathOf('/laptop-a.html', 'not a url'), null);
});
