<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabEventRow from '../../components/LabEventRow.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabTag from '../../components/LabTag.vue';
import { useEventStream } from '../../composables/useEventStream';
import type { StreamEvent } from '../../composables/useEventStream';
import { parseServerTiming } from '../../composables/useSearchRequest';
import type { ServerTimings } from '../../composables/useSearchRequest';

interface Source {
    n: number;
    id: number;
    source: string;
    path: string;
    heading: string | null;
    vector_similarity: number | null;
    text_rank: number | null;
}

interface Citations {
    cited: Array<{ n: number; id: number }>;
    unknown: number[];
}

interface Refusal {
    stage: string;
    reason: string;
}

interface RunRow {
    invocation_id: string;
    provider: string;
    model: string;
    prompt_version: string | null;
    tokens_in: number | null;
    tokens_out: number | null;
    ttft_ms: number | null;
    latency_ms: number;
    status: string;
}

interface CacheHit {
    kind: 'exact' | 'semantic';
    similarity: number;
    cached_at: string;
    saved_latency_ms: number;
    invocation_id: string;
}

type Segment = { text: string; n: number | null };

type Outcome = 'streaming' | 'done' | 'stopped' | 'error';

interface Turn {
    id: number;
    question: string;
    text: string;
    outcome: Outcome;
    stoppedTokens: number;
    error: string | null;
    sources: Source[];
    citations: Citations | null;
    noContext: string | null;
    refusal: Refusal | null;
    cacheHit: CacheHit | null;
    run: RunRow | null;
    runMessage: string | null;
}

const props = defineProps<{
    askUrl: string;
    runUrl: string;
    idPlaceholder: string;
    maxQuestionLength: number;
    maxHistoryTurns: number;
    maxTurnLength: number;
}>();

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

const question = ref('');
const turns = ref<Turn[]>([]);
const timings = ref<ServerTimings | null>(null);
const sourcesAtMs = ref<number | null>(null);
const useCache = ref(true);
let nextTurnId = 1;

function current(): Turn | undefined {
    return turns.value.at(-1);
}

function onEvent(event: StreamEvent): void {
    const turn = current();

    if (!turn) {
        return;
    }

    if (event.type === 'sources' && Array.isArray(event.sources)) {
        turn.sources = event.sources as Source[];
        sourcesAtMs.value = stream.events.value.at(-1)?.atMs ?? null;
    } else if (event.type === 'citations') {
        turn.citations = { cited: (event.cited ?? []) as Citations['cited'], unknown: (event.unknown ?? []) as number[] };
    } else if (event.type === 'cache_hit') {
        turn.cacheHit = {
            kind: event.kind === 'semantic' ? 'semantic' : 'exact',
            similarity: Number(event.similarity ?? 0),
            cached_at: String(event.cached_at ?? ''),
            saved_latency_ms: Number(event.saved_latency_ms ?? 0),
            invocation_id: String(event.invocation_id ?? ''),
        };
    } else if (event.type === 'no_context' && typeof event.message === 'string') {
        turn.noContext = event.message;
    } else if (event.type === 'refused') {
        turn.refusal = { stage: String(event.stage ?? ''), reason: String(event.reason ?? '') };

        if (event.replace === true) {
            stream.text.value = '';
        }
    }
}

const stream = useEventStream({
    onEvent,
    onResponse: (response) => {
        timings.value = parseServerTiming(response.headers.get('Server-Timing'));
    },
});

const isStreaming = computed(() => stream.status.value === 'streaming');
const length = computed(() => [...question.value].length);
const tooLong = computed(() => length.value > props.maxQuestionLength);
const canAsk = computed(() => !isStreaming.value && question.value.trim() !== '' && !tooLong.value);
const cacheHit = computed(() => current()?.cacheHit ?? null);
const hiddenEvents = computed(() => stream.totalEvents.value - stream.events.value.length);

const statusLabel = computed(() => ({
    idle: 'Ready',
    streaming: 'Streaming',
    done: 'Finished',
    stopped: 'Stopped',
    error: 'Failed',
})[stream.status.value]);

function isNewest(turn: Turn): boolean {
    return turn === current();
}

/**
 * The answer split into plain text and numbers that name one of that turn's sources. A marker is [n] or a comma list such
 * as [1, 2]; each known number inside the brackets is its own link, and unknown numbers stay text.
 */
