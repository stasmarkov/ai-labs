<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { parseServerTiming } from '../../composables/useSearchRequest';
import { presetQuery } from '../../composables/usePresetQuery';
import type { ServerTimings } from '../../composables/useSearchRequest';
import type { GuideRunState, GuideStep } from '../../composables/useGuideSteps';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSegmented from '../../components/LabSegmented.vue';
import LabProviderSelect from '../../components/LabProviderSelect.vue';
import type { ProviderOption } from '../../components/LabProviderSelect.vue';
import { useProviderChoice } from '../../composables/useProviderChoice';
import LabTag from '../../components/LabTag.vue';
import LabTextInput from '../../components/LabTextInput.vue';

interface Listing {
    id: number;
    make: string;
    model: string;
    year: number;
    fuel: string;
    mileage_km: number;
    price_cents: number;
    currency: string;
    city: string;
}

type Outcome =
    | { kind: 'ok'; filters: Record<string, string | number>; listings: Listing[]; total: number }
    | { kind: 'invalid'; errors: Record<string, string[]> }
    | { kind: 'refused'; message: string; pattern: string }
    | { kind: 'failed'; message: string };

interface RunRow {
    invocation_id: string;
    agent?: string | null;
    provider: string;
    model: string;
    prompt_version: string | null;
    tokens_in: number | null;
    tokens_out: number | null;
    ttft_ms: number | null;
    latency_ms: number;
    status: string;
}

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

interface Answer {
    query: string;
    model: string | null;
    extracted: Record<string, unknown> | null;
    unsupported: string[];
    invocationId: string | null;
    elapsedMs: number;
    timings: ServerTimings | null;
    run: RunRow | null;
    runMessage: string | null;
    outcome: Outcome;
}

interface Body {
    message?: string;
    errors?: Record<string, string[]>;
    refused?: boolean;
    pattern?: string;
    extracted?: Record<string, unknown>;
    unsupported?: string[];
    invocation_id?: string;
    data?: {
        query: string;
        model: string;
        extracted: Record<string, unknown>;
        unsupported: string[];
        filters: Record<string, string | number>;
        listings: Listing[];
        meta: { total: number };
        invocation_id: string;
    };
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    searchUrl: string;
    runUrl: string;
    idPlaceholder: string;
    maxQueryLength: number;
    models: string[];
    enabledTextProviders: ProviderOption[];
}>();

const query = ref(presetQuery());
const model = ref(props.models[0] ?? '');
const { provider, override: providerOverride } = useProviderChoice(props.enabledTextProviders);
const loading = ref(false);
const answer = ref<Answer | null>(null);
const panelEvents = ref<string[]>([]);
const panelState = ref<GuideRunState>('idle');

const modelOptions = computed(() => props.models.map((name) => ({ value: name, label: name })));
const queryLength = computed(() => [...query.value.trim()].length);
const canSearch = computed(() => !loading.value && queryLength.value > 0 && queryLength.value <= props.maxQueryLength);

function formatPrice(listing: Listing): string {
    return new Intl.NumberFormat('en', { style: 'currency', currency: listing.currency, maximumFractionDigits: 0 }).format(listing.price_cents / 100);
}

function describeFailure(status: number, body: Body | null): string {
    if (status === 429) {
        return 'Too many requests, try again in a minute.';
    }

    return body?.message ?? `The server answered ${status}.`;
}

function panelEventFor(kind: Outcome['kind']): string {
    if (kind === 'ok') {
        return 'response_received';
    }

    if (kind === 'refused') {
        return 'refused';
    }

    return kind === 'failed' ? 'request_failed' : 'validation_failed';
}

