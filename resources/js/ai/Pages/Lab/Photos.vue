<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabButton from '../../components/LabButton.vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSimilarityBar from '../../components/LabSimilarityBar.vue';
import LabTag from '../../components/LabTag.vue';
import type { GuideRunState, GuideStep } from '../../composables/useGuideSteps';

interface PhotoItem {
    id: number;
    url: string;
    title: string;
    status: string | null;
}

interface SimilarMatch {
    photo_id: number;
    listing_id: number;
    title: string;
    url: string;
    href: string;
    similarity: number;
    same_make_model: boolean;
}

interface SimilarStrip {
    label: string;
    reason: string | null;
    cached: boolean;
    min_score: number;
    near_duplicate_cutoff: number;
    matches: SimilarMatch[];
}

interface PhotoDetail extends PhotoItem {
    attempts: number;
    latency_ms: number | null;
    data: Record<string, unknown> | null;
    errors: Record<string, string[]> | null;
    run_href: string | null;
    others: Array<{ provider: string; model: string; prompt_version: string; status: string; latency_ms: number | null; data: Record<string, unknown> | null; run_href: string | null }>;
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    photos: PhotoItem[];
    pagination: { page: number; lastPage: number; total: number };
    selected: PhotoDetail | null;
    similar: SimilarStrip | null;
    extraction: { provider: string; model: string; promptVersion: string; queue: string };
    pageUrl: string;
    extractUrl: string;
    statusUrl: string;
    idPlaceholder: string;
}>();

const POLL_MS = 2000;
/** Failed status reads in a row before polling gives up; each retry waits twice as long as the one before. */
const MAX_POLL_FAILURES = 5;

const detail = ref<PhotoDetail | null>(props.selected);
const statuses = ref<Record<number, string | null>>({});
const message = ref<string | null>(null);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let pollFailures = 0;

watch(() => props.selected, (next) => {
    detail.value = next;
    message.value = null;
    schedule();
});

const inFlight = computed(() => detail.value?.status === 'queued' || detail.value?.status === 'running');
const json = computed(() => (detail.value?.data ? JSON.stringify(detail.value.data, null, 2) : null));
const errorList = computed(() => Object.entries(detail.value?.errors ?? {}).map(([field, lines]) => `${field}: ${lines.join(' ')}`));

const guideEvents = computed<string[]>(() => {
    switch (detail.value?.status) {
        case 'queued':
        case 'running':
            return ['request_sent'];
        case 'completed':
            return ['request_sent', 'response_received'];
        case 'failed':
            // A "transport" error never reached a usable answer; anything else is an answer that failed validation.
            return 'transport' in (detail.value?.errors ?? {}) || errorList.value.length === 0
                ? ['request_sent', 'request_failed']
                : ['request_sent', 'response_received', 'validation_failed'];
        default:
            return [];
    }
});

const guideState = computed<GuideRunState>(() => {
    switch (detail.value?.status) {
        case 'queued':
        case 'running':
            return 'streaming';
        case 'completed':
            return 'done';
        case 'failed':
            return 'error';
        default:
            return 'idle';
    }
});

function statusOf(photo: PhotoItem): string | null {
    return photo.id in statuses.value ? statuses.value[photo.id] : photo.status;
}

function statusLabel(status: string | null): string {
    return status ?? 'not extracted';
}

function hrefFor(photo: PhotoItem): string {
    return `${props.pageUrl}?${new URLSearchParams({ page: String(props.pagination.page), photo: String(photo.id) }).toString()}`;
}

function pageHref(page: number): string {
    return `${props.pageUrl}?${new URLSearchParams({ page: String(page) }).toString()}`;
}

