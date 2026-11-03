<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, ref } from 'vue';
import LabButton from '../../components/LabButton.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabSimilarityBar from '../../components/LabSimilarityBar.vue';
import LabTag from '../../components/LabTag.vue';
import LabTextInput from '../../components/LabTextInput.vue';
import { searchJson } from '../../composables/useSearchRequest';
import type { ServerTimings } from '../../composables/useSearchRequest';

type Mode = 'vector' | 'text' | 'hybrid';

interface ChunkMatch {
    id: number;
    source: string;
    path: string;
    heading: string | null;
    snippet: string;
    score: number;
    vector_rank: number | null;
    vector_similarity: number | null;
    text_rank: number | null;
    text_score: number | null;
}

interface Column {
    mode: Mode;
    title: string;
    note: string;
    matches: ChunkMatch[];
    timings: ServerTimings | null;
    elapsedMs: number | null;
    failure: string | null;
    done: boolean;
}

const props = defineProps<{
    searchUrl: string;
    maxQueryLength: number;
    k: number;
    maxK: number;
}>();

const MODES: Array<Pick<Column, 'mode' | 'title' | 'note'>> = [
    { mode: 'vector', title: 'Vector', note: 'Closest by meaning (cosine similarity)' },
    { mode: 'text', title: 'Text', note: 'Matching words (PostgreSQL full-text rank)' },
    { mode: 'hybrid', title: 'Hybrid', note: 'Both lists fused by reciprocal rank' },
];

function emptyColumns(): Column[] {
    return MODES.map((mode) => ({ ...mode, matches: [], timings: null, elapsedMs: null, failure: null, done: false }));
}

const query = ref('');
const limit = ref(String(props.k));
const loading = ref(false);
const columns = ref<Column[]>(emptyColumns());
let searchToken = 0;

const limitValue = computed(() => Number(limit.value));
const limitValid = computed(() => Number.isInteger(limitValue.value) && limitValue.value >= 1 && limitValue.value <= props.maxK);
const canSearch = computed(() => !loading.value && limitValid.value && query.value.trim() !== '' && [...query.value.trim()].length <= props.maxQueryLength);
const searched = computed(() => columns.value.some((column) => column.done));

const sharedIds = computed(() => {
    const seen = new Map<number, number>();

    for (const column of columns.value) {
        for (const match of column.matches) {
            seen.set(match.id, (seen.get(match.id) ?? 0) + 1);
        }
    }

    return new Set([...seen].filter(([, count]) => count > 1).map(([id]) => id));
});

function formatScore(value: number | null): string {
    return value === null ? '—' : value.toFixed(4);
}

function ranks(match: ChunkMatch): string {
    return `vector #${match.vector_rank ?? '—'} · text #${match.text_rank ?? '—'}`;
}

async function runMode(column: Column, token: number): Promise<void> {
    const outcome = await searchJson<ChunkMatch>(props.searchUrl, {
        q: query.value.trim(),
        mode: column.mode,
        k: String(limitValue.value),
    });

    if (token !== searchToken) {
        return;
    }

    const index = columns.value.findIndex((candidate) => candidate.mode === column.mode);
    const next = columns.value[index];

    if (outcome.ok) {
        next.matches = outcome.data;
        next.timings = outcome.timings;
        next.elapsedMs = outcome.elapsedMs;
    } else {
        next.failure = outcome.message;
    }

    next.done = true;
}

async function search(): Promise<void> {
    const token = ++searchToken;
    loading.value = true;
    columns.value = emptyColumns();

    await Promise.all(columns.value.map((column) => runMode(column, token)));

    if (token === searchToken) {
        loading.value = false;
    }
}
</script>

<template>
    <h1 class="m-0 text-[28px] font-semibold">Docs search</h1>
    <p class="mt-2 text-[15px] text-muted">
        Runs one query in three modes at once. A chunk found by more than one mode is marked.
    </p>

    <form class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="search">
        <div class="min-w-0 flex-1">
            <LabTextInput id="docs-query" v-model="query" type="search" label="Query" placeholder="e.g. how do queues retry failed jobs" />
        </div>
        <div class="sm:w-28">
            <LabTextInput id="docs-k" v-model="limit" type="number" label="Results per mode" />
        </div>
        <LabButton type="submit" :disabled="!canSearch">{{ loading ? 'Searching…' : 'Search' }}</LabButton>
    </form>
    <p v-if="query.length > maxQueryLength" role="alert" class="mt-2 text-[13px] text-danger">
        The query is limited to {{ maxQueryLength }} characters.
    </p>
    <p v-if="!limitValid" role="alert" class="mt-2 text-[13px] text-danger">Results per mode must be 1 to {{ maxK }}.</p>

    <div v-if="searched || loading" class="mt-8 grid grid-cols-1 gap-6 border-t border-rule pt-6 xl:grid-cols-3">
        <section v-for="column in columns" :key="column.mode" :aria-labelledby="`docs-${column.mode}-title`" class="min-w-0">
            <h2 :id="`docs-${column.mode}-title`" class="m-0 text-[18px]">{{ column.title }}</h2>
            <p class="mt-1 text-[13px] text-muted">{{ column.note }}</p>
            <dl class="m-0 mt-3 grid grid-cols-3 gap-3">
                <LabMetric label="Embedding" :value="column.timings?.embed ?? null" unit="ms" />
                <LabMetric label="Database" :value="column.timings?.db ?? null" unit="ms" />
                <LabMetric label="Round trip" :value="column.elapsedMs === null ? null : Math.round(column.elapsedMs)" unit="ms" />
            </dl>

            <p v-if="!column.done" class="mt-4 text-[15px] text-muted">Searching…</p>
            <p v-else-if="column.failure" role="alert" class="mt-4 text-[15px] font-medium text-danger">{{ column.failure }}</p>
            <p v-else-if="column.matches.length === 0" class="mt-4 text-[15px] text-muted">No chunks matched.</p>

            <ol class="m-0 mt-4 flex list-none flex-col gap-3 p-0">
                <li
                    v-for="(match, index) in column.matches"
                    :key="match.id"
                    class="min-w-0 border-l-2 pl-3"
                    :class="sharedIds.has(match.id) ? 'border-signal' : 'border-rule'"
                    :data-shared="sharedIds.has(match.id)"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-[13px] text-muted">#{{ index + 1 }}</span>
                        <LabTag>{{ match.source }}</LabTag>
                        <span v-if="sharedIds.has(match.id)" class="text-[13px] font-semibold text-signal">In several lists</span>
                    </div>
                    <p class="m-0 mt-1 break-words font-mono text-[13px]">{{ match.path }}</p>
                    <p v-if="match.heading" class="m-0 text-[15px] font-semibold">{{ match.heading }}</p>
                    <LabSimilarityBar v-if="column.mode === 'vector' && match.vector_similarity !== null" :value="match.vector_similarity" class="mt-1" />
                    <p v-else class="m-0 mt-1 font-mono text-[13px] tabular-nums">score {{ formatScore(match.score) }}</p>
                    <p class="m-0 mt-1 text-[13px] text-muted">{{ ranks(match) }}</p>
                    <p class="m-0 mt-1 break-words text-[13px]">{{ match.snippet }}</p>
                </li>
            </ol>
        </section>
    </div>
</template>
