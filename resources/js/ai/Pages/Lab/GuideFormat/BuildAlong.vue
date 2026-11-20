<script lang="ts">
import LabLayout from '../../../Layouts/LabLayout.vue';

export default { layout: LabLayout };
</script>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import LabCode from '../../../components/LabCode.vue';

interface Snippet {
    file: string;
    startLine: number;
    endLine: number;
    code: string;
}

interface Step {
    id: string;
    title: string;
    outcomes: string[];
    goal: string;
    snippet: number;
    command: string;
    expected: string;
}

const props = defineProps<{
    guide: { key: string; title: string; href: string };
    format: { key: string; name: string; idea: string; time: string };
    items: Step[];
    snippets: Snippet[];
    compareHref: string;
}>();

const storageKey = `ai-lab.guide-format.${props.guide.key}.${props.format.key}`;
const done = ref<string[]>([]);
const loaded = ref(false);

const doneCount = computed(() => props.items.filter((step) => done.value.includes(step.id)).length);

onMounted(() => {
    try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey) ?? '[]');
        if (Array.isArray(stored)) {
            done.value = stored.filter((id): id is string => typeof id === 'string' && props.items.some((step) => step.id === id));
        }
    } catch {
        done.value = [];
    }
    loaded.value = true;
});

watch(done, (value) => {
    if (!loaded.value) {
        return;
    }
    try {
        window.localStorage.setItem(storageKey, JSON.stringify(value));
    } catch {
        // Progress is a convenience; a blocked store only means it is not kept.
    }
});

function reset(): void {
    done.value = [];
}
</script>

<template>
    <p class="m-0 text-[14px] text-muted">
        <Link href="/ai/learn" class="text-ink underline">Learn</Link>
        /
        <Link :href="compareHref" class="text-ink underline">Format trial</Link>
        /
        {{ format.name }}
    </p>
    <h1 class="mt-2 text-[28px] font-semibold">{{ format.name }}: {{ guide.title }}</h1>
    <p class="mt-2 text-[15px] text-muted">{{ format.idea }} Expected time (estimate): {{ format.time }}</p>

    <section aria-labelledby="build-progress" class="mt-6 flex flex-wrap items-center gap-3">
        <h2 id="build-progress" class="m-0 text-[18px]">Your progress</h2>
        <p class="m-0 text-[15px]" role="status" aria-live="polite">{{ doneCount }} of {{ items.length }} steps done</p>
        <button
            type="button"
            class="rounded-sm border border-rule px-3 py-1 text-[14px] text-ink hover:bg-[#f2f2f2] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            @click="reset"
        >
            Reset progress
        </button>
    </section>

    <ol class="mt-6 list-none space-y-6 p-0">
        <li v-for="(step, index) in items" :key="step.id" class="min-w-0 rounded-sm border border-rule p-4">
            <h2 class="m-0 text-[18px] font-semibold">Step {{ index + 1 }}. {{ step.title }}</h2>
            <p class="mb-0 mt-1 text-[13px] text-muted">Teaches {{ step.outcomes.join(', ') }}</p>
            <p class="mb-0 mt-3 text-[15px]"><span class="font-semibold">Goal.</span> {{ step.goal }}</p>
            <div class="mt-3">
                <LabCode v-bind="snippets[step.snippet]" />
            </div>
            <p class="mb-0 mt-3 text-[15px] font-semibold">Run</p>
            <pre class="m-0 mt-1 overflow-x-auto rounded-sm border border-rule p-3 font-mono text-[13px]" tabindex="0"><code>{{ step.command }}</code></pre>
            <p class="mb-0 mt-3 text-[15px]"><span class="font-semibold">Expected.</span> {{ step.expected }}</p>
            <label class="mt-3 flex items-center gap-2 text-[15px]">
                <input v-model="done" type="checkbox" :value="step.id" class="size-4" />
                Step {{ index + 1 }} done: {{ step.title }}
            </label>
        </li>
    </ol>
</template>
