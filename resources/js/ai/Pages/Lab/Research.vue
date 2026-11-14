<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabProviderSelect from '../../components/LabProviderSelect.vue';
import LabTag from '../../components/LabTag.vue';
import type { ProviderOption } from '../../components/LabProviderSelect.vue';
import { useProviderChoice } from '../../composables/useProviderChoice';

type Status = 'running' | 'done' | 'failed' | 'refused';

interface Step {
    position: number;
    phase: 'plan' | 'search' | 'note' | 'report';
    sub_question_index: number | null;
    status: 'ok' | 'retried' | 'failed' | 'skipped';
    error: string | null;
    duration_ms: number;
    input: Record<string, unknown>;
    output: Record<string, unknown> | null;
    ai_run: { invocation_id: string; model: string; tokens_in: number | null; tokens_out: number | null } | null;
}

interface Source {
    n: number;
    path?: string;
    heading?: string | null;
}

interface Run {
    id: string;
    topic: string;
    status: Status;
    error: string | null;
    provider: string;
    model: string;
    sub_questions: string[] | null;
    steps: Step[];
    report: string | null;
    report_html: string | null;
    sources: Source[];
    counts: { model_calls: number; steps: number; failed_notes: number; tokens_in: number; tokens_out: number };
    started_at: string | null;
    finished_at: string | null;
}

interface RecentRun {
    id: string;
    topic: string;
    status: Status;
    model: string;
    started_at: string | null;
}

const POLL_MS = 1500;
const REPLAY_STEP_MS = 400;

const props = defineProps<{
    run: Run | null;
    recent: RecentRun[];
    startUrl: string;
    showUrl: string;
    pageUrl: string;
    runUrl: string;
    idPlaceholder: string;
    enabledTextProviders: ProviderOption[];
    limits: { min: number; max: number };
    examples: string[];
}>();

const { provider, override: providerOverride } = useProviderChoice(props.enabledTextProviders);

const topic = ref('');
const shown = ref<Run | null>(props.run);
const starting = ref(false);
const message = ref<string | null>(null);
const replayCount = ref<number | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let replayTimer: ReturnType<typeof setInterval> | null = null;
let token = 0;

const topicLength = computed(() => [...topic.value.trim()].length);
const topicValid = computed(() => topicLength.value >= props.limits.min && topicLength.value <= props.limits.max);
const polling = computed(() => shown.value?.status === 'running');
const replaying = computed(() => replayCount.value !== null && shown.value !== null && replayCount.value < shown.value.steps.length);
const visibleSteps = computed(() => {
    const steps = shown.value?.steps ?? [];

    return replayCount.value === null ? steps : steps.slice(0, replayCount.value);
});
const reportVisible = computed(() => replayCount.value === null || (shown.value !== null && replayCount.value >= shown.value.steps.length));

watch(
    () => props.run,
    (next) => {
        stopTimers();
        replayCount.value = null;
        shown.value = next;
        schedulePoll();
    },
);

function urlFor(template: string, id: string): string {
    return template.replace(props.idPlaceholder, encodeURIComponent(id));
}

function stopTimers(): void {
    token++;

    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    if (replayTimer !== null) {
        clearInterval(replayTimer);
        replayTimer = null;
    }
}

function schedulePoll(): void {
    if (shown.value?.status !== 'running') {
        return;
    }

    const mine = token;
    pollTimer = setTimeout(() => void poll(mine), POLL_MS);
}

async function poll(mine: number): Promise<void> {
    if (shown.value === null) {
        return;
    }

    try {
        const response = await fetch(urlFor(props.showUrl, shown.value.id), { headers: { Accept: 'application/json' } });

        if (mine !== token) {
            return;
        }

        if (response.ok) {
            shown.value = (await response.json()) as Run;
        } else {
            message.value = `The server answered ${response.status}.`;
            return;
        }
    } catch {
        if (mine === token) {
            message.value = 'Connection lost.';
        }

        return;
    }

    schedulePoll();
}

