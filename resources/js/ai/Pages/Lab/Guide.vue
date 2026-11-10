<script lang="ts">
import LabLayout from '../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabCode from '../../components/LabCode.vue';
import LabExperiment from '../../components/LabExperiment.vue';
import LabFailureMode from '../../components/LabFailureMode.vue';
import LabMetric from '../../components/LabMetric.vue';
import LabRecipe from '../../components/LabRecipe.vue';
import LabTag from '../../components/LabTag.vue';

interface Snippet {
    file: string;
    startLine: number;
    endLine: number;
    code: string;
}

interface Step {
    title: string;
    html: string;
    event: string | null;
    snippet?: Snippet | null;
}

interface Item {
    title: string;
    html: string;
    snippet: Snippet | null;
}

interface Guide {
    key: string;
    title: string;
    summary: string;
    planned: boolean;
    tabs: Array<{ key: string; label: string }>;
    works: Step[];
    implementation: Step[];
    failures: Item[];
    recipes: Item[];
    support: Array<{ name: string; status: string; html: string }>;
    numbers: Array<{ label: string; unit: string | null; value: string }>;
    experiments: Array<{
        title: string;
        predictions: Array<{ id: string; text: string }>;
        answer: { id: string; html: string };
        href: string;
    }>;
    plan: string[];
    facts: Array<{ label: string; value: string }>;
    table: { columns: string[]; rows: Array<Record<string, string>> } | null;
    related: Array<{ key: string; title: string }>;
}

const props = defineProps<{
    guide: Guide;
}>();

function omitProvider(row: Record<string, string>): Record<string, string> {
    const { provider: _provider, ...rest } = row;

    return rest;
}

const active = ref(props.guide.tabs[0]?.key ?? 'works');
const tabButtons = ref<HTMLButtonElement[]>([]);
const tabKeys = computed(() => props.guide.tabs.map((tab) => tab.key));

function select(key: string, focus = false): void {
    active.value = key;
    window.history.replaceState(window.history.state, '', `#${key}`);

    if (focus) {
        nextTick(() => tabButtons.value[tabKeys.value.indexOf(key)]?.focus());
    }
}

function onKeydown(event: KeyboardEvent): void {
    const count = tabKeys.value.length;
    const current = tabKeys.value.indexOf(active.value);
    const target: Record<string, number> = {
        ArrowRight: (current + 1) % count,
        ArrowLeft: (current - 1 + count) % count,
        Home: 0,
        End: count - 1,
    };

    if (event.key in target) {
        event.preventDefault();
        select(tabKeys.value[target[event.key]], true);
    }
}

onMounted(() => {
    const fromHash = window.location.hash.slice(1);

    if (tabKeys.value.includes(fromHash)) {
        active.value = fromHash;
    }
});

const statusLabel: Record<string, string> = {
    logged: 'Logged',
    guarded: 'Guarded',
    runbook: 'Runbook',
};
</script>