async function search(): Promise<void> {
    const text = query.value.trim();
    const params = new URLSearchParams({ q: text });

    // The model list belongs to the configured provider; with an override the server uses that provider's own model.
    if (model.value !== '' && providerOverride.value === null) {
        params.set('model', model.value);
    }

    if (providerOverride.value !== null) {
        params.set('provider', providerOverride.value);
    }

    loading.value = true;
    panelEvents.value = ['request_sent'];
    panelState.value = 'streaming';
    const started = performance.now();
    const next: Answer = { query: text, model: model.value || null, extracted: null, unsupported: [], invocationId: null, elapsedMs: 0, timings: null, run: null, runMessage: null, outcome: { kind: 'failed', message: 'Connection lost.' } };

    try {
        const response = await fetch(`${props.searchUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });
        const body = (await response.json().catch(() => null)) as Body | null;

        next.timings = parseServerTiming(response.headers.get('Server-Timing'));

        next.invocationId = body?.invocation_id ?? body?.data?.invocation_id ?? null;

        if (response.ok && body?.data) {
            next.model = body.data.model;
            next.extracted = body.data.extracted;
            next.unsupported = body.data.unsupported;
            next.outcome = { kind: 'ok', filters: body.data.filters, listings: body.data.listings, total: body.data.meta.total };
        } else if (response.status === 422 && body?.refused === true) {
            next.outcome = { kind: 'refused', message: body.message ?? 'Refused.', pattern: body.pattern ?? '' };
        } else if (response.status === 422 && body?.extracted !== undefined) {
            next.extracted = body.extracted;
            next.unsupported = body.unsupported ?? [];
            next.outcome = { kind: 'invalid', errors: body.errors ?? {} };
        } else {
            next.outcome = { kind: 'failed', message: describeFailure(response.status, body) };
        }
    } catch {
        next.outcome = { kind: 'failed', message: 'Connection lost.' };
    }

    next.elapsedMs = performance.now() - started;
    answer.value = next;
    loading.value = false;
    panelEvents.value = [...panelEvents.value, panelEventFor(next.outcome.kind)];
    // A refusal is an answer (the guard did its job); a validation failure or a failed request marks its step failed.
    panelState.value = next.outcome.kind === 'ok' || next.outcome.kind === 'refused' ? 'done' : 'error';

    // Work through the reactive proxy: `next` is the raw object, and writes to it would not re-render.
    const shown = answer.value;

    if (shown.invocationId !== null) {
        await loadRun(shown, shown.invocationId);
    }
}

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function loadRun(target: Answer, invocationId: string): Promise<void> {
    target.runMessage = 'Looking up the logged run…';

    for (let attempt = 0; attempt < RUN_LOOKUP_ATTEMPTS; attempt++) {
        if (attempt > 0) {
            await wait(RUN_LOOKUP_DELAY_MS);
        }

        if (answer.value !== target) {
            return;
        }

        try {
            const response = await fetch(props.runUrl.replace(props.idPlaceholder, encodeURIComponent(invocationId)), {
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                target.run = (await response.json()) as RunRow;
                target.runMessage = null;

                return;
            }

            if (response.status !== 404) {
                target.runMessage = `Could not read the run (server error ${response.status}).`;

                return;
            }
        } catch {
            target.runMessage = 'Could not read the run (connection lost).';

            return;
        }
    }

    target.runMessage = 'Run not logged yet.';
}

function outcomeLabel(outcome: Outcome): string {
    switch (outcome.kind) {
        case 'ok':
            return 'Valid, listings queried';
        case 'invalid':
            return 'Invalid, no listing query ran';
        case 'refused':
            return 'Refused by the input guard, no model called';
        default:
            return 'Not completed';
    }
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Natural-language search</h1>
    <p class="mt-2 text-[15px] text-muted">
        A model turns your sentence into six listing filters. The filters are validated like a normal listings request before any query runs.
    </p>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="panelEvents" :state="panelState" />

    <form class="mt-6 flex flex-col gap-4" @submit.prevent="search">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="min-w-0 flex-1">
                <LabTextInput id="nl-query" v-model="query" type="search" label="Query" placeholder="e.g. diesel BMW after 2018 under 20k in Munich" />
            </div>
            <LabButton type="submit" :disabled="!canSearch">{{ loading ? 'Searching…' : 'Search' }}</LabButton>
        </div>
        <LabProviderSelect id="nl-provider" v-model="provider" :providers="enabledTextProviders" :disabled="loading" />
        <div v-if="models.length > 1 && providerOverride === null" class="flex min-w-0 flex-col gap-1">
            <span class="text-[13px] font-medium text-muted">Model</span>
            <LabSegmented v-model="model" label="Model" :options="modelOptions" />
        </div>
    </form>
    <p v-if="queryLength > maxQueryLength" role="alert" class="mt-2 text-[13px] text-danger">
        The query is limited to {{ maxQueryLength }} characters.
    </p>

    <div aria-live="polite" class="mt-8 min-w-0">
        <section v-if="answer" aria-labelledby="nl-result-title" class="border-t border-rule pt-6">
            <h2 id="nl-result-title" class="m-0 text-[18px]">Result</h2>

            <dl class="m-0 mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[8rem_1fr]">
                <dt class="text-[13px] text-muted">Query</dt>
                <dd class="m-0 min-w-0 break-words text-[15px]">{{ answer.query }}</dd>
                <dt class="text-[13px] text-muted">Model</dt>
                <dd class="m-0 min-w-0"><LabTag v-if="answer.model">{{ answer.model }}</LabTag><span v-else>—</span></dd>
                <dt class="text-[13px] text-muted">Validation</dt>
                <dd class="m-0 text-[15px] font-medium" :class="answer.outcome.kind === 'ok' ? 'text-success' : 'text-danger'">
                    {{ outcomeLabel(answer.outcome) }}
                </dd>
            </dl>

            <p v-if="answer.outcome.kind === 'failed'" role="alert" class="mt-4 text-[15px] font-medium text-danger">{{ answer.outcome.message }}</p>
            <p v-if="answer.outcome.kind === 'refused'" role="alert" class="mt-4 text-[15px] font-medium text-danger">{{ answer.outcome.message }}</p>

            <div v-if="answer.extracted" class="mt-4">
                <h3 class="m-0 text-[15px] font-semibold">Extracted JSON</h3>
                <pre class="mt-2 whitespace-pre-wrap break-words rounded-sm border border-rule p-3 font-mono text-[13px]">{{ JSON.stringify(answer.extracted, null, 2) }}</pre>
            </div>

            <div v-if="answer.outcome.kind === 'invalid'" class="mt-4">
                <h3 class="m-0 text-[15px] font-semibold">Validation errors</h3>
                <ul class="m-0 mt-2 list-none p-0">
                    <li v-for="(messages, field) in answer.outcome.errors" :key="field" role="alert" class="text-[15px] text-danger">
                        <span class="font-mono text-[13px]">{{ field }}</span>: {{ messages.join(' ') }}
                    </li>
                </ul>
            </div>

            <div v-if="answer.extracted" class="mt-4">
                <h3 class="m-0 text-[15px] font-semibold">Unsupported</h3>
                <p v-if="answer.unsupported.length === 0" class="mt-2 text-[13px] text-muted">Nothing in the query fell outside the six filters.</p>
                <ul v-else class="m-0 mt-2 flex list-none flex-wrap gap-2 p-0">
                    <li v-for="chip in answer.unsupported" :key="chip"><LabTag>{{ chip }}</LabTag></li>
                </ul>
            </div>

            <div v-if="answer.outcome.kind === 'ok'" class="mt-6 border-t border-rule pt-6">
                <h3 class="m-0 text-[15px] font-semibold">Listings · {{ answer.outcome.total }} found</h3>
                <p v-if="answer.outcome.listings.length === 0" class="mt-2 text-[15px] text-muted">No listings matched the filters.</p>
                <ul class="m-0 mt-3 flex list-none flex-col gap-3 p-0">
                    <li v-for="listing in answer.outcome.listings" :key="listing.id" class="min-w-0">
                        <span class="text-[15px] font-semibold">{{ listing.year }} {{ listing.make }} {{ listing.model }}</span>
                        <span class="ml-3 text-[13px] text-muted">{{ listing.fuel }} · {{ listing.mileage_km.toLocaleString('en') }} km · {{ listing.city }} · {{ formatPrice(listing) }}</span>
                    </li>
                </ul>
            </div>

            <p v-if="answer.invocationId" class="mt-6 text-[13px] text-muted">
                Logged run:
                <a :href="runUrl.replace(idPlaceholder, encodeURIComponent(answer.invocationId))" class="break-all font-mono text-ink underline">{{ answer.invocationId }}</a>
            </p>

            <section v-if="answer.run || answer.runMessage" aria-labelledby="nl-run-title" class="mt-4 min-w-0">
                <h3 id="nl-run-title" class="m-0 text-[15px] font-semibold">Logged run</h3>
                <p v-if="answer.runMessage" class="mt-2 text-[13px] text-muted">{{ answer.runMessage }}</p>
                <dl v-if="answer.run" class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Agent" :value="answer.run.agent ?? null" />
                    <LabMetric label="Provider" :value="answer.run.provider" />
                    <LabMetric label="Model" :value="answer.run.model" />
                    <LabMetric label="Prompt" :value="answer.run.prompt_version" />
                    <LabMetric label="Tokens in" :value="answer.run.tokens_in" />
                    <LabMetric label="Tokens out" :value="answer.run.tokens_out" />
                    <LabMetric label="TTFT" :value="answer.run.ttft_ms" unit="ms" />
                    <LabMetric label="Latency" :value="answer.run.latency_ms" unit="ms" />
                    <LabMetric label="Status" :value="answer.run.status" />
                </dl>
            </section>
        </section>
    </div>

    <Teleport to="#lab-rail" defer>
        <section aria-labelledby="nl-timings-title">
            <h2 id="nl-timings-title" class="m-0 text-[18px]">Timings</h2>
            <p class="mt-1 text-[13px] text-muted">Measured in the browser for the last search, model call included.</p>
            <dl class="m-0 mt-3 grid grid-cols-2 gap-4">
                <LabMetric label="Round trip" :value="answer === null ? null : Math.round(answer.elapsedMs)" unit="ms" />
                <LabMetric label="Model" :value="answer?.timings?.model ?? null" unit="ms" />
                <LabMetric label="Database" :value="answer?.timings?.db ?? null" unit="ms" />
                <LabMetric label="Listings" :value="answer?.outcome.kind === 'ok' ? answer.outcome.total : null" />
            </dl>
        </section>
    </Teleport>
</template>