function urlFor(template: string, id: number): string {
    return template.replace(props.idPlaceholder, String(id));
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function apply(next: PhotoDetail): void {
    statuses.value = { ...statuses.value, [next.id]: next.status };

    if (detail.value?.id === next.id) {
        detail.value = next;
    }
}

function stopPolling(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function schedule(): void {
    stopPolling();

    if (!inFlight.value || detail.value === null) {
        return;
    }

    const id = detail.value.id;
    timer = setTimeout(() => void poll(id), POLL_MS * 2 ** pollFailures);
}

/** A failed status read is retried with backoff; the message stays until a read succeeds. */
function retryLater(text: string): void {
    message.value = text;
    pollFailures += 1;

    if (pollFailures < MAX_POLL_FAILURES) {
        schedule();
    }
}

async function poll(id: number): Promise<void> {
    try {
        const response = await fetch(urlFor(props.statusUrl, id), { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            retryLater(`Could not read the status (server error ${response.status}); retrying.`);

            return;
        }

        apply((await response.json()) as PhotoDetail);
    } catch {
        retryLater('Could not read the status (connection lost); retrying.');

        return;
    }

    pollFailures = 0;
    schedule();
}

async function extract(): Promise<void> {
    if (detail.value === null || busy.value) {
        return;
    }

    busy.value = true;
    message.value = null;

    try {
        const response = await fetch(urlFor(props.extractUrl, detail.value.id), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });
        const body = (await response.json()) as PhotoDetail & { message?: string };

        if (response.status === 409) {
            apply(body);
            schedule();

            return;
        }

        if (!response.ok) {
            message.value = body.message ?? `Could not queue the extraction (server error ${response.status}).`;

            return;
        }

        apply(body);
        schedule();
    } catch {
        message.value = 'Could not queue the extraction (connection lost).';
    } finally {
        busy.value = false;
    }
}

schedule();

onBeforeUnmount(stopPolling);
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Photos</h1>
    <p class="mt-2 max-w-[65ch] text-[15px] text-muted">
        Car photos on listings. A vision model reads one photo and answers with a caption, tags and a few checked fields.
    </p>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="guideEvents" :state="guideState" />

    <section v-if="pagination.total === 0" aria-labelledby="photos-empty-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="photos-empty-title" class="m-0 text-[18px]">No photos yet</h2>
        <p class="mt-2 max-w-[65ch] text-[15px]">
            Photos are imported from a public dataset and matched to listings. Run these two commands, then reload:
        </p>
        <pre class="m-0 mt-2 max-w-[65ch] overflow-auto rounded-sm border border-rule p-3 font-mono text-[13px]">bench/datasets/stanford-cars.sh --accept-terms
ddev artisan photos:import</pre>
        <p class="mt-2 max-w-[65ch] text-[15px]">
            The first downloads the dataset after you accept its terms; <code class="font-mono">photos:import</code> keeps the matching classes and stores the photos. The
            <Link :href="guideHref" class="font-semibold text-ink underline">guide</Link> lists the steps.
        </p>
    </section>

    <div v-else class="mt-8 grid gap-8 border-t border-rule pt-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <section aria-labelledby="photos-grid-title" class="min-w-0">
            <h2 id="photos-grid-title" class="m-0 text-[18px]">Photos</h2>
            <p class="mt-1 text-[13px] text-muted">{{ pagination.total }} photos, page {{ pagination.page }} of {{ pagination.lastPage }}</p>
            <ul class="m-0 mt-3 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 xl:grid-cols-4">
                <li v-for="photo in photos" :key="photo.id" class="min-w-0">
                    <Link
                        :href="hrefFor(photo)"
                        preserve-scroll
                        :aria-current="detail?.id === photo.id ? 'true' : undefined"
                        class="block rounded-sm border p-1"
                        :class="detail?.id === photo.id ? 'border-ink' : 'border-rule'"
                    >
                        <img :src="photo.url" :alt="photo.title" loading="lazy" class="aspect-[4/3] w-full rounded-sm bg-rule object-cover" />
                        <span class="mt-1 block truncate text-[13px]">{{ photo.title }}</span>
                        <span
                            class="block text-[13px]"
                            :class="statusOf(photo) === 'failed' ? 'text-danger' : statusOf(photo) === 'completed' ? 'text-success' : 'text-muted'"
                        >{{ statusLabel(statusOf(photo)) }}</span>
                    </Link>
                </li>
            </ul>
            <nav v-if="pagination.lastPage > 1" aria-label="Photo pages" class="mt-4 flex items-center gap-4 text-[15px]">
                <Link v-if="pagination.page > 1" :href="pageHref(pagination.page - 1)" class="font-semibold text-ink underline">Previous</Link>
                <Link v-if="pagination.page < pagination.lastPage" :href="pageHref(pagination.page + 1)" class="font-semibold text-ink underline">Next</Link>
            </nav>
        </section>

        <section v-if="detail" aria-labelledby="photos-detail-title" class="min-w-0">
            <h2 id="photos-detail-title" class="m-0 text-[18px]">{{ detail.title }}</h2>
            <img :src="detail.url" :alt="detail.title" class="mt-3 w-full rounded-sm bg-rule" />

            <div v-if="similar" class="mt-6" aria-labelledby="photos-similar-title" role="region">
                <h3 id="photos-similar-title" class="m-0 text-[15px]">Similar photos</h3>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <LabTag caption="model">{{ similar.label }}</LabTag>
                    <LabTag caption="cutoffs">{{ similar.min_score.toFixed(2) }} to below {{ similar.near_duplicate_cutoff.toFixed(2) }}</LabTag>
                    <LabTag caption="source">{{ similar.cached ? 'cache' : 'database' }}</LabTag>
                </div>
                <p v-if="similar.matches.length === 0" class="mt-2 text-[15px] text-muted">No similar photos: {{ similar.reason }}.</p>
                <ul v-else class="m-0 mt-3 grid list-none grid-cols-2 gap-3 p-0">
                    <li v-for="match in similar.matches" :key="match.photo_id" class="min-w-0">
                        <Link :href="match.href" preserve-scroll class="block rounded-sm border border-rule p-1">
                            <img :src="match.url" :alt="match.title" loading="lazy" class="aspect-[4/3] w-full rounded-sm bg-rule object-cover" />
                            <span class="mt-1 block truncate text-[13px]">{{ match.title }}</span>
                            <LabSimilarityBar :value="match.similarity" />
                            <span class="block text-[13px]" :class="match.same_make_model ? 'text-success' : 'text-muted'">{{ match.same_make_model ? 'same make and model' : 'other make or model' }}</span>
                        </Link>
                    </li>
                </ul>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <LabTag caption="model">{{ extraction.model }}</LabTag>
                <LabTag caption="prompt">{{ extraction.promptVersion }}</LabTag>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <LabButton :disabled="busy || inFlight" @click="extract">
                    {{ detail.status === null ? 'Run extraction' : 'Run extraction again' }}
                </LabButton>
                <span aria-live="polite" class="text-[15px]" :class="detail.status === 'failed' ? 'text-danger' : 'text-muted'">{{ statusLabel(detail.status) }}</span>
            </div>
            <p v-if="inFlight" class="mt-2 text-[13px] text-muted">
                Needs a queue worker on the {{ extraction.queue }} queue.
            </p>
            <p v-if="message" role="alert" class="mt-2 text-[15px] font-medium text-danger">{{ message }}</p>

            <dl v-if="detail.status === 'completed' || detail.status === 'failed'" class="m-0 mt-4 grid grid-cols-2 gap-4">
                <LabMetric label="Attempts" :value="detail.attempts" />
                <LabMetric label="Latency" :value="detail.latency_ms" unit="ms" />
            </dl>

            <div v-if="json" class="mt-4">
                <h3 class="m-0 text-[15px]">Extracted JSON</h3>
                <pre class="m-0 mt-2 max-h-96 overflow-auto rounded-sm border border-rule p-3 font-mono text-[13px]">{{ json }}</pre>
            </div>

            <div v-if="errorList.length > 0" class="mt-4">
                <h3 class="m-0 text-[15px]">Why it failed</h3>
                <ul class="m-0 mt-2 list-disc pl-5 text-[15px] text-danger">
                    <li v-for="line in errorList" :key="line">{{ line }}</li>
                </ul>
            </div>

            <p v-if="detail.run_href" class="mt-4 text-[15px]">
                <a :href="detail.run_href" class="font-semibold text-ink underline">Logged run of the last attempt</a>
            </p>

            <div v-if="detail.others && detail.others.length > 0" class="mt-6">
                <h3 class="m-0 text-[15px]">Other results for this photo</h3>
                <p class="m-0 mt-1 text-[13px] text-muted">From another model or prompt version, kept side by side for comparison.</p>
                <details v-for="other in detail.others" :key="`${other.provider}/${other.model}/${other.prompt_version}`" class="mt-2 rounded-sm border border-rule px-3 py-2">
                    <summary class="cursor-pointer text-[14px]">
                        <span class="font-mono">{{ other.model }}</span> · {{ other.prompt_version }} · {{ other.status }}<span v-if="other.latency_ms !== null"> · {{ other.latency_ms }} ms</span>
                    </summary>
                    <pre v-if="other.data" class="m-0 mt-2 max-h-72 overflow-auto font-mono text-[13px]">{{ JSON.stringify(other.data, null, 2) }}</pre>
                    <a v-if="other.run_href" :href="other.run_href" class="mt-2 inline-block text-[13px] text-ink underline">Logged run</a>
                </details>
            </div>
        </section>
    </div>
</template>