function segmentsOf(turn: Turn): Segment[] {
    const known = new Set(turn.sources.map((source) => source.n));
    const parts: Segment[] = [];
    const answer = turn.text;
    let pending = '';
    let last = 0;

    const pushText = (text: string): void => {
        pending += text;
    };
    const flushText = (): void => {
        if (pending !== '') {
            parts.push({ text: pending, n: null });
            pending = '';
        }
    };

    for (const match of answer.matchAll(/\[(\d+(?:\s*,\s*\d+)*)\]/g)) {
        const start = match.index ?? 0;

        if (![...match[1].matchAll(/\d+/g)].some((item) => known.has(Number(item[0])))) {
            continue;
        }

        pushText(answer.slice(last, start));
        let cursor = 0;

        pushText('[');

        for (const item of match[1].matchAll(/\d+/g)) {
            const n = Number(item[0]);
            const at = item.index ?? 0;

            pushText(match[1].slice(cursor, at));
            cursor = at + item[0].length;

            if (known.has(n)) {
                flushText();
                parts.push({ text: item[0], n });
            } else {
                pushText(item[0]);
            }
        }

        pushText(match[1].slice(cursor) + ']');
        last = start + match[0].length;
    }

    pushText(answer.slice(last));
    flushText();

    return parts;
}

function isCited(turn: Turn, n: number): boolean {
    return turn.citations?.cited.some((item) => item.n === n) ?? false;
}

/**
 * How long ago an ISO-8601 time was, in the largest whole unit; "just now" under a minute.
 */
function ageLabel(iso: string): string {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    if (Number.isNaN(seconds) || seconds < 60) {
        return 'just now';
    }

    const units: Array<[string, number]> = [['day', 86400], ['hour', 3600], ['minute', 60]];
    const [name, size] = units.find(([, size]) => seconds >= size) ?? units[2];
    const count = Math.floor(seconds / size);

    return `${count} ${name}${count === 1 ? '' : 's'} ago`;
}

