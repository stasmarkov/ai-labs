/**
 * Decides whether a hub message should change the price on the page.
 *
 * The hub replays its history from the start (lastEventID=earliest) so a price change published between the page
 * render and the EventSource connection is not lost. History also holds changes the page already shows, so the
 * rule is: apply a message only when its published_at_ms is not older than the server time at render
 * (data-price-rendered-ms) and not older than the last message already applied. Equal times are applied: the same
 * price twice is harmless, a skipped newer price is not. On reconnect EventSource sends Last-Event-ID by itself.
 *
 * @param {unknown} update the parsed message data
 * @param {number} renderedMs server time at render, in ms
 * @param {number} lastAppliedMs published_at_ms of the last applied message (0 when none)
 * @returns {boolean}
 */
export function shouldApply(update, renderedMs, lastAppliedMs) {
    if (typeof update !== 'object' || update === null) {
        return false;
    }
    const { formatted, published_at_ms: publishedAtMs } = /** @type {Record<string, unknown>} */ (update);

    return typeof formatted === 'string'
        && typeof publishedAtMs === 'number'
        && publishedAtMs >= renderedMs
        && publishedAtMs >= lastAppliedMs;
}
