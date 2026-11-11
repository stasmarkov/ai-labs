<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabHowItWorks from '../../components/LabHowItWorks.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSimilarityBar from '../../components/LabSimilarityBar.vue';
import LabTag from '../../components/LabTag.vue';
import LabTextInput from '../../components/LabTextInput.vue';
import { searchJson } from '../../composables/useSearchRequest';
import { presetQuery } from '../../composables/usePresetQuery';
import type { ServerTimings } from '../../composables/useSearchRequest';
import type { GuideRunState, GuideStep } from '../../composables/useGuideSteps';

interface PhotoMatch {
    photo_id: number;
    listing_id: number;
    listing_title: string;
    make: string;
    model: string;
    path: string;
    similarity: number;
}

const props = defineProps<{
    howItWorks: GuideStep[];
    guideHref: string;
    searchUrl: string;
    minQueryLength: number;
    maxQueryLength: number;
    promptTemplate: string;
    promptVersion: string;
    minScore: number;
    examples: string[];
    photoUrlPrefix: string;
    listingUrlTemplate: string;
    photoUrlTemplate: string;
    embeddingsProvider: { provider: string; model: string };
}>();

const query = ref(presetQuery());
const loading = ref(false);
const searched = ref(false);
const searchedQuery = ref('');
const matches = ref<PhotoMatch[]>([]);
const meta = ref<{ label: string | null; prompt: string | null; cached: boolean } | null>(null);
const timings = ref<ServerTimings | null>(null);
const elapsedMs = ref<number | null>(null);
const failure = ref<string | null>(null);
const panelEvents = ref<string[]>([]);
const panelState = ref<GuideRunState>('idle');

const queryLength = computed(() => [...query.value.trim()].length);
const canSearch = computed(() => !loading.value && queryLength.value >= props.minQueryLength && queryLength.value <= props.maxQueryLength);
const filledPrompt = computed(() => meta.value?.prompt ?? props.promptTemplate.replace('{query}', searchedQuery.value));

function photoUrl(match: PhotoMatch): string {
    return props.photoUrlPrefix + match.path;
}

function photoHref(match: PhotoMatch): string {
    return props.photoUrlTemplate.replace('PHOTO_ID', String(match.photo_id));
}

function listingHref(match: PhotoMatch): string {
    return props.listingUrlTemplate.replace('LISTING_ID', String(match.listing_id));
}

function title(match: PhotoMatch): string {
    return match.listing_title;
}

async function search(): Promise<void> {
    loading.value = true;
    failure.value = null;
    panelEvents.value = ['request_sent'];
    panelState.value = 'streaming';

    const text = query.value.trim();
    const outcome = await searchJson<PhotoMatch>(props.searchUrl, { q: text });

    loading.value = false;
    searched.value = true;
    searchedQuery.value = text;
    panelEvents.value = [...panelEvents.value, outcome.ok ? 'response_received' : outcome.refused ? 'refused' : 'request_failed'];
    panelState.value = outcome.ok || outcome.refused ? 'done' : 'error';

    if (outcome.ok) {
        matches.value = outcome.data;
        meta.value = {
            label: typeof outcome.meta?.label === 'string' ? outcome.meta.label : null,
            prompt: typeof outcome.meta?.prompt === 'string' ? outcome.meta.prompt : null,
            cached: outcome.meta?.cached === true,
        };
        timings.value = outcome.timings;
        elapsedMs.value = outcome.elapsedMs;

        return;
    }

    matches.value = [];
    meta.value = null;
    timings.value = null;
    elapsedMs.value = null;
    failure.value = outcome.message;
}

function useExample(example: string): void {
    query.value = example;
    void search();
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Photo search</h1>
    <p class="mt-2 text-[15px] text-muted">
        Turns your words into a vector with the text side of the photo encoder and returns the listing photos whose vectors are nearest.
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <LabTag caption="encoder">{{ embeddingsProvider.provider }} / {{ embeddingsProvider.model }}</LabTag>
        <LabTag caption="prompt">{{ promptVersion }}</LabTag>
        <LabTag caption="template">{{ promptTemplate }}</LabTag>
        <LabTag caption="cutoff">{{ minScore.toFixed(2) }}</LabTag>
    </div>

    <LabHowItWorks :steps="howItWorks" :guide-href="guideHref" :events="panelEvents" :state="panelState" />

    <form class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="search">
        <div class="min-w-0 flex-1">
            <LabTextInput id="photo-search-query" v-model="query" type="search" label="Query" placeholder="e.g. red sports car" />
        </div>
        <LabButton type="submit" :disabled="!canSearch">{{ loading ? 'Searching…' : 'Search' }}</LabButton>
    </form>
    <p v-if="queryLength > maxQueryLength" role="alert" class="mt-2 text-[13px] text-danger">
        The query is limited to {{ maxQueryLength }} characters.
    </p>

    <ul aria-label="Example queries" class="m-0 mt-3 flex list-none flex-wrap gap-2 p-0">
        <li v-for="example in examples" :key="example">
            <LabButton variant="secondary" :disabled="loading" @click="useExample(example)">{{ example }}</LabButton>
        </li>
    </ul>

    <p v-if="failure" role="alert" class="mt-6 text-[15px] font-medium text-danger">{{ failure }}</p>

    <section v-if="searched && !failure" aria-labelledby="photo-search-results-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="photo-search-results-title" class="m-0 text-[18px]">Results</h2>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <LabTag caption="sent to the encoder">{{ filledPrompt }}</LabTag>
            <LabTag v-if="meta?.label" caption="label">{{ meta.label }}</LabTag>
            <LabTag caption="cutoff">{{ minScore.toFixed(2) }}</LabTag>
            <LabTag caption="source">{{ meta?.cached ? 'from cache' : 'database' }}</LabTag>
        </div>
        <p v-if="matches.length === 0" class="mt-3 text-[15px] text-muted">
            No photos matched "{{ searchedQuery }}". Try other words, or lower the cutoff.
        </p>
        <ul v-else class="m-0 mt-3 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3">
            <li v-for="match in matches" :key="match.photo_id" class="min-w-0">
                <a :href="photoHref(match)" class="block rounded-sm border border-rule p-1">
                    <img :src="photoUrl(match)" :alt="title(match)" loading="lazy" class="aspect-[4/3] w-full rounded-sm bg-rule object-cover" />
                    <span class="mt-1 block truncate text-[13px]">{{ title(match) }}</span>
                    <LabSimilarityBar :value="match.similarity" />
                </a>
                <a :href="listingHref(match)" class="mt-1 block text-[13px] underline">Open listing</a>
            </li>
        </ul>
    </section>

    <Teleport to="#lab-rail" defer>
        <section aria-labelledby="photo-search-timings-title">
            <h2 id="photo-search-timings-title" class="m-0 text-[18px]">Timings</h2>
            <p class="mt-1 text-[13px] text-muted">From the <code>Server-Timing</code> header of the last search.</p>
            <dl class="m-0 mt-3 grid grid-cols-3 gap-4">
                <LabMetric label="Embedding" :value="timings?.embed ?? null" unit="ms" />
                <LabMetric label="Database" :value="timings?.db ?? null" unit="ms" />
                <LabMetric label="Round trip" :value="elapsedMs === null ? null : Math.round(elapsedMs)" unit="ms" />
            </dl>
        </section>
    </Teleport>
</template>