function sourceLabel(source: Source): string {
    return source.heading ? `${source.path} › ${source.heading}` : source.path;
}

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function loadRun(turn: Turn, invocationId: string): Promise<void> {
    turn.runMessage = 'Looking up the logged run…';

    for (let attempt = 0; attempt < RUN_LOOKUP_ATTEMPTS; attempt++) {
        if (attempt > 0) {
            await wait(RUN_LOOKUP_DELAY_MS);
        }

        if (!turns.value.includes(turn)) {
            return;
        }

        try {
            const response = await fetch(props.runUrl.replace(props.idPlaceholder, encodeURIComponent(invocationId)), {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                turn.run = (await response.json()) as RunRow;
                turn.runMessage = null;

                return;
            }

            if (response.status !== 404) {
                turn.runMessage = `Could not read the run (server error ${response.status}).`;

                return;
            }
        } catch {
            turn.runMessage = 'Could not read the run (connection lost).';

            return;
        }
    }

    turn.runMessage = 'Run not logged yet.';
}

/**
 * The last completed exchanges as alternating user and assistant turns. A stopped, refused, failed or empty answer is left
 * out with its question, so the roles always alternate and the model never reads text the user cut off. An answer longer than
 * the server's per-turn limit is left out the same way, because the server would reject the whole request.
 */
function history(): Array<{ role: 'user' | 'assistant'; content: string }> {
    return turns.value
        .filter((turn) => turn.outcome === 'done' && turn.refusal === null && turn.noContext === null && turn.text.trim() !== '' && [...turn.text.trim()].length <= props.maxTurnLength)
        .slice(-Math.floor(props.maxHistoryTurns / 2))
        .flatMap((turn) => [
            { role: 'user' as const, content: turn.question },
            { role: 'assistant' as const, content: turn.text.trim() },
        ]);
}

function ask(): void {
    if (!canAsk.value) {
        return;
    }

    const sent = question.value.trim();
    const body = { q: sent, cache: useCache.value, history: history() };

    timings.value = null;
    sourcesAtMs.value = null;
    turns.value.push(reactive<Turn>({
        id: nextTurnId++,
        question: sent,
        text: '',
        outcome: 'streaming',
        stoppedTokens: 0,
        error: null,
        sources: [],
        citations: null,
        noContext: null,
        refusal: null,
        cacheHit: null,
        run: null,
        runMessage: null,
    }));
    question.value = '';
    void stream.start(props.askUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    void nextTick(() => document.getElementById('chat-question')?.focus());
}

function newChat(): void {
    stream.stop();
    turns.value = [];
    timings.value = null;
    sourcesAtMs.value = null;
    question.value = '';
}

function showSource(turn: Turn, n: number): void {
    document.getElementById(`chat-source-${turn.id}-${n}`)?.focus();
}

watch(stream.text, (text) => {
    const turn = current();

    if (turn && turn.outcome === 'streaming') {
        turn.text = text;
    }
});

watch(stream.status, (status) => {
    const turn = current();

    if (!turn || status === 'idle' || status === 'streaming') {
        return;
    }

    turn.text = stream.text.value;
    turn.outcome = status;
    turn.stoppedTokens = stream.tokens.value;
    turn.error = stream.error.value;

    const invocationId = cacheHit.value?.invocation_id || stream.invocationId.value;

    if (invocationId) {
        void loadRun(turn, invocationId);
    } else if (status === 'done') {
        turn.runMessage = 'Logged without a stream id: see the runs table on the overview.';
    }
});

onBeforeUnmount(() => {
    turns.value = [];
});
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Ask the docs</h1>
    <p class="mt-2 text-[15px] text-muted">
        A conversation answered from the indexed docs with citations. Each follow-up carries the last few answers as history; sources are looked up for the newest question only.
    </p>

    <ol v-if="turns.length > 0" aria-label="Conversation" class="m-0 mt-6 flex list-none flex-col gap-8 p-0">
        <li v-for="turn in turns" :key="turn.id" class="min-w-0 border-t border-rule pt-6" :data-turn="turn.id">
            <h2 class="m-0 text-[13px] font-medium text-muted">You</h2>
            <p class="m-0 mt-1 whitespace-pre-wrap break-words text-[15px] font-medium">{{ turn.question }}</p>

            <h2 class="m-0 mt-4 text-[13px] font-medium text-muted">Answer</h2>
            <div v-if="turn.cacheHit" class="mt-2 min-w-0 border-l-2 border-signal pl-3 text-[13px]" data-state="cache-hit">
                <p class="m-0 font-semibold text-signal">Cached <span class="font-normal text-muted">({{ turn.cacheHit.kind }})</span></p>
                <p class="m-0 mt-1 text-muted">Matched an earlier question</p>
                <p class="m-0 mt-1 text-muted">
                    Similarity {{ turn.cacheHit.similarity.toFixed(3) }} · stored {{ ageLabel(turn.cacheHit.cached_at) }} · saved {{ turn.cacheHit.saved_latency_ms }} ms
                </p>
            </div>
            <component
                :is="isNewest(turn) ? 'output' : 'div'"
                v-bind="isNewest(turn) ? { 'aria-live': 'polite' } : {}"
                class="mt-2 block whitespace-pre-wrap break-words text-[15px] leading-relaxed"
                :class="isNewest(turn) ? 'min-h-12' : ''"
            ><template v-for="(segment, index) in segmentsOf(turn)" :key="index"><a
                v-if="segment.n !== null"
                :href="`#chat-source-${turn.id}-${segment.n}`"
                class="font-semibold text-signal underline"
                @click.prevent="showSource(turn, segment.n)"
            >{{ segment.text }}</a><template v-else>{{ segment.text }}</template></template><span v-if="turn.outcome === 'streaming'" class="text-signal" aria-hidden="true">▍</span></component>

            <p v-if="turn.noContext" class="mt-3 text-[15px] font-medium" data-state="no-context">{{ turn.noContext }}</p>
            <p v-if="turn.refusal" :role="isNewest(turn) ? 'alert' : undefined" class="mt-3 text-[15px] font-medium text-danger" data-state="refused">
                Refused ({{ turn.refusal.stage }}): {{ turn.refusal.reason }}
            </p>
            <p v-if="turn.citations && turn.citations.unknown.length > 0" class="mt-3 text-[13px] text-muted">
                The answer cites {{ turn.citations.unknown.map((n) => `[${n}]`).join(', ') }}, which {{ turn.citations.unknown.length === 1 ? 'is' : 'are' }} not among the sources.
            </p>
            <p v-if="turn.outcome === 'stopped'" class="mt-3 text-[13px] font-semibold text-muted" data-state="stopped">
                Stopped after {{ turn.stoppedTokens }} tokens. This answer is not sent as history.
            </p>
            <p v-if="turn.outcome === 'error' && turn.error" :role="isNewest(turn) ? 'alert' : undefined" class="mt-3 text-[15px] font-medium text-danger">{{ turn.error }}</p>

            <section v-if="turn.sources.length > 0" :aria-labelledby="`chat-sources-title-${turn.id}`" class="mt-4">
                <h3 :id="`chat-sources-title-${turn.id}`" class="m-0 text-[13px] font-medium text-muted">Sources</h3>
                <ol class="m-0 mt-2 flex list-none flex-col gap-3 p-0">
                    <li
                        v-for="source in turn.sources"
                        :id="`chat-source-${turn.id}-${source.n}`"
                        :key="source.n"
                        tabindex="-1"
                        class="min-w-0 border-l-2 pl-3"
                        :class="isCited(turn, source.n) ? 'border-signal' : 'border-rule'"
                        :data-cited="isCited(turn, source.n)"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-[13px] text-muted">[{{ source.n }}]</span>
                            <LabTag>{{ source.source }}</LabTag>
                            <span v-if="isCited(turn, source.n)" class="text-[13px] font-semibold text-signal">Cited</span>
                        </div>
                        <p class="m-0 mt-1 break-words font-mono text-[13px]">{{ sourceLabel(source) }}</p>
                    </li>
                </ol>
            </section>

            <details v-if="turn.run || turn.runMessage" class="mt-4 min-w-0">
                <summary class="cursor-pointer text-[13px] font-medium text-muted">Logged run</summary>
                <p v-if="turn.runMessage" class="mt-2 text-[13px] text-muted">{{ turn.runMessage }}</p>
                <dl v-if="turn.run" class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Provider" :value="turn.run.provider" />
                    <LabMetric label="Model" :value="turn.run.model" />
                    <LabMetric label="Tokens in" :value="turn.run.tokens_in" />
                    <LabMetric label="Tokens out" :value="turn.run.tokens_out" />
                    <LabMetric label="TTFT" :value="turn.run.ttft_ms" unit="ms" />
                    <LabMetric label="Latency" :value="turn.run.latency_ms" unit="ms" />
                    <LabMetric label="Status" :value="turn.run.status" />
                    <LabMetric label="Prompt" :value="turn.run.prompt_version" />
                </dl>
            </details>
        </li>
    </ol>

    <form class="mt-8 flex flex-col gap-3 border-t border-rule pt-6" @submit.prevent="ask">
        <div class="flex min-w-0 flex-col gap-1">
            <label for="chat-question" class="text-[13px] font-medium text-muted">{{ turns.length > 0 ? 'Follow-up question' : 'Question' }}</label>
            <textarea
                id="chat-question"
                v-model="question"
                rows="3"
                placeholder="e.g. how do queued jobs retry after a failure?"
                class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink placeholder:text-muted"
                :aria-invalid="tooLong"
                aria-describedby="chat-question-count"
            />
            <p id="chat-question-count" class="m-0 text-[13px]" :class="tooLong ? 'font-medium text-danger' : 'text-muted'">
                {{ length }} / {{ maxQuestionLength }} characters
            </p>
        </div>
        <div class="flex items-center gap-2">
            <input id="chat-use-cache" v-model="useCache" type="checkbox" class="size-4 accent-signal" />
            <label for="chat-use-cache" class="text-[13px] font-medium text-muted">Use cache (first question only)</label>
        </div>
        <div class="flex flex-wrap gap-2">
            <LabButton type="submit" :disabled="!canAsk">{{ isStreaming ? 'Asking…' : 'Ask' }}</LabButton>
            <LabButton variant="secondary" :disabled="!isStreaming" @click="stream.stop()">Stop</LabButton>
            <LabButton variant="secondary" :disabled="turns.length === 0" @click="newChat">New chat</LabButton>
        </div>
    </form>
    <p v-if="tooLong" role="alert" class="mt-2 text-[13px] text-danger">The question is limited to {{ maxQuestionLength }} characters.</p>

    <Teleport to="#lab-rail" defer>
        <div class="flex flex-col gap-6">
            <section aria-labelledby="chat-metrics-title">
                <h2 id="chat-metrics-title" class="m-0 text-[18px]">Timings</h2>
                <p class="mt-1 text-[13px] text-muted">
                    Newest question. State: <span :class="isStreaming ? 'text-signal' : ''">{{ statusLabel }}</span>
                </p>
                <dl class="m-0 mt-3 grid grid-cols-3 gap-4">
                    <LabMetric label="Embedding" :value="timings?.embed ?? null" unit="ms" />
                    <LabMetric label="Database" :value="timings?.db ?? null" unit="ms" />
                    <LabMetric label="Sources" :value="sourcesAtMs" unit="ms" />
                    <LabMetric label="First token" :value="stream.ttftMs.value" unit="ms" />
                    <LabMetric label="Tokens" :value="stream.tokens.value" :live="isStreaming" />
                    <LabMetric label="Tokens/s" :value="stream.tokensPerSecond.value" />
                </dl>
            </section>

            <section aria-labelledby="chat-events-title">
                <h2 id="chat-events-title" class="m-0 text-[18px]">Events</h2>
                <p class="mt-1 text-[13px] text-muted">
                    {{ stream.totalEvents.value }} received<span v-if="hiddenEvents > 0">, showing the last {{ stream.events.value.length }}</span>
                </p>
                <ol class="m-0 mt-2 max-h-80 list-none overflow-y-auto p-0">
                    <LabEventRow
                        v-for="event in stream.events.value"
                        :key="event.number"
                        :type="event.type"
                        :at-ms="event.atMs"
                        :detail="event.detail"
                    />
                </ol>
            </section>
        </div>
    </Teleport>
</template>