async function start(): Promise<void> {
    if (!topicValid.value || starting.value) {
        return;
    }

    stopTimers();
    starting.value = true;
    message.value = null;
    replayCount.value = null;

    const payload: Record<string, string> = { topic: topic.value.trim() };

    if (providerOverride.value !== null) {
        payload.provider = providerOverride.value;
    }

    try {
        const response = await fetch(props.startUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const body = (await response.json().catch(() => null)) as { id?: string; message?: string; errors?: Record<string, string[]> } | null;

        if (response.ok && body?.id) {
            await load(body.id);
        } else if (response.status === 422) {
            message.value = Object.values(body?.errors ?? {}).flat()[0] ?? body?.message ?? 'The topic was not accepted.';
        } else if (response.status === 429) {
            message.value = 'Too many runs, try again in a minute.';
        } else {
            message.value = body?.message ?? `The server answered ${response.status}.`;
        }
    } catch {
        message.value = 'Connection lost.';
    } finally {
        starting.value = false;
    }
}

async function load(id: string): Promise<void> {
    const response = await fetch(urlFor(props.showUrl, id), { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        message.value = `The server answered ${response.status}.`;

        return;
    }

    shown.value = (await response.json()) as Run;
    window.history.replaceState(window.history.state, '', urlFor(props.pageUrl, id));
    schedulePoll();
}

/** Plays the stored steps one by one; it only reads the run that is already loaded. */
function replay(): void {
    if (shown.value === null || shown.value.steps.length === 0 || polling.value) {
        return;
    }

    stopTimers();
    replayCount.value = 0;
    replayTimer = setInterval(() => {
        if (replayCount.value === null || shown.value === null) {
            return;
        }

        replayCount.value++;

        if (replayCount.value >= shown.value.steps.length && replayTimer !== null) {
            clearInterval(replayTimer);
            replayTimer = null;
            schedulePoll();
        }
    }, REPLAY_STEP_MS);
}

function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

function stepTitle(step: Step): string {
    const index = step.sub_question_index === null ? '' : ` ${step.sub_question_index + 1}`;

    return `${step.phase}${index}`;
}

function sourceLabel(source: Source): string {
    return [source.path, source.heading].filter(Boolean).join(' — ');
}

function statusText(status: Status): string {
    return { running: 'Running', done: 'Done', failed: 'Failed', refused: 'Refused' }[status];
}

schedulePoll();

onBeforeUnmount(stopTimers);
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Research</h1>
    <p class="mt-2 text-[15px] text-muted">
        A topic is planned into sub-questions, each is searched in the docs and noted, and a report cites the notes. Every step is stored, so a finished run can be replayed without a model call.
    </p>

    <form class="mt-6 flex flex-col gap-4" @submit.prevent="start">
        <div class="flex flex-col gap-1">
            <label for="research-topic" class="text-[13px] font-medium text-muted">Topic</label>
            <textarea
                id="research-topic"
                v-model="topic"
                rows="2"
                :maxlength="limits.max * 2"
                class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink placeholder:text-muted"
                placeholder="e.g. how do queued jobs retry and fail"
            />
            <p class="m-0 text-[13px] text-muted">{{ limits.min }} to {{ limits.max }} characters.</p>
        </div>

        <div class="flex flex-wrap gap-2" role="group" aria-label="Example topics">
            <button
                v-for="example in examples"
                :key="example"
                type="button"
                class="min-h-10 rounded-sm border border-rule px-3 text-left text-[13px] text-ink"
                @click="topic = example"
            >
                {{ example }}
            </button>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <LabProviderSelect id="research-provider" v-model="provider" :providers="enabledTextProviders" :disabled="starting" />
            <LabButton type="submit" :disabled="!topicValid || starting">{{ starting ? 'Starting…' : 'Start research' }}</LabButton>
        </div>
    </form>

    <p v-if="message" role="alert" class="mt-3 text-[15px] font-medium text-danger">{{ message }}</p>

    <section v-if="shown" class="mt-8 border-t border-rule pt-6" aria-labelledby="research-run-title">
        <h2 id="research-run-title" class="m-0 text-[18px]">{{ shown.topic }}</h2>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <LabTag caption="status">{{ statusText(shown.status) }}</LabTag>
            <LabTag caption="model">{{ shown.provider }} / {{ shown.model }}</LabTag>
            <span v-if="polling" role="status" class="text-[13px] text-muted">Working… the page checks every 1.5 seconds.</span>
        </div>
        <p v-if="shown.error" role="alert" class="mt-2 text-[15px] font-medium text-danger">{{ shown.error }}</p>

        <dl class="m-0 mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <LabMetric label="Model calls" :value="shown.counts.model_calls" />
            <LabMetric label="Steps" :value="shown.counts.steps" />
            <LabMetric label="Tokens in" :value="shown.counts.tokens_in" />
            <LabMetric label="Tokens out" :value="shown.counts.tokens_out" />
        </dl>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <h3 class="m-0 text-[15px] font-semibold">Steps</h3>
            <LabButton variant="secondary" :disabled="replaying || polling || shown.steps.length === 0" @click="replay">
                {{ replaying ? 'Replaying…' : 'Replay' }}
            </LabButton>
            <span v-if="polling" class="text-[13px] text-muted">Replay is available when the run ends.</span>
        </div>

        <p v-if="shown.steps.length === 0" class="mt-2 text-[15px] text-muted">No step is stored yet.</p>
        <ol class="m-0 mt-3 flex list-none flex-col gap-3 p-0" aria-label="Step timeline">
            <li v-for="step in visibleSteps" :key="step.position" class="min-w-0 border-l-2 pl-3" :class="step.status === 'failed' ? 'border-danger' : 'border-rule'">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-[13px] text-muted">#{{ step.position }}</span>
                    <span class="text-[15px] font-semibold">{{ stepTitle(step) }}</span>
                    <LabTag>{{ step.status }}</LabTag>
                    <span class="font-mono text-[13px] tabular-nums text-muted">{{ step.duration_ms }} ms</span>
                    <template v-if="step.ai_run">
                        <span class="font-mono text-[13px] text-muted">{{ step.ai_run.model }} · {{ step.ai_run.tokens_in ?? '—' }} in · {{ step.ai_run.tokens_out ?? '—' }} out</span>
                        <a :href="urlFor(runUrl, step.ai_run.invocation_id)" class="text-[13px] text-ink underline">Run log</a>
                    </template>
                </div>
                <p v-if="step.error" role="alert" class="m-0 mt-1 text-[13px] text-danger">{{ step.error }}</p>
                <details class="mt-1">
                    <summary class="cursor-pointer text-[13px] text-muted">Input and output</summary>
                    <p class="m-0 mt-1 text-[13px] font-medium">Input</p>
                    <pre class="m-0 overflow-x-auto font-mono text-[13px]">{{ pretty(step.input) }}</pre>
                    <p class="m-0 mt-1 text-[13px] font-medium">Output</p>
                    <pre class="m-0 overflow-x-auto font-mono text-[13px]">{{ pretty(step.output) }}</pre>
                </details>
            </li>
        </ol>

        <template v-if="reportVisible && shown.report_html">
            <h3 class="m-0 mt-6 text-[15px] font-semibold">Report</h3>
            <!-- The server escapes raw HTML in the model's markdown, replaces images by their alt text and keeps only the source anchors of links before this string is sent. -->
            <div class="lab-prose mt-2 max-w-3xl text-[15px]" v-html="shown.report_html" />

            <h3 v-if="shown.sources.length > 0" class="m-0 mt-6 text-[15px] font-semibold">Sources</h3>
            <ol class="m-0 mt-2 flex list-none flex-col gap-1 p-0">
                <li v-for="source in shown.sources" :id="`research-source-${source.n}`" :key="source.n" class="break-words text-[13px]">
                    <span class="font-mono text-muted">[{{ source.n }}]</span> <span class="font-mono">{{ sourceLabel(source) }}</span>
                </li>
            </ol>
        </template>
    </section>

    <section class="mt-8 border-t border-rule pt-6" aria-labelledby="research-recent-title">
        <h2 id="research-recent-title" class="m-0 text-[18px]">Recent runs</h2>
        <p v-if="recent.length === 0" class="mt-2 text-[15px] text-muted">No run yet.</p>
        <ul class="m-0 mt-3 flex list-none flex-col gap-2 p-0">
            <li v-for="item in recent" :key="item.id" class="flex min-w-0 flex-wrap items-center gap-2">
                <LabTag>{{ item.status }}</LabTag>
                <Link :href="urlFor(pageUrl, item.id)" class="min-w-0 break-words text-[15px] text-ink underline">{{ item.topic }}</Link>
                <span class="font-mono text-[13px] text-muted">{{ item.model }}</span>
            </li>
        </ul>
    </section>
</template>
