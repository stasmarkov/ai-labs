<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSimilarityBar from '../../components/LabSimilarityBar.vue';
import LabTextInput from '../../components/LabTextInput.vue';
import { searchJson } from '../../composables/useSearchRequest';
import type { ServerTimings } from '../../composables/useSearchRequest';

interface ListingMatch {
    id: number;
    year: number;
    make: string;
    model: string;
    body_type: string;
    price_cents: number;
    currency: string;
    city: string;
    description: string;
    similarity: number;
}

const props = defineProps<{
    searchUrl: string;
    maxQueryLength: number;
}>();

const query = ref('');
const loading = ref(false);
const searched = ref(false);
const matches = ref<ListingMatch[]>([]);
const timings = ref<ServerTimings | null>(null);
const elapsedMs = ref<number | null>(null);
const failure = ref<string | null>(null);

const canSearch = computed(() => !loading.value && query.value.trim() !== '' && query.value.length <= props.maxQueryLength);

function formatPrice(match: ListingMatch): string {
    return new Intl.NumberFormat('en', { style: 'currency', currency: match.currency, maximumFractionDigits: 0 }).format(match.price_cents / 100);
}

async function search(): Promise<void> {
    loading.value = true;
    failure.value = null;

    const outcome = await searchJson<ListingMatch>(props.searchUrl, { q: query.value.trim() });

    loading.value = false;
    searched.value = true;

    if (outcome.ok) {
        matches.value = outcome.data;
        timings.value = outcome.timings;
        elapsedMs.value = outcome.elapsedMs;

        return;
    }

    matches.value = [];
    timings.value = null;
    elapsedMs.value = null;
    failure.value = outcome.message;
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Semantic search</h1>
    <p class="mt-2 text-[15px] text-muted">
        Embeds your words and returns the ten listings whose descriptions are closest in meaning.
    </p>

    <form class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="search">
        <div class="min-w-0 flex-1">
            <LabTextInput id="semantic-query" v-model="query" type="search" label="Query" placeholder="e.g. roomy family car for long trips" />
        </div>
        <LabButton type="submit" :disabled="!canSearch">{{ loading ? 'Searching…' : 'Search' }}</LabButton>
    </form>
    <p v-if="query.length > maxQueryLength" role="alert" class="mt-2 text-[13px] text-danger">
        The query is limited to {{ maxQueryLength }} characters.
    </p>

    <p v-if="failure" role="alert" class="mt-6 text-[15px] font-medium text-danger">{{ failure }}</p>

    <section v-if="searched && !failure" aria-labelledby="semantic-results-title" class="mt-8 border-t border-rule pt-6">
        <h2 id="semantic-results-title" class="m-0 text-[18px]">Results</h2>
        <p v-if="matches.length === 0" class="mt-3 text-[15px] text-muted">No embedded listings matched.</p>
        <ol class="m-0 mt-3 flex list-none flex-col gap-5 p-0">
            <li v-for="match in matches" :key="match.id" class="min-w-0">
                <div class="flex flex-wrap items-baseline gap-x-3">
                    <span class="text-[15px] font-semibold">{{ match.year }} {{ match.make }} {{ match.model }}</span>
                    <span class="text-[13px] text-muted">{{ match.body_type }} · {{ match.city }} · {{ formatPrice(match) }}</span>
                </div>
                <LabSimilarityBar :value="match.similarity" class="mt-1" />
                <p class="mt-1 text-[13px] text-muted">{{ match.description }}</p>
            </li>
        </ol>
    </section>

    <Teleport to="#lab-rail" defer>
        <section aria-labelledby="semantic-timings-title">
            <h2 id="semantic-timings-title" class="m-0 text-[18px]">Timings</h2>
            <p class="mt-1 text-[13px] text-muted">From the <code>Server-Timing</code> header of the last search.</p>
            <dl class="m-0 mt-3 grid grid-cols-3 gap-4">
                <LabMetric label="Embedding" :value="timings?.embed ?? null" unit="ms" />
                <LabMetric label="Database" :value="timings?.db ?? null" unit="ms" />
                <LabMetric label="Round trip" :value="elapsedMs === null ? null : Math.round(elapsedMs)" unit="ms" />
            </dl>
        </section>
    </Teleport>
</template>
