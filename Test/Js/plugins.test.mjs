/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The push pipeline and the interface plugins receive. Plain Node: node --test app/code/Hryvinskyi/DataLayerFrontendUi/Test/Js/
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createPluginHost, loadPlugins, pluginsOnOrigin} from '../../view/frontend/web/js/data-layer/plugins.js';

const CONFIG = Object.freeze({core: Object.freeze({impressionTrigger: 'load'})});

/**
 * A host wired to a recording data layer and warning list
 *
 * @returns {{host: Object, committed: Array<Object>, warnings: Array<string>}}
 */
function harness() {
    const committed = [];
    const warnings = [];
    const host = createPluginHost({
        config: CONFIG,
        commit: (object) => committed.push(object),
        warn: (reason) => warnings.push(reason)
    });

    return {host, committed, warnings};
}

test('the interface is frozen and carries the settings read-only', () => {
    const {host} = harness();

    assert.ok(Object.isFrozen(host.api));
    assert.equal(host.api.config, CONFIG);
    assert.deepEqual(Object.keys(host.api).sort(), ['beforePush', 'config', 'onPush', 'push']);
});

test('a push without hooks reaches the data layer as it is; what is not an object is ignored', () => {
    const {host, committed} = harness();

    host.push({event: 'login'});
    host.push('login');
    host.push(null);
    host.push([{event: 'login'}]);
    assert.deepEqual(committed, [{event: 'login'}]);
});

test('beforePush hooks run in registration order, each seeing the previous one\'s result', () => {
    const {host, committed} = harness();
    const seen = [];

    host.api.beforePush((object) => {
        seen.push(['first', object.event]);

        return {...object, first: true};
    });
    host.api.beforePush((object) => {
        seen.push(['second', object.first]);

        return {...object, second: true};
    });
    host.push({event: 'view_item'});

    assert.deepEqual(seen, [['first', 'view_item'], ['second', true]]);
    assert.deepEqual(committed, [{event: 'view_item', first: true, second: true}]);
});

test('a beforePush hook that returns null drops the object: later hooks and onPush never see it', () => {
    const {host, committed} = harness();
    const later = [];
    const after = [];

    host.api.beforePush((object) => (object.event === 'view_item_list' ? null : object));
    host.api.beforePush((object) => {
        later.push(object.event);

        return object;
    });
    host.api.onPush((object) => after.push(object.event));
    host.push({event: 'view_item_list'});
    host.push({event: 'select_item'});

    assert.deepEqual(committed, [{event: 'select_item'}]);
    assert.deepEqual(later, ['select_item']);
    assert.deepEqual(after, ['select_item']);
});

test('a beforePush hook that returns nothing usable leaves the object as it was', () => {
    const {host, committed} = harness();

    host.api.beforePush(() => undefined);
    host.api.beforePush(() => 'replacement');
    host.push({event: 'login'});
    assert.deepEqual(committed, [{event: 'login'}]);
});

test('onPush hooks run after the object reached the data layer, with what was pushed', () => {
    const {host, committed} = harness();
    const after = [];

    host.api.beforePush((object) => ({...object, extra: 1}));
    host.api.onPush((object) => after.push([committed.length, object]));
    host.push({event: 'login'});
    assert.deepEqual(after, [[1, {event: 'login', extra: 1}]]);
});

test('a hook that throws is skipped and reported once; the pipeline keeps working', () => {
    const {host, committed, warnings} = harness();

    host.api.beforePush(() => {
        throw new Error('broken');
    });
    host.api.onPush(() => {
        throw new Error('broken too');
    });
    host.push({event: 'a'});
    host.push({event: 'b'});
    assert.deepEqual(committed, [{event: 'a'}, {event: 'b'}]);
    assert.equal(warnings.length, 2, 'one report per failing hook, however often it fails');
});

test('hooks that are not functions are ignored', () => {
    const {host, committed} = harness();

    host.api.beforePush('nope');
    host.api.onPush(null);
    host.push({event: 'login'});
    assert.deepEqual(committed, [{event: 'login'}]);
});

test('a plugin\'s push goes through the same pipeline', () => {
    const {host, committed} = harness();

    host.api.beforePush((object) => ({...object, stamped: true}));
    host.run({default: (api) => api.push({event: 'generate_lead'})}, '/plugin.js');
    assert.deepEqual(committed, [{event: 'generate_lead', stamped: true}]);
});

test('a plugin is started once with the interface; a broken one is reported and isolated', () => {
    const {host, warnings} = harness();
    const received = [];

    host.run({default: (api) => received.push(api)}, '/good.js');
    host.run({default: () => {
        throw new Error('broken');
    }}, '/throws.js');
    host.run({}, '/no-default.js');
    host.run({default: 'text'}, '/not-a-function.js');
    host.run(null, '/nothing.js');

    assert.deepEqual(received, [host.api]);
    assert.equal(warnings.length, 4);
    assert.ok(warnings.some((warning) => warning.includes('/throws.js')));
    assert.ok(warnings.some((warning) => warning.includes('/no-default.js')));
});

test('plugins load and start; one that fails to load is reported and the others still start', async () => {
    const started = [];
    const warnings = [];
    const modules = {
        '/a.js': {default: () => started.push('a')},
        '/c.js': {default: () => started.push('c')}
    };

    await loadPlugins(
        ['/a.js', '/b.js', '/c.js'],
        (url) => (modules[url] ? Promise.resolve(modules[url]) : Promise.reject(new Error('404'))),
        (module, url) => module.default(url),
        (reason) => warnings.push(reason)
    );
    assert.deepEqual(started.sort(), ['a', 'c']);
    assert.equal(warnings.length, 1);
    assert.ok(warnings[0].includes('/b.js'));
});

