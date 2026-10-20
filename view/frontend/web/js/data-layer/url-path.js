/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The path of a link, reduced the way the server reduces the product and promotion links it lists, so a clicked
 * link finds its entry. Pure: no DOM, no globals beyond the standard `URL`.
 *
 * The rule: keep only the path — no scheme, host, port, query or fragment; the trailing slash and percent-encoding
 * as written; a host-only link gives `/`; a protocol-relative link is accepted. Empty text, a link that is only a
 * fragment or a query, a bare relative path, a scheme other than http(s) and a link with an empty host give
 * nothing. Given the page's own URL, a link relative to the page is first resolved against it (the browser knows
 * where the page is, the server does not), and a link to another origin gives nothing.
 */

/**
 * The generic URI pattern of RFC 3986 (appendix B), which accepts any string.
 * Groups: 2 scheme, 3 authority marker ("//" plus authority), 4 authority, 5 path.
 */
const URI_PATTERN = /^(([^:/?#]+):)?(\/\/([^/?#]*))?([^?#]*)(\?[^#]*)?(#[\s\S]*)?$/;

const WEB_SCHEMES = Object.freeze({http: true, https: true});

/**
 * @param {Array} parts The URI pattern's match
 * @returns {string|null} The path of a link, without resolving anything
 */
function pathOfParts(parts) {
    const scheme = parts[2] === undefined ? '' : parts[2];
    const hasAuthority = parts[3] !== undefined;
    const host = parts[4] === undefined ? '' : parts[4];
    const path = parts[5];

    if (scheme !== '' && (WEB_SCHEMES[scheme.toLowerCase()] !== true || host === '')) {
        return null;
    }
    if (hasAuthority) {
        if (host === '') {
            return null;
        }

        return path === '' ? '/' : path;
    }

    return path.charAt(0) === '/' ? path : null;
}

/**
 * @param {string} href
 * @param {string} [base]
 * @returns {URL|null} The absolute URL, or nothing
 */
function parseUrl(href, base) {
    try {
        return new URL(href, base);
    } catch {
        return null;
    }
}

/**
 * The path of a link
 *
 * @param {*} href The link as written in the page
 * @param {*} [baseHref] The page's URL; without it nothing is resolved and no origin is compared
 * @returns {string|null}
 */
export function pathOf(href, baseHref) {
    if (typeof href !== 'string') {
        return null;
    }
    const link = href.trim();

    if (link === '') {
        return null;
    }
    const parts = URI_PATTERN.exec(link);
    const ownPath = pathOfParts(parts);

    if (typeof baseHref !== 'string') {
        return ownPath;
    }
    const base = parseUrl(baseHref);
    const resolved = base === null ? null : parseUrl(link, base.href);

    if (resolved === null || resolved.origin !== base.origin
        || WEB_SCHEMES[resolved.protocol.slice(0, -1)] !== true
    ) {
        return null;
    }
    const relativeToPage = parts[2] === undefined && parts[3] === undefined && parts[5].charAt(0) !== '/';

    return relativeToPage ? resolved.pathname : ownPath;
}
