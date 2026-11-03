import { computed, onScopeDispose, ref, shallowRef } from 'vue';

export type StreamStatus = 'idle' | 'streaming' | 'done' | 'stopped' | 'error';

export interface StreamEvent {
    type: string;
    delta?: string;
    invocation_id?: string;
    provider?: string;
    model?: string;
    message?: string;
    [key: string]: unknown;
}

export interface LoggedEvent {
    number: number;
    type: string;
    atMs: number;
    detail?: string;
}

export interface EventStreamHooks {
    /** Called with every parsed event after it is logged, for pages that understand more than text_delta. */
    onEvent?: (event: StreamEvent) => void;
    /** Called with the response once it is known to be a stream, for headers such as Server-Timing. */
    onResponse?: (response: Response) => void;
}

const MAX_LOGGED_EVENTS = 500;
const TICK_MS = 100;

/**
 * Splits a server-sent-events buffer into the data payloads of its complete blocks and the unfinished rest.
 */
export function parseSseBuffer(buffer: string): { payloads: string[]; rest: string } {
    const blocks = buffer.split(/\r?\n\r?\n/);
    const rest = blocks.pop() ?? '';
    const payloads: string[] = [];

    for (const block of blocks) {
        const data = block
            .split(/\r?\n/)
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.slice(5).replace(/^ /, ''))
            .join('\n');

        if (data !== '') {
            payloads.push(data);
        }
    }

    return { payloads, rest };
}

function describe(event: StreamEvent): string | undefined {
    if (event.type === 'text_delta' && typeof event.delta === 'string') {
        return JSON.stringify(event.delta);
    }

    if (event.type === 'error' && typeof event.message === 'string') {
        return event.message;
    }

    if (event.type === 'refused' || event.type === 'no_context') {
        return typeof event.stage === 'string' ? event.stage : undefined;
    }

    if (event.type === 'stream_start') {
        return [event.provider, event.model].filter((part) => typeof part === 'string').join(' ');
    }

    return undefined;
}

function failureMessage(response: Response): string {
    if (response.status === 429) {
        const seconds = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);

        return Number.isFinite(seconds) && seconds > 0
            ? `Too many requests, try again in ${seconds} s.`
            : 'Too many requests, try again in a minute.';
    }

    if (response.status === 404) {
        return 'No such listing.';
    }

    if (response.status === 422) {
        return 'The server rejected the input.';
    }

    if (response.status === 503) {
        return 'The service is unavailable right now.';
    }

    return `Server error (${response.status}).`;
}

/**
 * Reads a server-sent-events response with fetch and a stream reader, so it can be aborted and can see status codes.
 * A token is one text_delta event with a non-empty delta; every time is in ms since fetch was called.
 */
export function useEventStream(hooks: EventStreamHooks = {}) {
    const status = ref<StreamStatus>('idle');
    const text = ref('');
    const events = shallowRef<LoggedEvent[]>([]);
    const totalEvents = ref(0);
    const tokens = ref(0);
    const ttftMs = ref<number | null>(null);
    const error = ref<string | null>(null);
    const invocationId = ref<string | null>(null);
    const firstTokenAt = ref<number | null>(null);
    const lastTokenAt = ref<number | null>(null);
    const now = ref(0);

    let controller: AbortController | null = null;
    let ticker: ReturnType<typeof setInterval> | null = null;
    let startedAt = 0;

    /**
     * Tokens after the first, divided by the time from the first token: the first token
     * opens the interval, so it is not counted inside it. While streaming the interval
     * runs to now; once finished it ends at the last token, not at [DONE].
     */
    const tokensPerSecond = computed<number | null>(() => {
        if (firstTokenAt.value === null || lastTokenAt.value === null || tokens.value < 2) {
            return null;
        }

        const end = status.value === 'streaming' ? now.value : lastTokenAt.value;
        const seconds = (end - firstTokenAt.value) / 1000;

        return seconds > 0 ? Math.round(((tokens.value - 1) / seconds) * 10) / 10 : null;
    });

    function elapsed(): number {
        return Math.round(performance.now() - startedAt);
    }

    function stopTicker(): void {
        if (ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }

        now.value = elapsed();
    }

    function finish(next: StreamStatus, message: string | null = null): void {
        if (status.value !== 'streaming') {
            return;
        }

        status.value = next;
        error.value = message;
        stopTicker();
    }

    function handle(event: StreamEvent): void {
        const atMs = elapsed();
        totalEvents.value += 1;
        events.value = [...events.value, { number: totalEvents.value, type: event.type, atMs, detail: describe(event) }].slice(-MAX_LOGGED_EVENTS);
        now.value = atMs;

        // The model stream names its run in stream_start; outcomes without a model call carry it on their only event.
        if (invocationId.value === null && typeof event.invocation_id === 'string') {
            invocationId.value = event.invocation_id;
        }

        if (event.type === 'text_delta' && typeof event.delta === 'string' && event.delta !== '') {
            text.value += event.delta;
            tokens.value += 1;
            lastTokenAt.value = atMs;

            if (firstTokenAt.value === null) {
                firstTokenAt.value = atMs;
                ttftMs.value = atMs;
            }
        }

        hooks.onEvent?.(event);

        if (event.type === 'error') {
            finish('error', typeof event.message === 'string' ? event.message : 'The stream reported an error.');
        }
    }

    function reset(): void {
        text.value = '';
        events.value = [];
        totalEvents.value = 0;
        tokens.value = 0;
        ttftMs.value = null;
        error.value = null;
        invocationId.value = null;
        firstTokenAt.value = null;
        lastTokenAt.value = null;
        now.value = 0;
    }

    async function start(url: string, init: Pick<RequestInit, 'method' | 'body' | 'headers'> = {}): Promise<void> {
        if (status.value === 'streaming') {
            return;
        }

        reset();
        status.value = 'streaming';
        controller = new AbortController();
        const { signal } = controller;
        startedAt = performance.now();
        ticker = setInterval(() => {
            now.value = elapsed();
        }, TICK_MS);

        try {
            const response = await fetch(url, { ...init, signal, headers: { Accept: 'text/event-stream', ...(init.headers as Record<string, string> | undefined) } });

            if (!response.ok || response.body === null) {
                finish('error', response.ok ? 'Server error (empty response).' : failureMessage(response));

                return;
            }

            hooks.onResponse?.(response);

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let complete = false;

            while (!complete) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                const parsed = parseSseBuffer(buffer);
                buffer = parsed.rest;

                for (const payload of parsed.payloads) {
                    if (payload === '[DONE]') {
                        complete = true;
                        break;
                    }

                    try {
                        handle(JSON.parse(payload) as StreamEvent);
                    } catch {
                        handle({ type: 'unparsed' });
                    }
                }
            }

            if (complete) {
                await reader.cancel();
                finish('done');
            } else {
                finish('error', 'Connection lost.');
            }
        } catch (caught) {
            if (signal.aborted) {
                finish('stopped');
            } else {
                finish('error', caught instanceof Error && caught.name !== 'TypeError' ? caught.message : 'Connection lost.');
            }
        }
    }

    function stop(): void {
        if (status.value !== 'streaming') {
            return;
        }

        finish('stopped');
        controller?.abort();
    }

    onScopeDispose(() => {
        stop();
        stopTicker();
    });

    return { status, text, events, totalEvents, tokens, ttftMs, tokensPerSecond, error, invocationId, start, stop };
}
