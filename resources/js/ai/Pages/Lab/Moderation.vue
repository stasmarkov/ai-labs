<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabProviderSelect from '../../components/LabProviderSelect.vue';
import type { ProviderOption } from '../../components/LabProviderSelect.vue';
import LabTag from '../../components/LabTag.vue';
import LabTextInput from '../../components/LabTextInput.vue';
import { useProviderChoice } from '../../composables/useProviderChoice';
import type { GuideRunState, GuideStep } from '../../composables/useGuideSteps';

interface FormListing {
    id: number | null;
    label: string;
    title: string;
    price_eur: number;
    make: string;
    model: string;
    year: number;
    mileage_km: number;
    city: string;
    description: string;
}

interface Reason {
    key: string;
    definition: string;
}

interface Facts {
    market_median_eur: number | null;
    price_ratio: number | null;
    vins_in_text: string[];
    vin_reported: boolean;
    injection_pattern: string | null;
}

interface Result {
    status: 'classified' | 'invalid';
    risk: number | null;
    risky: boolean | null;
    reasons: string[];
    explanation: string | null;
    facts: Facts;
    threshold: number;
    provider: string;
    model: string;
    prompt_version: string;
    attempts: number;
    invocation_ids: string[];
    errors: Record<string, string[]>;
    model_risk: number | null;
    floors: string[];
}

interface RunRow {
    invocation_id: string;
    provider: string;
    model: string;
    prompt_version: string | null;
    tokens_in: number | null;
    tokens_out: number | null;
    latency_ms: number;
    status: string;
}

interface Body {
    message?: string;
    errors?: Record<string, string[]>;
    invocation_ids?: string[];
    data?: Result;
}

type Outcome =
    | { kind: 'ok'; result: Result }
    | { kind: 'invalid-input'; errors: Record<string, string[]> }
    | { kind: 'failed'; message: string; invocationIds: string[] };

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    moderateUrl: string;
    runUrl: string;
    idPlaceholder: string;
    enabledTextProviders: ProviderOption[];
    limits: { title: number; name: number; city: number; description: number; price_eur: number; mileage_km: number; min_year: number; max_year: number };
    reasons: Reason[];
    listings: FormListing[];
    examples: FormListing[];
}>();

const RUN_LOOKUP_ATTEMPTS = 5;
const RUN_LOOKUP_DELAY_MS = 300;

const { provider, override: providerOverride } = useProviderChoice(props.enabledTextProviders);
const pickedId = ref('');
const listingId = ref<number | null>(null);
const title = ref('');
const priceEur = ref('');
const make = ref('');
const model = ref('');
const year = ref('');
const mileageKm = ref('');
const city = ref('');
const description = ref('');
const loading = ref(false);
const outcome = ref<Outcome | null>(null);
const run = ref<RunRow | null>(null);
const runMessage = ref<string | null>(null);
const panelEvents = ref<string[]>([]);
const panelState = ref<GuideRunState>('idle');
let lookupToken = 0;

const definitions = computed(() => Object.fromEntries(props.reasons.map((reason) => [reason.key, reason.definition])));
const descriptionLength = computed(() => [...description.value].length);
const filled = computed(() => [title, priceEur, make, model, year, mileageKm, city, description].every((field) => field.value.trim() !== ''));
const canSubmit = computed(() => !loading.value && filled.value && descriptionLength.value <= props.limits.description);
const result = computed(() => (outcome.value?.kind === 'ok' ? outcome.value.result : null));
const classified = computed(() => (result.value?.status === 'classified' ? result.value : null));
const answeringRunId = computed(() => {
    const ids = result.value?.invocation_ids ?? (outcome.value?.kind === 'failed' ? outcome.value.invocationIds : []);

    return ids.length > 0 ? ids[ids.length - 1] : null;
});
const riskPercent = computed(() => Math.round((classified.value?.risk ?? 0) * 100));
const thresholdPercent = computed(() => Math.round((result.value?.threshold ?? 0) * 100));

