<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabEventRow from '../../components/LabEventRow.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabTextInput from '../../components/LabTextInput.vue';
import { useEventStream } from '../../composables/useEventStream';

interface ListingOption {
    id: number;
    title: string;
}

interface RunRow {
    invocation_id: string;
    agent: string;
    provider: string;
    model: string;
    prompt_version: string | null;
    tokens_in: number | null;
    tokens_out: number | null;
    ttft_ms: number | null;
    latency_ms: number;
    status: string;
    error_message: string | null;
    created_at: string;
}

const props = defineProps<{
    listings: ListingOption[];
    describeUrl: string;
    runUrl: string;
    idPlaceholder: string;
}>();

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

const stream = useEventStream();
const selectedId = ref(props.listings[0] !== undefined ? String(props.listings[0].id) : '');
const freeId = ref('');
const run = ref<RunRow | null>(null);
const runMessage = ref<string | null>(null);
let lookupToken = 0;

const listingId = computed(() => (freeId.value.trim() !== '' ? freeId.value.trim() : selectedId.value));
const isStreaming = computed(() => stream.status.value === 'streaming');
const canStart = computed(() => !isStreaming.value && /^\d+$/.test(listingId.value));

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

const statusLabel = computed(() => ({
    idle: 'Ready',
    streaming: 'Streaming',
    done: 'Finished',
    stopped: 'Stopped',
    error: 'Failed',
})[stream.status.value]);

const hiddenEvents = computed(() => stream.totalEvents.value - stream.events.value.length);

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

function start(): void {
    lookupToken++;
    run.value = null;
    runMessage.value = null;
    void stream.start(props.describeUrl.replace(props.idPlaceholder, listingId.value));
}

watch(stream.status, (status) => {
    if (status === 'done' && stream.invocationId.value !== null) {
        void loadRun(stream.invocationId.value);
    }

    if (status === 'done' && stream.invocationId.value === null) {
        runMessage.value = 'Run not logged yet.';
    }
});

onBeforeUnmount(() => {
    lookupToken++;
});
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Describe a listing</h1>
    <p class="mt-2 text-[15px] text-muted">
        Streams the description as server-sent events and shows what arrives, when.
    </p>

    <form class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="start">
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <label for="describe-listing" class="text-[13px] font-medium text-muted">Listing</label>
            <select
                id="describe-listing"
                v-model="selectedId"
                class="min-h-10 w-full min-w-0 rounded-sm border border-rule bg-white px-3 text-[15px] text-ink"
            >
                <option v-for="listing in listings" :key="listing.id" :value="String(listing.id)">
                    {{ listing.id }} · {{ listing.title }}
                </option>
            </select>
        </div>
        <div class="sm:w-40">
            <LabTextInput id="describe-free-id" v-model="freeId" label="Or a listing id" placeholder="e.g. 4812" />
        </div>
        <div class="flex gap-2">
            <LabButton type="submit" :disabled="!canStart">Start</LabButton>
            <LabButton variant="secondary" :disabled="!isStreaming" @click="stream.stop()">Stop</LabButton>
        </div>
    </form>

    <section aria-labelledby="describe-output-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="describe-output-title" class="m-0 text-[18px]">Description</h2>
        <output
            aria-live="polite"
            class="mt-3 block min-h-24 whitespace-pre-wrap text-[15px] leading-relaxed"
        >{{ stream.text.value }}<span v-if="isStreaming" class="text-signal" aria-hidden="true">▍</span></output>
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
            <section aria-labelledby="describe-metrics-title">
                <h2 id="describe-metrics-title" class="m-0 text-[18px]">Timings</h2>
                <p class="mt-1 text-[13px] text-muted">
                    State: <span :class="isStreaming ? 'text-signal' : ''">{{ statusLabel }}</span>
                </p>
                <dl class="m-0 mt-3 grid grid-cols-3 gap-4">
                    <LabMetric label="First token" :value="stream.ttftMs.value" unit="ms" />
                    <LabMetric label="Tokens" :value="stream.tokens.value" :live="isStreaming" />
                    <LabMetric label="Tokens/s" :value="stream.tokensPerSecond.value" />
                </dl>
            </section>

            <section aria-labelledby="describe-events-title">
                <h2 id="describe-events-title" class="m-0 text-[18px]">Events</h2>
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

            <section v-if="run || runMessage" aria-labelledby="describe-run-title">
                <h2 id="describe-run-title" class="m-0 text-[18px]">Logged run</h2>
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
