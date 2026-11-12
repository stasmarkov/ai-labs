<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabEventRow from '../../components/LabEventRow.vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabProviderSelect from '../../components/LabProviderSelect.vue';
import type { ProviderOption } from '../../components/LabProviderSelect.vue';
import LabTag from '../../components/LabTag.vue';
import { useEventStream } from '../../composables/useEventStream';
import type { StreamEvent } from '../../composables/useEventStream';
import type { GuideStep } from '../../composables/useGuideSteps';
import { presetQuery } from '../../composables/usePresetQuery';
import { useProviderChoice } from '../../composables/useProviderChoice';

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

interface Refusal {
    stage: string;
    reason: string;
}

type StepState = 'running' | 'done' | 'error' | 'stopped';

interface ToolStep {
    toolId: string;
    name: string;
    arguments: Record<string, unknown>;
    state: StepState;
    atMs: number;
    total: number | null;
    shown: number | null;
    listingIds: number[];
    error: string | null;
    final: boolean;
    fieldErrors: Record<string, string[]> | null;
}

type Outcome = 'streaming' | 'done' | 'stopped' | 'error';

interface Turn {
    id: number;
    question: string;
    text: string;
    outcome: Outcome;
    stoppedTokens: number;
    error: string | null;
    refusal: Refusal | null;
    steps: ToolStep[];
    run: RunRow | null;
    runMessage: string | null;
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    askUrl: string;
    runUrl: string;
    idPlaceholder: string;
    maxQuestionLength: number;
    maxHistoryTurns: number;
    maxTurnLength: number;
    enabledTextProviders: ProviderOption[];
    examples: string[];
}>();

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

const question = ref(presetQuery());
const turns = ref<Turn[]>([]);
const { provider, override: providerOverride } = useProviderChoice(props.enabledTextProviders);
let nextTurnId = 1;

function current(): Turn | undefined {
    return turns.value.at(-1);
}

const MAX_LINKED_LISTINGS = 10;

function parseResult(result: unknown): { total: number | null; shown: number | null; listingIds: number[] } {
    try {
        const decoded = typeof result === 'string' ? JSON.parse(result) : result;

        return {
            total: typeof decoded?.total === 'number' ? decoded.total : null,
            shown: typeof decoded?.shown === 'number' ? decoded.shown : null,
            listingIds: Array.isArray(decoded?.listings)
                ? decoded.listings
                    .map((listing: { id?: unknown }) => listing?.id)
                    .filter((id: unknown): id is number => typeof id === 'number')
                    .slice(0, MAX_LINKED_LISTINGS)
                : [],
        };
    } catch {
        return { total: null, shown: null, listingIds: [] };
    }
}

function onEvent(event: StreamEvent): void {
    const turn = current();

    if (!turn) {
        return;
    }

    const atMs = stream.events.value.at(-1)?.atMs ?? 0;

    if (event.type === 'tool_call') {
        turn.steps.push({
            toolId: String(event.tool_id ?? ''),
            name: String(event.tool_name ?? 'tool'),
            arguments: (event.arguments ?? {}) as Record<string, unknown>,
            state: 'running',
            atMs,
            total: null,
            shown: null,
            listingIds: [],
            error: null,
            final: false,
            fieldErrors: null,
        });
    } else if (event.type === 'tool_result') {
        const step = turn.steps.find((item) => item.toolId === event.tool_id);

        if (step) {
            step.state = 'done';
            Object.assign(step, parseResult(event.result));
        }
    } else if (event.type === 'tool_error') {
        const step = turn.steps.find((item) => item.toolId === event.tool_id);

        if (step) {
            step.state = 'error';
            step.error = String(event.error ?? 'failed');
            step.final = event.final === true;
            step.fieldErrors = (event.errors ?? null) as Record<string, string[]> | null;
        }
    } else if (event.type === 'refused') {
        turn.refusal = { stage: String(event.stage ?? ''), reason: String(event.reason ?? '') };
    }
}

const stream = useEventStream({ onEvent });

const isStreaming = computed(() => stream.status.value === 'streaming');
const length = computed(() => [...question.value].length);
const tooLong = computed(() => length.value > props.maxQuestionLength);
const canAsk = computed(() => !isStreaming.value && question.value.trim() !== '' && !tooLong.value);
const eventTypes = computed(() => stream.events.value.map((event) => event.type));
const hiddenEvents = computed(() => stream.totalEvents.value - stream.events.value.length);
const showExamples = computed(() => turns.value.length === 0 && !isStreaming.value);

const statusLabel = computed(() => ({
    idle: 'Ready',
    streaming: 'Streaming',
    done: 'Finished',
    stopped: 'Stopped',
    error: 'Failed',
})[stream.status.value]);

const stateLabels: Record<StepState, string> = {
    running: 'running',
    done: 'done',
    error: 'error',
    stopped: 'stopped',
};

function isNewest(turn: Turn): boolean {
    return turn === current();
}