test('a loader that throws at once is a failed load, not an exception', async () => {
    const warnings = [];

    await loadPlugins(['/x.js'], () => {
        throw new Error('no import');
    }, () => assert.fail('nothing to run'), (reason) => warnings.push(reason));
    assert.equal(warnings.length, 1);
});

test('an onPush hook registered with replay first sees every earlier push, in order, then the later ones', () => {
    const {host} = harness();
    const plain = [];
    const replayed = [];

    host.push({event: 'view_item'});
    host.api.beforePush((object) => (object.event === 'dropped' ? null : object));
    host.push({event: 'dropped'});
    host.push({event: 'purchase'});
    host.api.onPush((object) => plain.push(object.event));
    host.api.onPush((object) => replayed.push(object.event), {replay: true});
    host.api.onPush((object) => replayed.push('ignored ' + object.event), {replay: 'yes'});
    host.push({event: 'login'});

    assert.deepEqual(plain, ['login']);
    assert.deepEqual(replayed, ['view_item', 'purchase', 'login', 'ignored login']);
});

test('a replaying hook that throws is reported once and still sees later pushes', () => {
    const {host, warnings} = harness();
    let calls = 0;

    host.push({event: 'view_item'});
    host.push({event: 'view_cart'});
    host.api.onPush(() => {
        calls += 1;
        throw new Error('broken');
    }, {replay: true});
    host.push({event: 'login'});

    assert.equal(calls, 3);
    assert.deepEqual(warnings, ['a data layer plugin hook failed and was skipped.']);
});

test('once released, the pushes kept for replay are let go and later replaying hooks see only later pushes', () => {
    const {host, committed} = harness();
    const seen = [];

    host.push({event: 'page_view'});
    host.release();
    host.push({event: 'view_item'});
    host.api.onPush((object) => seen.push(object.event), {replay: true});
    host.push({event: 'add_to_cart'});

    assert.deepEqual(seen, ['add_to_cart']);
    assert.equal(committed.length, 3, 'releasing changes nothing in the data layer');
});

test('a plugin that awaits before registering its replaying hook still receives the page\'s events', async () => {
    const {host} = harness();
    const seen = [];
    let resume = null;
    const plugin = {
        default: async (api) => {
            await new Promise((resolve) => {
                resume = resolve;
            });
            api.onPush((object) => seen.push(object.event), {replay: true});
        }
    };

    host.push({event: 'page_view'});
    host.push({event: 'view_item'});
    const loaded = loadPlugins(['/slow.js'], () => Promise.resolve(plugin), host.run, assert.fail).then(host.release);

    await new Promise((resolve) => setImmediate(resolve));
    assert.notEqual(resume, null, 'the plugin is waiting');
    host.push({event: 'scroll'});
    resume();
    await loaded;
    host.push({event: 'add_to_cart'});

    assert.deepEqual(seen, ['page_view', 'view_item', 'scroll', 'add_to_cart']);
    host.api.onPush((object) => seen.push('late ' + object.event), {replay: true});
    assert.equal(seen.length, 4, 'released after the plugin started: a later hook replays nothing');
});

test('a plugin whose start rejects is reported once and does not keep the others from settling', async () => {
    const {host, warnings} = harness();
    const modules = {
        '/rejects.js': {default: async () => {
            throw new Error('broken');
        }},
        '/fine.js': {default: () => undefined}
    };

    await loadPlugins(['/rejects.js', '/fine.js'], (url) => Promise.resolve(modules[url]), host.run, assert.fail);

    assert.deepEqual(warnings, ['the data layer plugin /rejects.js failed to start.']);
});

test('a plugin whose start never settles is reported at the deadline, so the caller can release the pushes', async () => {
    const warnings = [];
    let endWait = null;
    const loaded = loadPlugins(
        ['/hangs.js'],
        () => Promise.resolve({}),
        () => new Promise(() => undefined),
        (reason) => warnings.push(reason),
        () => new Promise((resolve) => {
            endWait = resolve;
        })
    );

    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(warnings, []);
    endWait();
    await loaded;
    assert.equal(warnings.length, 1);
    assert.ok(warnings[0].includes('had not started in time'));
});

test('plugins that start before the deadline settle the load without a report', async () => {
    const warnings = [];

    await loadPlugins(
        ['/a.js'],
        () => Promise.resolve({}),
        () => Promise.resolve(),
        (reason) => warnings.push(reason),
        () => new Promise(() => undefined)
    );
    assert.deepEqual(warnings, []);
});

test('a hook registered before the release still replays the pushes made before it', () => {
    const {host} = harness();
    const seen = [];

    host.push({event: 'page_view'});
    host.api.onPush((object) => seen.push(object.event), {replay: true});
    host.release();
    host.push({event: 'view_item'});

    assert.deepEqual(seen, ['page_view', 'view_item']);
});

test('plugins load only from the module\'s own origin', () => {
    const origin = 'https://static.shop.test';
    const result = pluginsOnOrigin([
        'https://static.shop.test/static/frontend/a.js',
        '/static/frontend/b.js',
        'https://evil.test/c.js',
        '//evil.test/d.js',
        'data:text/javascript,export default () => {}',
        'javascript:alert(1)',
        'http://static.shop.test/e.js'
    ], origin);

    assert.deepEqual(result.accepted, ['https://static.shop.test/static/frontend/a.js', '/static/frontend/b.js']);
    assert.equal(result.refused.length, 5);
});