function fill(listing: FormListing): void {
    listingId.value = listing.id;
    title.value = listing.title;
    priceEur.value = String(listing.price_eur);
    make.value = listing.make;
    model.value = listing.model;
    year.value = String(listing.year);
    mileageKm.value = String(listing.mileage_km);
    city.value = listing.city;
    description.value = listing.description;
}

function pick(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const listing = props.listings.find((candidate) => String(candidate.id) === value);

    if (listing !== undefined) {
        fill(listing);
    }
}

function loadExample(example: FormListing): void {
    pickedId.value = '';
    fill(example);
}

// Editing a field turns a stored listing into a new one, so its id is no longer sent.
function edited(): void {
    listingId.value = null;
    pickedId.value = '';
}

function formatEur(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat('en', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value);
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

async function submit(): Promise<void> {
    lookupToken++;
    run.value = null;
    runMessage.value = null;
    loading.value = true;
    panelEvents.value = ['request_sent'];
    panelState.value = 'streaming';

    const payload: Record<string, unknown> = {
        title: title.value.trim(),
        price_eur: Number(priceEur.value),
        make: make.value.trim(),
        model: model.value.trim(),
        year: Number(year.value),
        mileage_km: Number(mileageKm.value),
        city: city.value.trim(),
        description: description.value.trim(),
    };

    if (listingId.value !== null) {
        payload.listing_id = listingId.value;
    }

    if (providerOverride.value !== null) {
        payload.provider = providerOverride.value;
    }

    let next: Outcome;

    try {
        const response = await fetch(props.moderateUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const body = (await response.json().catch(() => null)) as Body | null;

        if (response.ok && body?.data) {
            next = { kind: 'ok', result: body.data };
        } else if (response.status === 422 && body?.errors !== undefined) {
            next = { kind: 'invalid-input', errors: body.errors };
        } else if (response.status === 429) {
            next = { kind: 'failed', message: 'Too many requests, try again in a minute.', invocationIds: [] };
        } else {
            next = { kind: 'failed', message: body?.message ?? `The server answered ${response.status}.`, invocationIds: body?.invocation_ids ?? [] };
        }
    } catch {
        next = { kind: 'failed', message: 'Connection lost.', invocationIds: [] };
    }

    outcome.value = next;
    loading.value = false;
    panelEvents.value = [...panelEvents.value, next.kind === 'ok' && next.result.status === 'classified' ? 'response_received' : next.kind === 'failed' ? 'request_failed' : 'validation_failed'];
    panelState.value = next.kind === 'ok' && next.result.status === 'classified' ? 'done' : 'error';

    if (answeringRunId.value !== null) {
        await loadRun(answeringRunId.value);
    }
}

onBeforeUnmount(() => {
    lookupToken++;
});
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Moderate a listing</h1>
    <p class="mt-2 text-[15px] text-muted">
        A model rates how risky a listing is for a buyer. The app computes the facts it cannot know, such as the market price, and checks the answer before showing it.
    </p>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="panelEvents" :state="panelState" />

    <form class="mt-6 flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <label for="moderation-pick" class="text-[13px] font-medium text-muted">Pick a listing</label>
                <select
                    id="moderation-pick"
                    v-model="pickedId"
                    class="min-h-10 w-full min-w-0 rounded-sm border border-field bg-white px-3 text-[15px] text-ink"
                    @change="pick"
                >
                    <option value="">Choose one of the first listings…</option>
                    <option v-for="listing in listings" :key="listing.id ?? listing.label" :value="String(listing.id)">{{ listing.label }}</option>
                </select>
            </div>
            <div class="flex min-w-0 flex-col gap-1">
                <span id="moderation-examples-label" class="text-[13px] font-medium text-muted">Load an example</span>
                <div role="group" aria-labelledby="moderation-examples-label" class="flex flex-wrap gap-2">
                    <LabButton v-for="example in examples" :key="example.label" variant="secondary" @click="loadExample(example)">
                        {{ example.label.replaceAll('_', ' ') }}
                    </LabButton>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" @input="edited">
            <LabTextInput id="moderation-title" v-model="title" label="Title" />
            <LabTextInput id="moderation-price" v-model="priceEur" type="number" label="Price (EUR)" />
            <LabTextInput id="moderation-make" v-model="make" label="Make" />
            <LabTextInput id="moderation-model" v-model="model" label="Model" />
            <LabTextInput id="moderation-year" v-model="year" type="number" label="Year" />
            <LabTextInput id="moderation-mileage" v-model="mileageKm" type="number" label="Mileage (km)" />
            <LabTextInput id="moderation-city" v-model="city" label="City" />
        </div>

        <div class="flex min-w-0 flex-col gap-1" @input="edited">
            <label for="moderation-description" class="text-[13px] font-medium text-muted">Description</label>
            <textarea
                id="moderation-description"
                v-model="description"
                rows="6"
                class="w-full min-w-0 rounded-sm border border-field bg-white px-3 py-2 text-[15px] text-ink"
            />
            <p class="m-0 text-[13px]" :class="descriptionLength > limits.description ? 'text-danger' : 'text-muted'">
                {{ descriptionLength }} / {{ limits.description }} characters
            </p>
        </div>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <LabProviderSelect id="moderation-provider" v-model="provider" :providers="enabledTextProviders" :disabled="loading" />
            <LabButton type="submit" :disabled="!canSubmit">{{ loading ? 'Moderating…' : 'Moderate' }}</LabButton>
        </div>
    </form>

    <div aria-live="polite" class="mt-8 min-w-0">
        <section v-if="outcome" aria-labelledby="moderation-result-title" class="border-t border-rule pt-6">
            <h2 id="moderation-result-title" class="m-0 text-[18px]">Result</h2>

            <div v-if="outcome.kind === 'invalid-input'" role="alert" class="mt-3">
                <p class="m-0 text-[15px] font-medium text-danger">The listing was not accepted.</p>
                <ul class="m-0 mt-2 list-none p-0">
                    <li v-for="(messages, field) in outcome.errors" :key="field" class="text-[15px] text-danger">
                        <span class="font-mono text-[13px]">{{ field }}</span>: {{ messages.join(' ') }}
                    </li>
                </ul>
            </div>

            <p v-else-if="outcome.kind === 'failed'" role="alert" class="mt-3 text-[15px] font-medium text-danger">{{ outcome.message }}</p>

            <div v-else-if="result && result.status === 'invalid'" role="alert" class="mt-3">
                <p class="m-0 text-[15px] font-medium text-danger">
                    Nothing was classified: the model's answer failed the checks {{ result.attempts }} times.
                </p>
                <ul class="m-0 mt-2 list-none p-0">
                    <li v-for="(messages, field) in result.errors" :key="field" class="text-[15px] text-danger">
                        <span class="font-mono text-[13px]">{{ field }}</span>: {{ messages.join(' ') }}
                    </li>
                </ul>
            </div>

            <div v-if="classified" class="mt-3 min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="rounded-sm border px-3 py-1 text-[15px] font-semibold"
                        :class="classified.risky ? 'border-danger text-danger' : 'border-success text-success'"
                    >{{ classified.risky ? 'Risky' : 'Not risky' }}</span>
                    <span class="font-mono text-[15px] tabular-nums">risk {{ classified.risk }} · threshold {{ classified.threshold }}</span>
                </div>

                <div
                    role="meter"
                    aria-label="Risk against the threshold"
                    aria-valuemin="0"
                    aria-valuemax="1"
                    :aria-valuenow="classified.risk ?? 0"
                    :aria-valuetext="`Risk ${classified.risk}, threshold ${classified.threshold}`"
                    class="relative mt-3 h-4 w-full rounded-sm border border-ink"
                >
                    <div class="h-full" :class="classified.risky ? 'bg-danger' : 'bg-success'" :style="{ width: `${riskPercent}%` }" />
                    <div class="absolute -top-1 bottom-[-4px] w-0.5 bg-ink" :style="{ left: `${thresholdPercent}%` }" aria-hidden="true" />
                </div>
                <p class="mt-1 text-[13px] text-muted">The bar is the risk; the dark line is the threshold of {{ classified.model }}.</p>
                <p v-if="classified.floors.length > 0" class="mt-2 text-[15px]" data-testid="floor-notice">
                    A rule in the app raised the result: the model said risk {{ classified.model_risk }}, the app set at least 0.7 because of
                    {{ classified.floors.map((floor) => floor.replace('_', ' ')).join(' and ') }}.
                </p>
                <p v-else class="mt-2 text-[13px] text-muted">The model's own risk, {{ classified.model_risk }}, was used as given.</p>

                <h3 class="m-0 mt-5 text-[15px] font-semibold">Reasons</h3>
                <p v-if="classified.reasons.length === 0" class="mt-2 text-[13px] text-muted">The model gave no reason.</p>
                <dl v-else class="m-0 mt-2 grid grid-cols-1 gap-2 sm:grid-cols-[14rem_1fr]">
                    <template v-for="reason in classified.reasons" :key="reason">
                        <dt><LabTag>{{ reason }}</LabTag></dt>
                        <dd class="m-0 text-[13px] text-muted">{{ definitions[reason] ?? '' }}</dd>
                    </template>
                </dl>

                <h3 class="m-0 mt-5 text-[15px] font-semibold">Explanation</h3>
                <p class="mt-2 whitespace-pre-wrap break-words text-[15px]">{{ classified.explanation }}</p>
            </div>

            <section v-if="result" aria-labelledby="moderation-facts-title" class="mt-5 min-w-0">
                <h3 id="moderation-facts-title" class="m-0 text-[15px] font-semibold">Facts the app computed</h3>
                <dl class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Market median" :value="formatEur(result.facts.market_median_eur)" />
                    <LabMetric label="Price ratio" :value="result.facts.price_ratio" />
                    <LabMetric label="VINs in the text" :value="result.facts.vins_in_text.length > 0 ? result.facts.vins_in_text.join(', ') : 'none'" />
                    <LabMetric label="VIN reported" :value="result.facts.vin_reported ? 'yes' : 'no'" />
                    <LabMetric label="Injection pattern" :value="result.facts.injection_pattern ?? 'none'" />
                </dl>
                <p v-if="result.facts.market_median_eur === null" class="mt-2 text-[13px] text-muted">
                    Fewer than 5 comparable listings, so the price cannot be judged.
                </p>
            </section>

            <p v-if="answeringRunId" class="mt-5 text-[13px] text-muted">
                Logged run:
                <a :href="runUrl.replace(idPlaceholder, encodeURIComponent(answeringRunId))" class="break-all font-mono text-ink underline">{{ answeringRunId }}</a>
            </p>
        </section>
    </div>

    <Teleport to="#lab-rail" defer>
        <div class="flex flex-col gap-6">
            <section aria-labelledby="moderation-attempts-title">
                <h2 id="moderation-attempts-title" class="m-0 text-[18px]">Attempts</h2>
                <p class="mt-1 text-[13px] text-muted">A rejected answer is asked for once more, so at most 2.</p>
                <dl class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Attempts" :value="result?.attempts ?? null" />
                    <LabMetric label="Status" :value="result?.status ?? null" />
                    <LabMetric label="Provider" :value="result?.provider ?? null" />
                    <LabMetric label="Model" :value="result?.model ?? null" />
                    <LabMetric label="Prompt" :value="result?.prompt_version ?? null" />
                </dl>
            </section>

            <section v-if="run || runMessage" aria-labelledby="moderation-run-title">
                <h2 id="moderation-run-title" class="m-0 text-[18px]">Logged run</h2>
                <p v-if="runMessage" class="mt-2 text-[13px] text-muted">{{ runMessage }}</p>
                <dl v-if="run" class="m-0 mt-3 grid grid-cols-2 gap-4">
                    <LabMetric label="Provider" :value="run.provider" />
                    <LabMetric label="Model" :value="run.model" />
                    <LabMetric label="Tokens in" :value="run.tokens_in" />
                    <LabMetric label="Tokens out" :value="run.tokens_out" />
                    <LabMetric label="Latency" :value="run.latency_ms" unit="ms" />
                    <LabMetric label="Status" :value="run.status" />
                </dl>
            </section>
        </div>
    </Teleport>
</template>
