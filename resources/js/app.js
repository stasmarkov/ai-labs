import { shouldApply } from './price-updates.js';

const price = document.querySelector('[data-price-topic]');
const hub = document.querySelector('meta[name="mercure-hub"]')?.content;

if (price && hub && 'EventSource' in window) {
    const privateTopic = price.dataset.priceTopicPrivate;
    const renderedMs = Number(price.dataset.priceRenderedMs) || 0;
    const url = new URL(hub, window.location.origin);
    url.searchParams.append('topic', price.dataset.priceTopic);
    if (privateTopic) {
        url.searchParams.append('topic', privateTopic);
    }
    // Replay the hub's short history: a change published between render and connect still arrives.
    url.searchParams.append('lastEventID', 'earliest');

    const status = document.getElementById('listing-price-status');
    const source = new EventSource(url, { withCredentials: Boolean(privateTopic) });
    let connected = false;
    let lastAppliedMs = 0;

    source.onopen = () => {
        connected = true;
    };

    source.onmessage = (event) => {
        connected = true;
        let update;
        try {
            update = JSON.parse(event.data);
        } catch {
            return;
        }
        if (!shouldApply(update, renderedMs, lastAppliedMs)) {
            return;
        }
        lastAppliedMs = update.published_at_ms;
        price.textContent = update.formatted;
        if (status) {
            status.textContent = `Price updated to ${update.formatted}`;
        }
    };

    // A server without a hub answers once with an error; give up instead of letting EventSource retry.
    source.onerror = () => {
        if (!connected) {
            source.close();
        }
    };
}
