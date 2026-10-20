/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The browser module's push pipeline and the interface its plugins receive. Pure: committing an object to the data
 * layer, loading a module and warning are ports the caller passes in.
 *
 * A plugin is an ES module another Magento module registers; its default export is `function (api)`, called once.
 * `api` is frozen: `config` (the page's settings, read-only), `push(object)` (through the same pipeline as the
 * module's own pushes), `beforePush(fn)` (fn receives each object about to be pushed and returns it, a replacement,
 * or null to drop it; in registration order) and `onPush(fn, options)` (called after each push). Hooks see the pushes
 * made after they registered; an `onPush` hook registered with `{replay: true}` while the page's plugins load is first
 * called with every object pushed before it, in order, so a plugin that forwards pushes elsewhere misses none of the
 * page's events, which are pushed before plugins load. A plugin's function may be `async` (or return a promise): it
 * has started once that promise settles, so a plugin that awaits something before it registers its replaying hook
 * still sees the page's events. The host keeps those objects only until the caller releases them once every plugin
 * has started: a long session's pushes are never held, and a hook registered later replays nothing. A plugin that
 * fails to load, throws or rejects, and a hook that throws, is reported once and left out; the pipeline keeps working.
 */

/**
 * @param {*} value
 * @returns {boolean} Whether a value is a plain object
 */
function isRecord(value) {
    return Object.prototype.toString.call(value) === '[object Object]';
}

/**
 * Create the push pipeline and the plugin interface
 *
 * @param {Object} ports
 * @param {Object} ports.config The page's settings, frozen
 * @param {function(Object): void} ports.commit Writes one object to the data layer
 * @param {function(string): void} ports.warn Reports a problem to the developer
 * @returns {{api: Object, push: function(*): void, run: function(*, string): Promise<void>, release: function(): void}}
 *     `run` settles once the plugin has started; `release` drops the objects kept for replay and stops keeping them
 */
export function createPluginHost(ports) {
    const before = [];
    const after = [];
    let pushed = [];
    const failedHooks = new WeakSet();

    /**
     * Call a hook, reporting its first failure
     *
     * @param {Function} hook
     * @param {Object} object
     * @returns {{ok: boolean, value: *}}
     */
    function call(hook, object) {
        try {
            return {ok: true, value: hook(object)};
        } catch {
            if (!failedHooks.has(hook)) {
                failedHooks.add(hook);
                ports.warn('a data layer plugin hook failed and was skipped.');
            }

            return {ok: false, value: undefined};
        }
    }

    /**
     * Push an object: the `beforePush` hooks, the data layer, then the `onPush` hooks
     *
     * @param {*} object
     * @returns {void}
     */
    function push(object) {
        if (!isRecord(object)) {
            return;
        }
        let current = object;

        for (const hook of before) {
            const result = call(hook, current);

            if (result.ok && result.value === null) {
                return;
            }
            if (result.ok && isRecord(result.value)) {
                current = result.value;
            }
        }
        ports.commit(current);
        if (pushed !== null) {
            pushed.push(current);
        }
        after.forEach((hook) => call(hook, current));
    }

    /**
     * Register a hook called after each push, first replaying the earlier pushes when asked
     *
     * @param {*} hook
     * @param {*} options `{replay: true}` to replay
     * @returns {void}
     */
    function onPush(hook, options) {
        if (typeof hook !== 'function') {
            return;
        }
        if (options !== null && typeof options === 'object' && options.replay === true && pushed !== null) {
            pushed.forEach((object) => call(hook, object));
        }
        after.push(hook);
    }

    const api = Object.freeze({
        config: ports.config,
        push,
        beforePush: (hook) => {
            if (typeof hook === 'function') {
                before.push(hook);
            }
        },
        onPush
    });

    /**
     * Start a loaded plugin module with the interface
     *
     * @param {*} module The module namespace
     * @param {string} url Where it was loaded from, for the report
     * @returns {Promise<void>} Settles, never rejecting, once the plugin's function and any promise it returned did
     */
    function run(module, url) {
        const start = module !== null && typeof module === 'object' ? module.default : undefined;
        const failed = () => ports.warn('the data layer plugin ' + url + ' failed to start.');

        if (typeof start !== 'function') {
            ports.warn('the data layer plugin ' + url + ' exports no default function.');

            return Promise.resolve();
        }
        try {
            return Promise.resolve(start(api)).then(() => undefined, failed);
        } catch {
            failed();

            return Promise.resolve();
        }
    }

    /**
     * Stop keeping pushes for replay and let go of the ones kept
     *
     * @returns {void}
     */
    function release() {
        pushed = null;
    }

    return Object.freeze({api, push, run, release});
}

/**
 * Keep the plugin addresses on one origin, the one the browser module itself was loaded from
 *
 * Plugins are static files of the store's modules, so they are served from where the module is. An address anywhere
 * else (settings planted in the page by injected markup, a scheme that is not a web address) is left out.
 *
 * @param {Array<string>} urls
 * @param {string} origin The static content's origin, such as `https://static.example.com`
 * @returns {{accepted: Array<string>, refused: Array<string>}}
 */
export function pluginsOnOrigin(urls, origin) {
    const accepted = [];
    const refused = [];

    urls.forEach((url) => {
        let resolved = null;

        try {
            resolved = new URL(url, origin);
        } catch {
            resolved = null;
        }
        (resolved !== null && resolved.origin === origin ? accepted : refused).push(url);
    });

    return {accepted, refused};
}

/**
 * Load every plugin and start each as it arrives, in the order its module finishes loading
 *
 * @param {Array<string>} urls
 * @param {function(string): Promise<*>} importModule Loads a module by address
 * @param {function(*, string): (Promise<void>|void)} run Starts a loaded module; settles once it has started
 * @param {function(string): void} warn
 * @param {function(): Promise<void>} [deadline] Settles when waiting for the plugins must end; without one, the wait
 *     lasts until every plugin has started
 * @returns {Promise<void>} Settles once every plugin has started (its own promise included) or was reported, or when
 *     the deadline comes first: a plugin whose start never settles is reported and does not hold the caller forever
 */
export function loadPlugins(urls, importModule, run, warn, deadline) {
    const started = Promise.all(urls.map((url) => Promise.resolve(url).then(importModule).then(
        (module) => run(module, url),
        () => warn('the data layer plugin ' + url + ' could not be loaded.')
    ))).then(() => true);

    if (typeof deadline !== 'function' || urls.length === 0) {
        return started.then(() => undefined);
    }

    return Promise.race([started, Promise.resolve().then(deadline).then(() => false, () => false)]).then((all) => {
        if (!all) {
            warn('a data layer plugin had not started in time; it no longer receives the page\'s earlier pushes.');
        }
    });
}