function argumentsLabel(step: ToolStep): string {
    const filled = Object.fromEntries(Object.entries(step.arguments).filter(([, value]) => value !== null && value !== ''));

    return Object.keys(filled).length > 0 ? JSON.stringify(filled, null, 2) : 'no arguments';
}

function outcomeLabel(step: ToolStep): string {
    if (step.state === 'done') {
        return step.total === null ? 'result received' : `${step.shown ?? 0} of ${step.total} listings`;
    }

    if (step.state === 'error') {
        const fields = step.fieldErrors ? Object.keys(step.fieldErrors).join(', ') : '';

        return `${step.error}${fields ? ` (${fields})` : ''}${step.final ? ', no more calls' : ''}`;
    }

    return stateLabels[step.state];
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
 * out with its question, so the roles always alternate and the model never reads text the user cut off.
 */
function history(): Array<{ role: 'user' | 'assistant'; content: string }> {
    return turns.value
        .filter((turn) => turn.outcome === 'done' && turn.refusal === null && turn.text.trim() !== '' && [...turn.text.trim()].length <= props.maxTurnLength)
        .slice(-Math.floor(props.maxHistoryTurns / 2))
        .flatMap((turn) => [
            { role: 'user' as const, content: turn.question },
            { role: 'assistant' as const, content: answerWithSearch(turn) },
        ]);
}

/**
 * The answer plus the arguments of the last search that returned a result, so a follow-up keeps its filters. The
 * line is left out when it would push the turn past the length limit.
 */
function answerWithSearch(turn: Turn): string {
    const text = turn.text.trim();
    const searched = turn.steps.filter((step) => step.state === 'done').at(-1);

    if (!searched) {
        return text;
    }

    const filled = Object.fromEntries(Object.entries(searched.arguments).filter(([, value]) => value !== null && value !== ''));
    const withSearch = `${text}\n(searched: ${JSON.stringify(filled)})`;

    return Object.keys(filled).length === 0 || [...withSearch].length > props.maxTurnLength ? text : withSearch;
}

function ask(): void {
    if (!canAsk.value) {
        return;
    }

    const sent = question.value.trim();
    const body = { question: sent, history: history(), ...(providerOverride.value === null ? {} : { provider: providerOverride.value }) };

    turns.value.push(reactive<Turn>({
        id: nextTurnId++,
        question: sent,
        text: '',
        outcome: 'streaming',
        stoppedTokens: 0,
        error: null,
        refusal: null,
        steps: [],
        run: null,
        runMessage: null,
    }));
    question.value = '';
    void stream.start(props.askUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    void nextTick(() => document.getElementById('assistant-question')?.focus());
}

function useExample(example: string): void {
    question.value = example;
    void nextTick(() => document.getElementById('assistant-question')?.focus());
}

function newChat(): void {
    stream.stop();
    turns.value = [];
    question.value = '';
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

    for (const step of turn.steps) {
        if (step.state === 'running') {
            step.state = 'stopped';
        }
    }

    if (stream.invocationId.value) {
        void loadRun(turn, stream.invocationId.value);
    }
});

onBeforeUnmount(() => {
    turns.value = [];
});
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Buyer assistant</h1>
    <p class="mt-2 text-[15px] text-muted">
        Ask about cars in plain words. The model decides when to search the listings, calls a read-only search tool and answers from what the tool returned. Each tool call appears in the timeline.
    </p>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="eventTypes" :state="stream.status.value" />

    <section v-if="showExamples" aria-labelledby="assistant-empty-title" class="mt-6" data-state="empty">
        <h2 id="assistant-empty-title" class="m-0 text-[18px]">Nothing asked yet</h2>
        <p class="mt-1 text-[13px] text-muted">Pick an example or write your own question below.</p>
        <ul class="m-0 mt-3 flex list-none flex-wrap gap-2 p-0" aria-label="Example questions">
            <li v-for="example in examples" :key="example">
                <button
                    type="button"
                    class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink"
                    @click="useExample(example)"
                >
                    {{ example }}
                </button>
            </li>
        </ul>
    </section>

    <ol v-if="turns.length > 0" aria-label="Conversation" class="m-0 mt-6 flex list-none flex-col gap-8 p-0">
        <li v-for="turn in turns" :key="turn.id" class="min-w-0 border-t border-rule pt-6" :data-turn="turn.id">
            <h2 class="m-0 text-[13px] font-medium text-muted">You</h2>
            <p class="m-0 mt-1 whitespace-pre-wrap break-words text-[15px] font-medium">{{ turn.question }}</p>

            <section v-if="turn.steps.length > 0" :aria-labelledby="`assistant-steps-title-${turn.id}`" class="mt-4" data-section="timeline">
                <h3 :id="`assistant-steps-title-${turn.id}`" class="m-0 text-[13px] font-medium text-muted">Tool calls</h3>
                <ol class="m-0 mt-2 flex list-none flex-col gap-2 p-0">
                    <li
                        v-for="(step, index) in turn.steps"
                        :key="step.toolId || index"
                        class="min-w-0 border-l-2 pl-3"
                        :class="step.state === 'error' ? 'border-danger' : 'border-rule'"
                        :data-step-state="step.state"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-[13px] text-muted">{{ index + 1 }}</span>
                            <LabTag>{{ step.name }}</LabTag>
                            <span class="text-[13px] font-semibold" :class="step.state === 'error' ? 'text-danger' : step.state === 'done' ? 'text-signal' : 'text-muted'">
                                {{ stateLabels[step.state] }}
                            </span>
                            <span class="text-[13px] tabular-nums text-muted">{{ step.atMs }} ms</span>
                        </div>
                        <pre class="m-0 mt-1 whitespace-pre-wrap break-words font-mono text-[13px]">{{ argumentsLabel(step) }}</pre>
                        <p v-if="step.state !== 'running'" class="m-0 mt-1 text-[13px] text-muted">{{ outcomeLabel(step) }}</p>
                        <ul v-if="step.listingIds.length > 0" class="m-0 mt-1 flex list-none flex-wrap gap-x-3 gap-y-1 p-0 text-[13px]" data-section="listing-links">
                            <li v-for="id in step.listingIds" :key="id">
                                <a :href="`/listings/${id}`" class="font-mono text-signal underline">#{{ id }}</a>
                            </li>
                        </ul>
                    </li>
                </ol>
            </section>

            <h2 class="m-0 mt-4 text-[13px] font-medium text-muted">Answer</h2>
            <component
                :is="isNewest(turn) ? 'output' : 'div'"
                v-bind="isNewest(turn) ? { 'aria-live': 'polite' } : {}"
                class="mt-2 block whitespace-pre-wrap break-words text-[15px] leading-relaxed"
                :class="isNewest(turn) ? 'min-h-12' : ''"
            >{{ turn.text }}<span v-if="turn.outcome === 'streaming'" class="text-signal" aria-hidden="true">▍</span></component>

            <p v-if="turn.refusal" :role="isNewest(turn) ? 'alert' : undefined" class="mt-3 text-[15px] font-medium text-danger" data-state="refused">
                Refused ({{ turn.refusal.stage }}): {{ turn.refusal.reason }}
            </p>
            <p v-if="turn.outcome === 'stopped'" class="mt-3 text-[13px] font-semibold text-muted" data-state="stopped">
                Stopped after {{ turn.stoppedTokens }} tokens. This answer is not sent as history.
            </p>
            <p v-if="turn.outcome === 'error' && turn.error" :role="isNewest(turn) ? 'alert' : undefined" class="mt-3 text-[15px] font-medium text-danger">{{ turn.error }}</p>

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
            <label for="assistant-question" class="text-[13px] font-medium text-muted">{{ turns.length > 0 ? 'Follow-up question' : 'Question' }}</label>
            <textarea
                id="assistant-question"
                v-model="question"
                rows="3"
                placeholder="e.g. a petrol Golf in Berlin under 15000 euro"
                class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink placeholder:text-muted"
                :aria-invalid="tooLong"
                aria-describedby="assistant-question-count"
            />
            <p id="assistant-question-count" class="m-0 text-[13px]" :class="tooLong ? 'font-medium text-danger' : 'text-muted'">
                {{ length }} / {{ maxQuestionLength }} characters
            </p>
        </div>
        <LabProviderSelect id="assistant-provider" v-model="provider" :providers="enabledTextProviders" :disabled="isStreaming" />
        <div class="flex flex-wrap gap-2">
            <LabButton type="submit" :disabled="!canAsk">{{ isStreaming ? 'Asking…' : 'Ask' }}</LabButton>
            <LabButton variant="secondary" :disabled="!isStreaming" @click="stream.stop()">Stop</LabButton>
            <LabButton variant="secondary" :disabled="turns.length === 0" @click="newChat">New chat</LabButton>
        </div>
    </form>
    <p v-if="tooLong" role="alert" class="mt-2 text-[13px] text-danger">The question is limited to {{ maxQuestionLength }} characters.</p>

    <Teleport to="#lab-rail" defer>
        <div class="flex flex-col gap-6">
            <section aria-labelledby="assistant-metrics-title">
                <h2 id="assistant-metrics-title" class="m-0 text-[18px]">Timings</h2>
                <p class="mt-1 text-[13px] text-muted">
                    Newest question. State: <span :class="isStreaming ? 'text-signal' : ''">{{ statusLabel }}</span>
                </p>
                <dl class="m-0 mt-3 grid grid-cols-3 gap-4">
                    <LabMetric label="First token" :value="stream.ttftMs.value" unit="ms" />
                    <LabMetric label="Tokens" :value="stream.tokens.value" :live="isStreaming" />
                    <LabMetric label="Tokens/s" :value="stream.tokensPerSecond.value" />
                </dl>
            </section>

            <section aria-labelledby="assistant-events-title">
                <h2 id="assistant-events-title" class="m-0 text-[18px]">Events</h2>
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
