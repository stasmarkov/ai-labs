<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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

const props = defineProps<{
    askUrl: string;
    runUrl: string;
    idPlaceholder: string;
    maxQuestionLength: number;
}>();

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

const question = ref('');
const sources = ref<Source[]>([]);
const citations = ref<Citations | null>(null);
const noContext = ref<string | null>(null);
const refusal = ref<Refusal | null>(null);
const timings = ref<ServerTimings | null>(null);
const sourcesAtMs = ref<number | null>(null);
const useCache = ref(true);
const cacheHit = ref<CacheHit | null>(null);
const run = ref<RunRow | null>(null);
const runMessage = ref<string | null>(null);
let lookupToken = 0;

function onEvent(event: StreamEvent): void {
    if (event.type === 'sources' && Array.isArray(event.sources)) {
        sources.value = event.sources as Source[];
        sourcesAtMs.value = stream.events.value.at(-1)?.atMs ?? null;
    } else if (event.type === 'citations') {
        citations.value = { cited: (event.cited ?? []) as Citations['cited'], unknown: (event.unknown ?? []) as number[] };
    } else if (event.type === 'cache_hit') {
        cacheHit.value = {
            kind: event.kind === 'semantic' ? 'semantic' : 'exact',
            similarity: Number(event.similarity ?? 0),
            cached_at: String(event.cached_at ?? ''),
            saved_latency_ms: Number(event.saved_latency_ms ?? 0),
            invocation_id: String(event.invocation_id ?? ''),
        };
    } else if (event.type === 'no_context' && typeof event.message === 'string') {
        noContext.value = event.message;
    } else if (event.type === 'refused') {
        refusal.value = { stage: String(event.stage ?? ''), reason: String(event.reason ?? '') };

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
const hiddenEvents = computed(() => stream.totalEvents.value - stream.events.value.length);

const statusLabel = computed(() => ({
    idle: 'Ready',
    streaming: 'Streaming',
    done: 'Finished',
    stopped: 'Stopped',
    error: 'Failed',
})[stream.status.value]);

const statusMessage = computed<string | null>(() => {
    switch (stream.status.value) {
        case 'stopped':
            return `Stopped after ${stream.tokens.value} tokens. The server may not have logged this run.`;
        case 'error':
            return stream.error.value;
        default:
            return null;
    }
});

const knownNumbers = computed(() => new Set(sources.value.map((source) => source.n)));

/**
 * The answer split into plain text and numbers that name a known source. A marker is [n] or a comma list such as [1, 2];
 * each known number inside the brackets is its own link, and unknown numbers stay text.
 */
const segments = computed<Segment[]>(() => {
    const parts: Segment[] = [];
    const answer = stream.text.value;
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

        if (![...match[1].matchAll(/\d+/g)].some((item) => knownNumbers.value.has(Number(item[0])))) {
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

            if (knownNumbers.value.has(n)) {
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
});

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

async function loadRun(invocationId: string): Promise<void> {
    const token = ++lookupToken;
    runMessage.value = 'Looking up the logged run…';

    for (let attempt = 0; attempt < RUN_LOOKUP_ATTEMPTS; attempt++) {
        if (attempt > 0) {
            await wait(RUN_LOOKUP_DELAY_MS);
        }

        if (token !== lookupToken) {
            return;
        }

        try {
            const response = await fetch(props.runUrl.replace(props.idPlaceholder, encodeURIComponent(invocationId)), {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                run.value = (await response.json()) as RunRow;
                runMessage.value = null;

                return;
            }

            if (response.status !== 404) {
                runMessage.value = `Could not read the run (server error ${response.status}).`;

                return;
            }
        } catch {
            runMessage.value = 'Could not read the run (connection lost).';

            return;
        }
    }

    runMessage.value = 'Run not logged yet.';
}

function ask(): void {
    lookupToken++;
    sources.value = [];
    citations.value = null;
    cacheHit.value = null;
    noContext.value = null;
    refusal.value = null;
    timings.value = null;
    sourcesAtMs.value = null;
    run.value = null;
    runMessage.value = null;
    void stream.start(props.askUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ q: question.value.trim(), cache: useCache.value }),
    });
}

function showSource(n: number): void {
    document.getElementById(`chat-source-${n}`)?.focus();
}

watch(stream.status, (status) => {
    if (status !== 'done' && status !== 'error') {
        return;
    }

    const invocationId = cacheHit.value?.invocation_id || stream.invocationId.value;

    if (invocationId) {
        void loadRun(invocationId);
    } else if (status === 'done') {
        runMessage.value = 'Logged without a stream id: see the runs table on the overview.';
    }
});

onBeforeUnmount(() => {
    lookupToken++;
});
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Ask the docs</h1>
    <p class="mt-2 text-[15px] text-muted">
        One question, answered from the indexed docs with citations. Shows each step of the request.
    </p>

    <form class="mt-6 flex flex-col gap-3" @submit.prevent="ask">
        <div class="flex min-w-0 flex-col gap-1">
            <label for="chat-question" class="text-[13px] font-medium text-muted">Question</label>
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
            <label for="chat-use-cache" class="text-[13px] font-medium text-muted">Use cache</label>
        </div>
        <div class="flex gap-2">
            <LabButton type="submit" :disabled="!canAsk">{{ isStreaming ? 'Asking…' : 'Ask' }}</LabButton>
            <LabButton variant="secondary" :disabled="!isStreaming" @click="stream.stop()">Stop</LabButton>
        </div>
    </form>
    <p v-if="tooLong" role="alert" class="mt-2 text-[13px] text-danger">The question is limited to {{ maxQuestionLength }} characters.</p>

    <section v-if="sources.length > 0" aria-labelledby="chat-sources-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="chat-sources-title" class="m-0 text-[18px]">Sources</h2>
        <p class="mt-1 text-[13px] text-muted">The chunks given to the model, in citation order.</p>
        <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
            <li
                v-for="source in sources"
                :id="`chat-source-${source.n}`"
                :key="source.n"
                tabindex="-1"
                class="min-w-0 border-l-2 pl-3"
                :class="citations?.cited.some((item) => item.n === source.n) ? 'border-signal' : 'border-rule'"
                :data-cited="citations?.cited.some((item) => item.n === source.n) ?? false"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-[13px] text-muted">[{{ source.n }}]</span>
                    <LabTag>{{ source.source }}</LabTag>
                    <span v-if="citations?.cited.some((item) => item.n === source.n)" class="text-[13px] font-semibold text-signal">Cited</span>
                </div>
                <p class="m-0 mt-1 break-words font-mono text-[13px]">{{ sourceLabel(source) }}</p>
            </li>
        </ol>
    </section>

    <section aria-labelledby="chat-answer-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="chat-answer-title" class="m-0 text-[18px]">Answer</h2>
        <div v-if="cacheHit" class="mt-3 min-w-0 border-l-2 border-signal pl-3 text-[13px]" data-state="cache-hit">
            <p class="m-0 font-semibold text-signal">Cached <span class="font-normal text-muted">({{ cacheHit.kind }})</span></p>
            <p class="m-0 mt-1 text-muted">Matched an earlier question</p>
            <p class="m-0 mt-1 text-muted">
                Similarity {{ cacheHit.similarity.toFixed(3) }} · stored {{ ageLabel(cacheHit.cached_at) }} · saved {{ cacheHit.saved_latency_ms }} ms
            </p>
        </div>
        <output
            aria-live="polite"
            class="mt-3 block min-h-24 whitespace-pre-wrap break-words text-[15px] leading-relaxed"
        ><template v-for="(segment, index) in segments" :key="index"><a
            v-if="segment.n !== null"
            :href="`#chat-source-${segment.n}`"
            class="font-semibold text-signal underline"
            @click.prevent="showSource(segment.n)"
        >{{ segment.text }}</a><template v-else>{{ segment.text }}</template></template><span v-if="isStreaming" class="text-signal" aria-hidden="true">▍</span></output>

        <p v-if="noContext" class="mt-3 text-[15px] font-medium" data-state="no-context">{{ noContext }}</p>
        <p v-if="refusal" role="alert" class="mt-3 text-[15px] font-medium text-danger" data-state="refused">
            Refused ({{ refusal.stage }}): {{ refusal.reason }}
        </p>
        <p v-if="citations && citations.unknown.length > 0" class="mt-3 text-[13px] text-muted">
            The answer cites {{ citations.unknown.map((n) => `[${n}]`).join(', ') }}, which {{ citations.unknown.length === 1 ? 'is' : 'are' }} not among the sources.
        </p>
        <p
            v-if="statusMessage"
            role="alert"
            class="mt-3 text-[15px] font-medium"
            :class="stream.status.value === 'error' ? 'text-danger' : 'text-muted'"
        >
            {{ statusMessage }}
        </p>
    </section>

    <Teleport to="#lab-rail" defer>
        <div class="flex flex-col gap-6">
            <section aria-labelledby="chat-metrics-title">
                <h2 id="chat-metrics-title" class="m-0 text-[18px]">Timings</h2>
                <p class="mt-1 text-[13px] text-muted">
                    State: <span :class="isStreaming ? 'text-signal' : ''">{{ statusLabel }}</span>
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

            <section v-if="run || runMessage" aria-labelledby="chat-run-title">
                <h2 id="chat-run-title" class="m-0 text-[18px]">Logged run</h2>
                <p v-if="runMessage" class="mt-2 text-[13px] text-muted">{{ runMessage }}</p>
                <dl v-if="run" class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Provider" :value="run.provider" />
                    <LabMetric label="Model" :value="run.model" />
                    <LabMetric label="Tokens in" :value="run.tokens_in" />
                    <LabMetric label="Tokens out" :value="run.tokens_out" />
                    <LabMetric label="TTFT" :value="run.ttft_ms" unit="ms" />
                    <LabMetric label="Latency" :value="run.latency_ms" unit="ms" />
                    <LabMetric label="Status" :value="run.status" />
                    <LabMetric label="Prompt" :value="run.prompt_version" />
                </dl>
            </section>
        </div>
    </Teleport>
</template>
