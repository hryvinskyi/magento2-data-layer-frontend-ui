/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The gtag destination: a browser plugin, loaded only where the page loads gtag.js, that hands every object the data
 * layer pushes to gtag.js as the commands `gtag-commands.js` makes of it, the page's events included (they are
 * replayed, as they were pushed before plugins load).
 *
 * The commands are queued the way the page's own `gtag()` stub queues them: as an `arguments` object on
 * `window.dataLayer`, the array gtag.js reads, so the plugin needs no global function of its own.
 */
import {parseGtagSettings, toGtagCommands} from '@hryvinskyi/data-layer/gtag-commands.js';

/**
 * Queue one gtag command
 *
 * @returns {void}
 */
function gtag() {
    (window.dataLayer = window.dataLayer || []).push(arguments);
}

/**
 * Start the destination
 *
 * @param {{config: Object, onPush: function(function(Object): void, Object): void}} api The plugin interface
 * @returns {void}
 */
export default function start(api) {
    const settings = parseGtagSettings(api.config);

    api.onPush((object) => {
        toGtagCommands(object, settings).forEach((command) => gtag(...command));
    }, {replay: true});
}