<template>
    <h1 class="m-0 flex flex-wrap items-center gap-3 text-[28px] font-semibold">
        {{ guide.title }}
        <LabTag v-if="guide.planned">planned</LabTag>
    </h1>
    <p class="mt-2 text-[15px]">{{ guide.summary }}</p>

    <div role="tablist" aria-label="Guide sections" class="mt-6 flex flex-wrap gap-x-4 gap-y-1 border-b border-rule" @keydown="onKeydown">
        <button
            v-for="tab in guide.tabs"
            :id="`tab-${tab.key}`"
            :key="tab.key"
            ref="tabButtons"
            type="button"
            role="tab"
            :aria-selected="active === tab.key ? 'true' : 'false'"
            :aria-controls="`panel-${tab.key}`"
            :tabindex="active === tab.key ? 0 : -1"
            class="border-0 border-b-2 bg-transparent px-1 py-2 text-[15px]"
            :class="active === tab.key ? 'border-signal font-semibold text-signal' : 'border-transparent text-ink'"
            @click="select(tab.key)"
        >
            {{ tab.label }}
        </button>
    </div>

    <section
        v-for="tab in guide.tabs"
        v-show="active === tab.key"
        :id="`panel-${tab.key}`"
        :key="tab.key"
        role="tabpanel"
        :aria-labelledby="`tab-${tab.key}`"
        tabindex="0"
        class="mt-6 flex min-w-0 flex-col gap-5"
    >
        <template v-if="tab.key === 'works'">
            <dl v-if="guide.facts.length" class="m-0 flex flex-wrap gap-x-8 gap-y-3">
                <div v-for="fact in guide.facts" :key="fact.label" class="flex flex-col gap-1">
                    <dt class="text-[13px] text-muted">{{ fact.label }}</dt>
                    <dd class="m-0 font-mono text-[13px]">{{ fact.value }}</dd>
                </div>
            </dl>
            <div v-if="guide.table" class="overflow-x-auto">
                <table class="w-full border-collapse text-left text-[13px]">
                    <caption class="pb-2 text-left text-[13px] text-muted">What each provider can do here, and whether its key is set</caption>
                    <thead>
                        <tr>
                            <th v-for="column in guide.table.columns" :key="column" scope="col" class="border-b border-rule py-2 pr-4 font-semibold">{{ column }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in guide.table.rows" :key="row.provider">
                            <th scope="row" class="border-b border-rule py-2 pr-4 font-mono font-normal">{{ row.provider }}</th>
                            <td v-for="(value, column) in omitProvider(row)" :key="column" class="border-b border-rule py-2 pr-4">{{ value }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ol class="m-0 flex list-none flex-col gap-5 p-0">
                <li v-for="(step, index) in guide.works" :key="index" class="min-w-0 border-b border-rule pb-5">
                    <h3 class="m-0 text-[16px] font-semibold">{{ index + 1 }}. {{ step.title }}</h3>
                    <div class="lab-prose mt-2" v-html="step.html" />
                </li>
            </ol>
        </template>

        <template v-else-if="tab.key === 'implementation'">
            <article v-for="(step, index) in guide.implementation" :key="index" class="flex min-w-0 flex-col gap-3 border-b border-rule pb-5">
                <h3 class="m-0 text-[16px] font-semibold">{{ step.title }}</h3>
                <div class="lab-prose" v-html="step.html" />
                <LabCode v-if="step.snippet" v-bind="step.snippet" />
            </article>
        </template>

        <template v-else-if="tab.key === 'modify'">
            <LabRecipe v-for="(recipe, index) in guide.recipes" :key="index" v-bind="recipe" />
        </template>

        <template v-else-if="tab.key === 'support'">
            <ul v-if="guide.support.length" class="m-0 flex list-none flex-col gap-4 p-0">
                <li v-for="row in guide.support" :key="row.name" class="min-w-0">
                    <h3 class="m-0 flex flex-wrap items-center gap-2 text-[16px] font-semibold">
                        {{ row.name }}
                        <LabTag>{{ statusLabel[row.status] ?? row.status }}</LabTag>
                    </h3>
                    <div class="lab-prose mt-1" v-html="row.html" />
                </li>
            </ul>
            <template v-if="guide.failures.length">
                <h3 class="m-0 text-[18px] font-semibold">When it goes wrong</h3>
                <LabFailureMode v-for="(failure, index) in guide.failures" :key="index" v-bind="failure" />
            </template>
        </template>

        <template v-else-if="tab.key === 'numbers'">
            <dl class="m-0 flex flex-wrap gap-x-8 gap-y-4">
                <LabMetric v-for="number in guide.numbers" :key="number.label" :label="number.label" :value="number.value" :unit="number.unit ?? undefined" />
            </dl>
        </template>

        <template v-else-if="tab.key === 'experiments'">
            <LabExperiment v-for="(experiment, index) in guide.experiments" :key="index" v-bind="experiment" />
        </template>

        <template v-else-if="tab.key === 'plan'">
            <ul class="m-0 flex list-disc flex-col gap-3 pl-5">
                <li v-for="(bullet, index) in guide.plan" :key="index" class="lab-prose min-w-0" v-html="bullet" />
            </ul>
        </template>
    </section>

    <nav v-if="guide.related.length" aria-label="Related guides" class="mt-8 flex flex-wrap items-baseline gap-3 text-[15px]">
        <span class="text-muted">Related</span>
        <Link v-for="related in guide.related" :key="related.key" :href="`/ai/guides/${related.key}`" class="text-signal underline">
            {{ related.title }}
        </Link>
    </nav>
</template>
